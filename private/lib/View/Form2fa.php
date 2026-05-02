<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Form2fa.php
 * Shared 2FA form normalization and view preparation helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\Totp;
use Raven\Lib\View\Qr;

/**
 * Shared helpers for managing user 2FA method form payloads.
 */
final class Form2fa
{
    private const MAX_METHODS = 20;

    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Shared input sanitizer for form field normalization.
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Returns the display labels for each supported 2FA method type.
     *
     * @return array<string, string> Map of type key to human-readable label.
     */
    public function typeOptions(): array
    {
        return [
            'none' => '<none>',
            'totp' => 'Authenticator App (TOTP)',
            'recovery' => 'Recovery Phrase',
            'webauthn' => 'Security Key (WebAuthn)',
            'email' => 'Email Code',
        ];
    }

    /**
     * Returns the validated list of existing-method indices from a submitted form payload.
     *
     * @param mixed $rawMethods Raw submitted methods array from the form POST.
     * @return array<int, int> Validated existing-index values.
     */
    public function normalizeSubmittedExistingIndices(mixed $rawMethods): array
    {
        if (!is_array($rawMethods)) {
            return [];
        }

        $normalized = [];
        foreach ($rawMethods as $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingIndex = $this->input->int($row['existing_index'] ?? null, 0, 1000);
            if ($existingIndex === null) {
                continue;
            }

            $normalized[$existingIndex] = $existingIndex;
            if (count($normalized) >= 100) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * Returns a normalized list of newly submitted 2FA methods from a form POST.
     *
     * @param mixed  $rawMethods    Raw submitted methods array from the form POST.
     * @param string $fallbackEmail User email used as fallback for email-type methods.
     * @param string $totpIssuer    Issuer name for TOTP method entries.
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSubmittedMethods(mixed $rawMethods, string $fallbackEmail, string $totpIssuer): array
    {
        if (!is_array($rawMethods)) {
            return [];
        }

        $normalized = [];
        $numberedLabelState = $this->initializeNumberedLabelState($rawMethods);
        foreach ($rawMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            if ($type === '' || $type === 'none' || !Login2fa::isKnownType($type)) {
                continue;
            }

            $rawLabel = $this->sanitizeText((string) ($method['label'] ?? ''), 80);
            $label = in_array($type, ['webauthn', 'email'], true)
                ? $this->resolveSubmittedNumberedLabel($rawLabel, $type, $numberedLabelState)
                : Login2fa::normalizeLabel($rawLabel, $type);

            $row = [
                'type' => $type,
                'label' => $label,
                'status' => Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type),
                'added_at' => $this->normalizeAddedAt($method['added_at'] ?? null),
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

                $row['label'] = Login2fa::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = isset($method['reusable']) && (string) ($method['reusable'] ?? '') === '1';
            } elseif ($type === 'webauthn') {
                $credentialId = $this->sanitizeText((string) ($method['credential_id'] ?? ''), 512);
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
                $email = $this->sanitizeEmail((string) ($method['target_email'] ?? ''));
                if ($email === null) {
                    $email = $this->sanitizeEmail($fallbackEmail);
                }
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $dedupeValue = (string) ($row['secret'] ?? $row['recovery_hash'] ?? $row['credential_id'] ?? $row['email'] ?? '');
            $dedupeLabel = trim((string) ($row['label'] ?? $label));
            $dedupeKey = Login2fa::dedupeKey($type, $dedupeLabel, $dedupeValue);
            $normalized[$dedupeKey] = $row;
            if (count($normalized) >= self::MAX_METHODS) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * Returns the 2FA methods list prepared for display in an account form view.
     *
     * @param array<int, array<string, mixed>> $methods      Stored methods for the user.
     * @param string                            $fallbackEmail User email fallback for email-type display.
     * @param string                            $totpIssuer   TOTP issuer name for display.
     * @return array<int, array<string, mixed>>
     */
    public function prepareMethodsForView(array $methods, string $fallbackEmail, string $totpIssuer): array
    {
        $prepared = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            if (!Login2fa::isKnownType($type)) {
                continue;
            }

            $status = Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $label = Login2fa::normalizeLabel((string) ($method['label'] ?? ''), $type);
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

                $row['label'] = Login2fa::defaultLabelForType('recovery');
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
                $email = $this->sanitizeEmail((string) ($method['email'] ?? ''));
                if ($email === null) {
                    $email = $this->sanitizeEmail($fallbackEmail);
                }
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $row['status_label'] = Login2fa::statusLabel((string) $row['status']);
            $prepared[] = $row;
            if (count($prepared) >= self::MAX_METHODS) {
                break;
            }
        }

        usort($prepared, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) ($left['label'] ?? '')));
            $rightLabel = strtolower(trim((string) ($right['label'] ?? '')));
            if ($leftLabel === '' || $rightLabel === '') {
                $leftFallback = strtolower(Login2fa::defaultLabelForType((string) ($left['type'] ?? '')));
                $rightFallback = strtolower(Login2fa::defaultLabelForType((string) ($right['type'] ?? '')));
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

    /**
     * Returns the TOTP issuer name, falling back to 'Raven CMS' if the site name is empty.
     *
     * @param string $siteName Configured site name.
     * @return string Non-empty issuer string.
     */
    public function resolveTotpIssuer(string $siteName): string
    {
        $issuer = trim($siteName);
        return $issuer !== '' ? $issuer : 'Raven CMS';
    }

    /**
     * Builds the TOTP setup payload for the preferences form, generating a secret if needed.
     *
     * @param mixed  $submittedSecret Previously submitted or newly generated TOTP secret.
     * @param string $accountEmail    User's email address for the provisioning URI.
     * @param string $totpIssuer      Issuer name for the provisioning URI.
     * @return array{ok: bool, message?: string, secret?: string, issuer?: string, account?: string, provisioning_uri?: string}
     */
    public function buildTotpSetupPayload(mixed $submittedSecret, string $accountEmail, string $totpIssuer): array
    {
        $secret = Totp::normalizeSecret((string) $submittedSecret);
        if (!Totp::isValidSecret($secret)) {
            $generatedSecret = Totp::generateSecret($totpIssuer);
            $secret = is_string($generatedSecret) ? $generatedSecret : '';
        }

        if (!Totp::isValidSecret($secret)) {
            return ['ok' => false, 'message' => 'Unable to generate a TOTP secret.'];
        }

        $provisioningUri = Totp::provisioningUri($totpIssuer, $accountEmail, $secret);
        if ($provisioningUri === '') {
            return ['ok' => false, 'message' => 'Unable to build TOTP provisioning data.'];
        }

        $accountAddress = $this->input->email($accountEmail);
        if ($accountAddress === null) {
            $accountAddress = 'account@local';
        }

        return [
            'ok' => true,
            'secret' => $secret,
            'issuer' => $totpIssuer,
            'account' => $accountAddress,
            'provisioning_uri' => $provisioningUri,
        ];
    }

    /**
     * Generates a new random recovery phrase.
     *
     * @param int $wordCount Number of words in the phrase (default 12).
     * @return string|null Generated phrase, or null if generation failed.
     */
    public function generateRecoveryPhrase(int $wordCount = 12): ?string
    {
        $phrase = RecoveryPhrase::generate($wordCount);
        if (!is_string($phrase) || !RecoveryPhrase::isValid($phrase, $wordCount)) {
            return null;
        }

        return $phrase;
    }

    /**
     * Collects WebAuthn credential IDs to exclude from a new registration ceremony.
     * Merges IDs from already-stored methods with any additionally submitted exclusion IDs.
     *
     * @param array<int, array<string, mixed>> $storedMethods      Stored 2FA methods for the user.
     * @param mixed                             $submittedExcludeIds Additional credential IDs submitted by the client.
     * @param int                               $maxCredentials      Maximum number of exclude entries to include.
     * @return array<int, string> Binary credential ID strings for the WebAuthn excludeCredentials list.
     */
    public function collectWebauthnExcludeCredentialIds(
        array $storedMethods,
        mixed $submittedExcludeIds = null,
        int $maxCredentials = 20
    ): array {
        $excludeCredentialIds = [];
        $seenCredentialIds = [];

        $appendCredentialId = static function (string $credentialIdB64) use (&$excludeCredentialIds, &$seenCredentialIds): void {
            $credentialIdB64 = trim($credentialIdB64);
            if ($credentialIdB64 === '' || isset($seenCredentialIds[$credentialIdB64])) {
                return;
            }

            $credentialBinary = base64_decode($credentialIdB64, true);
            if (!is_string($credentialBinary) || $credentialBinary === '') {
                return;
            }

            $seenCredentialIds[$credentialIdB64] = true;
            $excludeCredentialIds[] = $credentialBinary;
        };

        foreach ($storedMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn') {
                continue;
            }

            $appendCredentialId((string) ($method['credential_id'] ?? ''));
        }

        if (is_array($submittedExcludeIds)) {
            foreach ($submittedExcludeIds as $credentialIdCandidate) {
                if (!is_scalar($credentialIdCandidate)) {
                    continue;
                }

                $appendCredentialId((string) $credentialIdCandidate);
                if (count($excludeCredentialIds) >= max(1, $maxCredentials)) {
                    break;
                }
            }
        }

        return $excludeCredentialIds;
    }

    /**
     * Resolves the WebAuthn user identity fields from stored preferences.
     * Falls back to email, then to a generated identifier if both are empty.
     *
     * @param array<string, mixed> $preferences Stored user preferences row.
     * @param int                  $userId      User ID for fallback identifier generation.
     * @return array{username: string, display_name: string}
     */
    public function resolveWebauthnUserIdentity(array $preferences, int $userId): array
    {
        $username = trim((string) ($preferences['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($preferences['email'] ?? ''));
        }
        if ($username === '') {
            $username = 'user-' . $userId;
        }

        $displayName = trim((string) ($preferences['name'] ?? ''));
        if ($displayName === '') {
            $displayName = $username;
        }

        return [
            'username' => $username,
            'display_name' => $displayName,
        ];
    }

    /**
     * @param array<int, mixed> $methods
     * @return array{webauthn: array<int, bool>, email: array<int, bool>}
     */
    private function initializeNumberedLabelState(array $methods): array
    {
        $state = [
            'webauthn' => [],
            'email' => [],
        ];

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            if (!array_key_exists($type, $state)) {
                continue;
            }

            $label = $this->sanitizeText((string) ($method['label'] ?? ''), 80);
            if ($label === '') {
                continue;
            }

            $base = $this->numberedLabelBase($type);
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
    private function resolveSubmittedNumberedLabel(string $rawLabel, string $type, array &$state): string
    {
        $type = Login2fa::normalizeType($type);
        if (!array_key_exists($type, $state)) {
            return Login2fa::normalizeLabel($rawLabel, $type);
        }

        $label = $this->sanitizeText($rawLabel, 80);
        $base = $this->numberedLabelBase($type);
        if (preg_match('/^' . preg_quote($base, '/') . '\s+([1-9][0-9]*)$/i', $label, $matches) === 1) {
            $number = (int) ($matches[1] ?? 0);
            if ($number > 0) {
                $state[$type][$number] = true;
                return $base . ' ' . $number;
            }
        }

        $normalizedLabel = strtolower(trim($label));
        $autoLabelCandidates = $this->numberedLabelLegacyDefaults($type);
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
            return Login2fa::normalizeLabel($label, $type);
        }

        $nextNumber = 1;
        while (isset($state[$type][$nextNumber])) {
            $nextNumber += 1;
        }

        $state[$type][$nextNumber] = true;
        return $base . ' ' . $nextNumber;
    }

    private function numberedLabelBase(string $type): string
    {
        return match (Login2fa::normalizeType($type)) {
            'webauthn' => 'My Key',
            'email' => 'My Email',
            default => Login2fa::defaultLabelForType($type),
        };
    }

    /**
     * @return array<int, string>
     */
    private function numberedLabelLegacyDefaults(string $type): array
    {
        $base = $this->numberedLabelBase($type);
        return [
            $base,
            Login2fa::defaultLabelForType($type),
        ];
    }

    private function normalizeAddedAt(mixed $value): string
    {
        $addedAt = trim((string) $value);
        if ($addedAt === '') {
            return gmdate('Y-m-d H:i:s');
        }

        return $addedAt;
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function sanitizeEmail(string $value): ?string
    {
        $value = strtolower($this->sanitizeText($value, 254));
        if ($value === '') {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
    }
}
