<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/AuthPayloadCodec.php
 * Encode/decode helpers for auth-adjacent JSON column payloads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\TwoFactorMethodNormalizer;
use Raven\Lib\Security\TotpCipher;

/**
 * Shared codec for auth-adjacent JSON payload persistence.
 *
 * Handles contact-profile and 2FA-method column serialization, including TOTP secret
 * encryption on writes and decryption on reads. Contact-profile normalization is
 * handled internally; callers no longer inject a separate normalizer.
 */
final class AuthPayloadCodec
{
    private TotpCipher $totpSecretCipher;

    /**
     * Prepares the codec with an optional TOTP cipher for secret encryption at rest.
     *
     * @param TotpCipher|null $totpSecretCipher Cipher for encrypting TOTP secrets; defaults to a new instance.
     */
    public function __construct(?TotpCipher $totpSecretCipher = null)
    {
        $this->totpSecretCipher = $totpSecretCipher ?? new TotpCipher();
    }

    /**
     * Decodes a raw JSON contact-profiles column value into a typed array.
     *
     * Returns an empty array on any decode or normalization error.
     *
     * @param mixed $raw Raw column value from the database.
     * @return array<int, array{type: string, value: string}>
     */
    public function decodeContactProfiles(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeContactProfiles($decoded);
    }

    /**
     * Encodes a normalized contact-profiles array to a JSON string for persistence.
     *
     * Returns null when the array is empty so callers can store a NULL column cleanly.
     *
     * @param array<int, array{type: string, value: string}> $profiles Normalized profiles array.
     * @return string|null JSON string, or null when profiles is empty.
     */
    public function encodeContactProfiles(array $profiles): ?string
    {
        if ($profiles === []) {
            return null;
        }

        try {
            return json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalizes and deduplicates a raw contact-profiles array.
     *
     * Cleans type slugs (lowercase, hyphens only), trims values, truncates long entries,
     * removes duplicates, and sorts by type then value for stable ordering.
     *
     * @param array<int, mixed> $profiles Raw profile entries from form input or DB decode.
     * @return array<int, array{type: string, value: string}>
     */
    public function normalizeContactProfiles(array $profiles): array
    {
        return $this->normalizeContactProfileItems($profiles, 20);
    }

    /**
     * Decodes a raw JSON 2FA-methods column value into a typed array.
     *
     * Decrypts TOTP secrets and normalizes method structure on decode.
     * Returns an empty array on any decode or normalization error.
     *
     * @param mixed $raw Raw column value from the database.
     * @return array<int, array<string, mixed>>
     */
    public function decodeTwoFactorMethods(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return TwoFactorMethodNormalizer::normalizeStored($this->decryptTotpSecrets($decoded));
    }

    /**
     * Encodes a normalized 2FA-methods array to a JSON string for persistence.
     *
     * Encrypts TOTP secrets before encoding. Returns null when the array is empty.
     *
     * @param array<int, array<string, mixed>> $methods Normalized 2FA method rows.
     * @return string|null JSON string, or null when methods is empty.
     */
    public function encodeTwoFactorMethods(array $methods): ?string
    {
        if ($methods === []) {
            return null;
        }

        try {
            return json_encode($this->encryptTotpSecrets($methods), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalizes and deduplicates a raw contact-profiles list, enforcing the item cap.
     *
     * Internal entry point used by normalizeContactProfiles and decodeContactProfiles.
     *
     * @param array<int, mixed> $profiles Raw profile entries.
     * @param int $maxItems  Maximum number of profiles to keep; extra entries are dropped.
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeContactProfileItems(array $profiles, int $maxItems = 20): array
    {
        $maxItems = max(1, $maxItems);
        $normalized = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $type = strtolower(trim((string) ($profile['type'] ?? '')));
            $value = trim((string) ($profile['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            // Normalize type slug: lowercase, hyphens only, no leading/trailing hyphens.
            $type = preg_replace('/[^a-z0-9-]+/', '-', $type) ?? '';
            $type = trim($type, '-');
            $type = preg_replace('/-+/', '-', $type) ?? '';
            if ($type === '') {
                continue;
            }

            if (mb_strlen($type) > 80) {
                $type = mb_substr($type, 0, 80);
            }
            if (mb_strlen($value) > 255) {
                $value = mb_substr($value, 0, 255);
            }
            if ($value === '') {
                continue;
            }

            // Deduplicate by case-insensitive type+value composite key.
            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type' => $type,
                'value' => $value,
            ];

            if (count($normalized) >= $maxItems) {
                break;
            }
        }

        $result = array_values($normalized);
        usort(
            $result,
            static function (array $left, array $right): int {
                $leftType = strtolower(trim((string) ($left['type'] ?? '')));
                $rightType = strtolower(trim((string) ($right['type'] ?? '')));
                if ($leftType !== $rightType) {
                    return $leftType <=> $rightType;
                }

                $leftValue = strtolower(trim((string) ($left['value'] ?? '')));
                $rightValue = strtolower(trim((string) ($right['value'] ?? '')));
                return $leftValue <=> $rightValue;
            }
        );

        return $result;
    }

    /**
     * Encrypts plaintext TOTP secrets in a method list before persistence.
     *
     * Skips secrets that are already encrypted or empty to make this safe to call
     * on both fresh and previously persisted method arrays.
     *
     * @param array<int, array<string, mixed>> $methods 2FA method rows.
     * @return array<int, array<string, mixed>> Method rows with TOTP secrets encrypted.
     */
    private function encryptTotpSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '') {
                continue;
            }

            if ($this->totpSecretCipher->isEncrypted($secret)) {
                continue;
            }

            $encrypted = $this->totpSecretCipher->encryptSecret($secret);
            if (!is_string($encrypted) || $encrypted === '') {
                continue;
            }

            $method['secret'] = $encrypted;
            $methods[$index] = $method;
        }

        return $methods;
    }

    /**
     * Decrypts encrypted TOTP secrets in a method list after reading from DB.
     *
     * Sets secret to empty string when decryption fails to ensure downstream
     * TOTP validation rejects invalid credentials safely.
     *
     * @param array<int, mixed> $methods Raw decoded method rows from JSON.
     * @return array<int, mixed> Method rows with TOTP secrets decrypted.
     */
    private function decryptTotpSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '') {
                continue;
            }

            $decrypted = $this->totpSecretCipher->decryptSecret($secret);
            $method['secret'] = is_string($decrypted) ? $decrypted : '';
            $methods[$index] = $method;
        }

        return $methods;
    }
}
