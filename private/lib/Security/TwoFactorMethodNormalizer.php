<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

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
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            if ($type === '' || $type === 'none' || !TwoFactorMethodRules::isKnownType($type)) {
                continue;
            }

            $label = TwoFactorMethodRules::normalizeLabel(
                self::sanitizeText((string) ($method['label'] ?? ''), 80),
                $type
            );

            $row = [
                'type' => $type,
                'label' => $label,
                'status' => TwoFactorMethodRules::normalizeStatus('', $type),
                'added_at' => self::normalizeAddedAt($method['added_at'] ?? null),
            ];

            if ($type === 'totp') {
                $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
                if ($secret === '') {
                    $generated = TotpService::generateSecret($totpIssuer);
                    $secret = is_string($generated) ? $generated : '';
                }

                if (!TotpService::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                $verificationCode = TotpService::normalizeCode((string) ($method['verification_code'] ?? ''));
                if ($verificationCode !== '' && TotpService::verifyCode($secret, $verificationCode, 1, $totpIssuer)) {
                    $row['status'] = 'confirmed';
                }
            } elseif ($type === 'recovery') {
                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    $generatedRecoveryCode = RecoveryPhrase::generate(12);
                    $recoveryCode = is_string($generatedRecoveryCode) ? $generatedRecoveryCode : '';
                }

                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_code'] = $recoveryCode;
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
                if ($email !== null) {
                    $row['email'] = $email;
                }
            }

            $dedupeValue = (string) ($row['secret'] ?? $row['recovery_code'] ?? $row['credential_id'] ?? $row['email'] ?? '');
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
                $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!TotpService::isValidSecret($secret)) {
                    continue;
                }
                $row['secret'] = $secret;
            } elseif ($type === 'recovery') {
                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_code'] = $recoveryCode;
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
                $email = trim((string) ($method['email'] ?? ''));
                if ($email !== '') {
                    if (mb_strlen($email) > 254) {
                        $email = mb_substr($email, 0, 254);
                    }
                    $row['email'] = $email;
                }
            }

            $dedupeValue = (string) ($row['secret'] ?? $row['recovery_code'] ?? $row['credential_id'] ?? $row['email'] ?? '');
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
                $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!TotpService::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                $provisioningUri = TotpService::provisioningUri($totpIssuer, $fallbackEmail, $secret);
                if ($provisioningUri !== '') {
                    $row['provisioning_uri'] = $provisioningUri;
                    $qrDataUri = QrCodeService::dataUriSvgBase64($provisioningUri, 220);
                    if ($qrDataUri !== '') {
                        $row['qr_data_uri'] = $qrDataUri;
                    }
                }
            } elseif ($type === 'recovery') {
                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    continue;
                }

                $row['label'] = TwoFactorMethodRules::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_code'] = $recoveryCode;
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
                if ($email !== null) {
                    $row['email'] = $email;
                }
            }

            $row['status_label'] = TwoFactorMethodRules::statusLabel((string) $row['status']);
            $prepared[] = $row;
            if (count($prepared) >= self::MAX_METHODS) {
                break;
            }
        }

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

