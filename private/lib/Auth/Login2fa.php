<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Login2fa.php
 * Consolidated 2FA key, rule, and method-normalization primitives for auth and panel flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\PhraseGenerate;
use Raven\Lib\Security\PhraseValidate;
use Raven\Lib\Security\Totp;

/**
 * Shared 2FA key/rules/normalization helpers used by panel and auth flows.
 */
final class Login2fa
{
    private const RECOVERY_POOL_KEY = 'recovery:pool';
    private const EMAIL_POOL_KEY = 'email:pool';
    private const MAX_METHODS = 20;
    /** @var array<int, string> */
    private const TYPES = ['totp', 'recovery', 'webauthn', 'email'];
    /** @var array<int, string> */
    private const STATUSES = ['pending', 'confirmed', 'stub'];

    /**
     * Returns one canonical method key for a TOTP secret.
     *
     * @param string $secret Raw or normalized TOTP secret.
     * @return string Stable key for challenge method matching.
     */
    public static function forTotpSecret(string $secret): string
    {
        return 'totp:' . sha1(Totp::normalizeSecret($secret));
    }

    /**
     * Returns one canonical method key for a recovery phrase.
     *
     * @param string $phrase Recovery phrase.
     * @return string Stable key for challenge method matching.
     */
    public static function forRecoveryPhrase(string $phrase): string
    {
        return 'recovery:' . sha1(PhraseValidate::normalize($phrase));
    }

    /**
     * Returns one canonical method key for a stored recovery hash.
     *
     * @param string $recoveryHash Stored recovery hash.
     * @return string Stable key for challenge method matching.
     */
    public static function forRecoveryHash(string $recoveryHash): string
    {
        return 'recovery:' . sha1(trim($recoveryHash));
    }

    /**
     * Returns one canonical method key for a WebAuthn credential id.
     *
     * @param string $credentialId Base64 credential id.
     * @return string Stable key for challenge method matching.
     */
    public static function forWebauthnCredentialId(string $credentialId): string
    {
        return 'webauthn:' . trim($credentialId);
    }

    /**
     * Returns one canonical method key for an email address.
     *
     * @param string $email Email address.
     * @return string Stable key for challenge method matching.
     */
    public static function forEmailAddress(string $email): string
    {
        return 'email:' . sha1(strtolower(trim($email)));
    }

    /**
     * Returns the special recovery-method pool key for shared recovery-code challenges.
     *
     * @return string Recovery pool key.
     */
    public static function recoveryPool(): string
    {
        return self::RECOVERY_POOL_KEY;
    }

    /**
     * Returns the special email-method pool key for shared email-code challenges.
     *
     * @return string Email pool key.
     */
    public static function emailPool(): string
    {
        return self::EMAIL_POOL_KEY;
    }

    /**
     * Returns whether the provided method key targets the recovery pool.
     *
     * @param string $methodKey Candidate method key.
     * @return bool True when the key targets recovery pool handling.
     */
    public static function isRecoveryPool(string $methodKey): bool
    {
        return trim(strtolower($methodKey)) === self::RECOVERY_POOL_KEY;
    }

    /**
     * Returns whether the provided method key targets the email pool.
     *
     * @param string $methodKey Candidate method key.
     * @return bool True when the key targets email pool handling.
     */
    public static function isEmailPool(string $methodKey): bool
    {
        return trim(strtolower($methodKey)) === self::EMAIL_POOL_KEY;
    }

    /**
     * Extracts one WebAuthn credential id from a method key.
     *
     * @param string $methodKey Candidate method key.
     * @return string Credential id when key is WebAuthn, otherwise empty string.
     */
    public static function extractWebauthnCredentialId(string $methodKey): string
    {
        $methodKey = trim($methodKey);
        if (!str_starts_with($methodKey, 'webauthn:')) {
            return '';
        }

        return trim(substr($methodKey, strlen('webauthn:')));
    }

    /**
     * Normalizes one method type.
     *
     * @param string $type Candidate type value.
     * @return string Lower-cased type slug.
     */
    public static function normalizeType(string $type): string
    {
        return strtolower(trim($type));
    }

    /**
     * Returns whether the method type is known by Raven.
     *
     * @param string $type Candidate type value.
     * @return bool True when type is known.
     */
    public static function isKnownType(string $type): bool
    {
        return in_array(self::normalizeType($type), self::TYPES, true);
    }

    /**
     * Returns the default human-facing label for one method type.
     *
     * @param string $type Method type value.
     * @return string Default label.
     */
    public static function defaultLabelForType(string $type): string
    {
        return match (self::normalizeType($type)) {
            'totp' => 'Authenticator App',
            'recovery' => 'Recovery Phrase',
            'webauthn' => 'Security Key',
            default => 'Email Code',
        };
    }

    /**
     * Normalizes one method label with fallback and length constraints.
     *
     * @param string $label Candidate label.
     * @param string $type Method type.
     * @param int $maxLength Maximum label length.
     * @return string Normalized method label.
     */
    public static function normalizeLabel(string $label, string $type, int $maxLength = 80): string
    {
        $normalized = trim($label);
        if ($normalized === '') {
            $normalized = self::defaultLabelForType($type);
        }

        if (mb_strlen($normalized) > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    /**
     * Normalizes one method status with type-aware fallback.
     *
     * @param string $status Candidate status.
     * @param string $type Method type.
     * @return string Normalized status.
     */
    public static function normalizeStatus(string $status, string $type): string
    {
        $normalizedStatus = strtolower(trim($status));
        if (in_array($normalizedStatus, self::STATUSES, true)) {
            return $normalizedStatus;
        }

        $normalizedType = self::normalizeType($type);
        if ($normalizedType === 'totp') {
            return 'pending';
        }
        if (in_array($normalizedType, ['recovery', 'email'], true)) {
            return 'confirmed';
        }

        return 'stub';
    }

    /**
     * Returns one human-facing status label.
     *
     * @param string $status Normalized or raw status value.
     * @return string Human-facing status label.
     */
    public static function statusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'confirmed' => 'Confirmed',
            'pending' => 'Pending',
            default => 'Stub',
        };
    }

    /**
     * Builds a dedupe key for normalized method rows.
     *
     * @param string $type Method type.
     * @param string $label Method label.
     * @param string $value Type-specific payload value.
     * @return string Stable dedupe key.
     */
    public static function dedupeKey(string $type, string $label, string $value): string
    {
        return strtolower(self::normalizeType($type) . "\n" . trim($label) . "\n" . trim($value));
    }

    /**
     * Normalizes stored/user-preference 2FA methods.
     *
     * @param array<int, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeStored(array $methods): array
    {
        $normalized = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = self::normalizeType((string) ($method['type'] ?? ''));
            if (!self::isKnownType($type)) {
                continue;
            }

            $label = self::normalizeLabel((string) ($method['label'] ?? ''), $type);
            $status = self::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $row = [
                'type' => $type,
                'label' => $label,
                'status' => $status,
                'added_at' => self::normalizeAddedAt($method['added_at'] ?? null),
            ];

            if ($type === 'totp') {
                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!Totp::isValidSecret($secret)) {
                    continue;
                }
                $row['secret'] = $secret;
            } elseif ($type === 'recovery') {
                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                if (!PhraseValidate::isValidHash($recoveryHash)) {
                    $recoveryCode = PhraseValidate::normalize((string) ($method['recovery_code'] ?? ''));
                    if (PhraseValidate::isValid($recoveryCode, 12)) {
                        $hashedRecoveryCode = PhraseGenerate::hash($recoveryCode, 12);
                        $recoveryHash = is_string($hashedRecoveryCode) ? $hashedRecoveryCode : '';
                    }
                }

                if (!PhraseValidate::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = self::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = (bool) ($method['reusable'] ?? false);
            } elseif ($type === 'webauthn') {
                $credentialId = trim((string) ($method['credential_id'] ?? ''));
                if ($credentialId !== '') {
                    if (mb_strlen($credentialId) > 512) {
                        $credentialId = mb_substr($credentialId, 0, 512);
                    }
                    $row['credential_id'] = $credentialId;
                }

                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                if ($credentialPublicKey !== '') {
                    if (mb_strlen($credentialPublicKey) > 4096) {
                        $credentialPublicKey = mb_substr($credentialPublicKey, 0, 4096);
                    }
                    $row['credential_public_key'] = $credentialPublicKey;
                }

                $signatureCounter = (int) ($method['signature_counter'] ?? 0);
                if ($signatureCounter < 0) {
                    $signatureCounter = 0;
                }
                $row['signature_counter'] = $signatureCounter;
                $row['status'] = (($row['credential_id'] ?? '') !== '' && ($row['credential_public_key'] ?? '') !== '')
                    ? 'confirmed'
                    : 'stub';
                $row['require_uv'] = (bool) ($method['require_uv'] ?? false);
            } else {
                $email = self::sanitizeEmail((string) ($method['email'] ?? ''));
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $dedupeValue = (string) ($row['secret'] ?? $row['recovery_hash'] ?? $row['credential_id'] ?? $row['email'] ?? '');
            $dedupeLabel = trim((string) ($row['label'] ?? $label));
            $dedupeKey = self::dedupeKey($type, $dedupeLabel, $dedupeValue);
            $normalized[$dedupeKey] = $row;
            if (count($normalized) >= self::MAX_METHODS) {
                break;
            }
        }

        return array_values($normalized);
    }

    private static function normalizeAddedAt(mixed $value): string
    {
        $addedAt = trim((string) $value);
        if ($addedAt === '') {
            return gmdate('Y-m-d H:i:s');
        }

        return $addedAt;
    }

    private static function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    private static function sanitizeEmail(string $value): ?string
    {
        $value = strtolower(self::sanitizeText($value, 254));
        if ($value === '') {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
    }
}
