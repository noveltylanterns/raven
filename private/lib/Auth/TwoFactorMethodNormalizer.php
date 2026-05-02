<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\View\Qr;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\Totp;

/**
 * Shared 2FA method normalization helpers used by panel and auth flows.
 */
final class TwoFactorMethodNormalizer
{
    private const MAX_METHODS = 20;

    /**
     * Normalizes submitted panel 2FA method rows.
     *
     * @param array<int, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeSubmitted(array $methods, string $fallbackEmail, string $totpIssuer): array
    {
        $normalized = [];
        $numberedLabelState = self::initializeNumberedLabelState($methods);
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            if ($type === '' || $type === 'none' || !TwoFactorMethodRules::isKnownType($type)) {
                continue;
            }

            $rawLabel = self::sanitizeText((string) ($method['label'] ?? ''), 80);
            $label = in_array($type, ['webauthn', 'email'], true)
                ? self::resolveSubmittedNumberedLabel($rawLabel, $type, $numberedLabelState)
                : TwoFactorMethodRules::normalizeLabel($rawLabel, $type);

            $row = [
                'type' => $type,
                'label' => $label,
                'status' => TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type),
                'added_at' => self::normalizeAddedAt($method['added_at'] ?? null),
            ];

            if ($type === 'totp') {
                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                if ($secret === '') {
                    $generated = Totp::generateSecret($totpIssuer);
                    $secret = is_string($generated) ? $generated : '';
                }

                if (!Totp::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                if ($row['status'] !== 'confirmed') {
                    $row['status'] = 'pending';
                }
                $verificationCode = Totp::normalizeCode((string) ($method['verification_code'] ?? ''));
                if ($verificationCode !== '' && Totp::verifyCode($secret, $verificationCode, 1, $totpIssuer)) {
                    $row['status'] = 'confirmed';
                }
            } elseif ($type === 'recovery') {
                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                if (RecoveryPhrase::isValid($recoveryCode, 12)) {
                    $generatedHash = RecoveryPhrase::hash($recoveryCode, 12);
                    $recoveryHash = is_string($generatedHash) ? $generatedHash : '';
                }

                if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = isset($method['reusable']) && (string) ($method['reusable'] ?? '') === '1';
            } elseif ($type === 'webauthn') {
                $credentialId = self::sanitizeText((string) ($method['credential_id'] ?? ''), 512);
                if ($credentialId !== '') {
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
                $row['require_uv'] = isset($method['require_uv']) && (string) ($method['require_uv'] ?? '') === '1';
            } else {
                $email = self::sanitizeEmail((string) ($method['target_email'] ?? ''));
                if ($email === null) {
                    $email = self::sanitizeEmail($fallbackEmail);
                }
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $dedupeValue = (string) ($row['secret'] ?? $row['recovery_hash'] ?? $row['credential_id'] ?? $row['email'] ?? '');
            $dedupeLabel = trim((string) ($row['label'] ?? $label));
            $dedupeKey = TwoFactorMethodRules::dedupeKey($type, $dedupeLabel, $dedupeValue);
            $normalized[$dedupeKey] = $row;
            if (count($normalized) >= self::MAX_METHODS) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, mixed> $methods
     * @return array{webauthn: array<int, bool>, email: array<int, bool>}
     */
    private static function initializeNumberedLabelState(array $methods): array
    {
        $state = [
            'webauthn' => [],
            'email' => [],
        ];

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            if (!array_key_exists($type, $state)) {
                continue;
            }

            $label = self::sanitizeText((string) ($method['label'] ?? ''), 80);
            if ($label === '') {
                continue;
            }

            $base = self::numberedLabelBase($type);
            if (preg_match('/^' . preg_quote($base, '/') . '\s+([1-9][0-9]*)$/i', $label, $matches) !== 1) {
                continue;
            }

            $state[$type][(int) ($matches[1] ?? 0)] = true;
        }

        return $state;
    }

    /**
     * @param array{webauthn: array<int, bool>, email: array<int, bool>} $state
     */
    private static function resolveSubmittedNumberedLabel(string $rawLabel, string $type, array &$state): string
    {
        $type = TwoFactorMethodRules::normalizeType($type);
        if (!array_key_exists($type, $state)) {
            return TwoFactorMethodRules::normalizeLabel($rawLabel, $type);
        }

        $label = self::sanitizeText($rawLabel, 80);
        $base = self::numberedLabelBase($type);
        if (preg_match('/^' . preg_quote($base, '/') . '\s+([1-9][0-9]*)$/i', $label, $matches) === 1) {
            $number = (int) ($matches[1] ?? 0);
            if ($number > 0) {
                $state[$type][$number] = true;
                return $base . ' ' . $number;
            }
        }

        $normalizedLabel = strtolower(trim($label));
        $autoLabelCandidates = self::numberedLabelLegacyDefaults($type);
        $shouldAuto = $normalizedLabel === '';
        if (!$shouldAuto) {
            foreach ($autoLabelCandidates as $candidate) {
                if ($normalizedLabel === strtolower(trim($candidate))) {
                    $shouldAuto = true;
                    break;
                }
            }
        }

        if (!$shouldAuto) {
            return TwoFactorMethodRules::normalizeLabel($label, $type);
        }

        $nextNumber = 1;
        while (isset($state[$type][$nextNumber])) {
            $nextNumber += 1;
        }

        $state[$type][$nextNumber] = true;
        return $base . ' ' . $nextNumber;
    }

    private static function numberedLabelBase(string $type): string
    {
        return match (TwoFactorMethodRules::normalizeType($type)) {
            'webauthn' => 'My Key',
            'email' => 'My Email',
            default => TwoFactorMethodRules::defaultLabelForType($type),
        };
    }

    /**
     * @return array<int, string>
     */
    private static function numberedLabelLegacyDefaults(string $type): array
    {
        $base = self::numberedLabelBase($type);
        return [
            $base,
            TwoFactorMethodRules::defaultLabelForType($type),
        ];
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

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            if (!TwoFactorMethodRules::isKnownType($type)) {
                continue;
            }

            $label = TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), $type);
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
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
                if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                    $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                    if (RecoveryPhrase::isValid($recoveryCode, 12)) {
                        $hashedRecoveryCode = RecoveryPhrase::hash($recoveryCode, 12);
                        $recoveryHash = is_string($hashedRecoveryCode) ? $hashedRecoveryCode : '';
                    }
                }

                if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
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
            $dedupeKey = TwoFactorMethodRules::dedupeKey($type, $dedupeLabel, $dedupeValue);
            $normalized[$dedupeKey] = $row;
            if (count($normalized) >= self::MAX_METHODS) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalizes 2FA methods for panel view rendering.
     *
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function prepareForView(array $methods, string $fallbackEmail, string $totpIssuer): array
    {
        $prepared = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            if (!TwoFactorMethodRules::isKnownType($type)) {
                continue;
            }

            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $label = TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), $type);
            $row = [
                'type' => $type,
                'label' => $label,
                'status' => $status,
            ];

            if ($type === 'totp') {
                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!Totp::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                $provisioningUri = Totp::provisioningUri($totpIssuer, $fallbackEmail, $secret);
                if ($provisioningUri !== '') {
                    $row['provisioning_uri'] = $provisioningUri;
                    $qrDataUri = Qr::dataUriSvgBase64($provisioningUri, 220);
                    if ($qrDataUri !== '') {
                        $row['qr_data_uri'] = $qrDataUri;
                    }
                }
            } elseif ($type === 'recovery') {
                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = (bool) ($method['reusable'] ?? false);
            } elseif ($type === 'webauthn') {
                $credentialId = trim((string) ($method['credential_id'] ?? ''));
                if ($credentialId !== '') {
                    $row['credential_id'] = $credentialId;
                }

                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                if ($credentialPublicKey !== '') {
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
                    $email = self::sanitizeEmail($fallbackEmail);
                }
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $row['status_label'] = TwoFactorMethodRules::statusLabel((string) $row['status']);
            $prepared[] = $row;
            if (count($prepared) >= self::MAX_METHODS) {
                break;
            }
        }

        usort($prepared, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) ($left['label'] ?? '')));
            $rightLabel = strtolower(trim((string) ($right['label'] ?? '')));
            if ($leftLabel === '' || $rightLabel === '') {
                $leftFallback = strtolower(TwoFactorMethodRules::defaultLabelForType((string) ($left['type'] ?? '')));
                $rightFallback = strtolower(TwoFactorMethodRules::defaultLabelForType((string) ($right['type'] ?? '')));
                if ($leftLabel === '') {
                    $leftLabel = $leftFallback;
                }
                if ($rightLabel === '') {
                    $rightLabel = $rightFallback;
                }
            }

            if ($leftLabel !== $rightLabel) {
                return $leftLabel <=> $rightLabel;
            }

            return strtolower((string) ($left['type'] ?? '')) <=> strtolower((string) ($right['type'] ?? ''));
        });

        return $prepared;
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
