<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/AuthPayloadCodec.php
 * Encode/decode helpers for auth-adjacent JSON column payloads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Format\Json;
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
    private const MAX_CONTACT_PROFILES = 20;

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

        $decoded = Json::decodeAssocString($raw, 32);
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

        return Json::encode($profiles, JSON_UNESCAPED_SLASHES);
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

            if (count($normalized) >= self::MAX_CONTACT_PROFILES) {
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

        $decoded = Json::decodeAssocString($raw, 64);
        if (!is_array($decoded)) {
            return [];
        }

        return Login2fa::normalizeStored($this->totpSecretCipher->decryptMethodSecrets($decoded));
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

        return Json::encode($this->totpSecretCipher->encryptMethodSecrets($methods), JSON_UNESCAPED_SLASHES);
    }

}
