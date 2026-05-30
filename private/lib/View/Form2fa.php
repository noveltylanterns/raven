<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Form2fa.php
 * Shared 2FA form normalization and view preparation helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\PhraseGenerate;
use Raven\Lib\Security\PhraseValidate;
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
        // Existing-index extraction requires array-shaped submitted method rows.
        if (!is_array($rawMethods)) {
            return [];
        }

        $normalized = [];
        // Collect and deduplicate valid existing indices.
        foreach ($rawMethods as $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingIndex = $this->input->int($row['existing_index'] ?? null, 0, 1000);
            // Ignore rows without a usable numeric existing index.
            if ($existingIndex === null) {
                continue;
            }

            $normalized[$existingIndex] = $existingIndex;
            // Cap index collection size for defensive bounds.
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
        // Method normalization requires array-form submitted rows.
        if (!is_array($rawMethods)) {
            return [];
        }

        $normalized = [];
        $numberedLabelState = $this->initializeNumberedLabelState($rawMethods);
        // Normalize each submitted 2FA method row into one deduplicated entry.
        foreach ($rawMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            // Skip unknown/disabled placeholder types.
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

            // TOTP rows require a valid secret; generate one when omitted.
            if ($type === 'totp') {
                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                // Generate a new secret when none was submitted.
                if ($secret === '') {
                    $generated = Totp::generateSecret($totpIssuer);
                    $secret = is_string($generated) ? $generated : '';
                }

                // Discard TOTP rows when a valid secret is unavailable.
                if (!Totp::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                // Unverified TOTP methods remain pending until a code check succeeds.
                if ($row['status'] !== 'confirmed') {
                    $row['status'] = 'pending';
                }
                $verificationCode = Totp::normalizeCode((string) ($method['verification_code'] ?? ''));
                // Inline verification can promote pending TOTP rows to confirmed.
                if ($verificationCode !== '' && Totp::verifyCode($secret, $verificationCode, 1, $totpIssuer)) {
                    $row['status'] = 'confirmed';
                }
            } elseif ($type === 'recovery') {
                $recoveryCode = PhraseValidate::normalize((string) ($method['recovery_code'] ?? ''));
                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                // Submitted recovery phrase can refresh/replace the stored recovery hash.
                if (PhraseValidate::isValid($recoveryCode, 12)) {
                    $generatedHash = PhraseGenerate::hash($recoveryCode, 12);
                    $recoveryHash = is_string($generatedHash) ? $generatedHash : '';
                }

                // Recovery rows must include a valid persisted recovery hash.
                if (!PhraseValidate::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = Login2fa::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = isset($method['reusable']) && (string) ($method['reusable'] ?? '') === '1';
            } elseif ($type === 'webauthn') {
                $credentialId = $this->sanitizeText((string) ($method['credential_id'] ?? ''), 512);
                // Keep credential id only when present.
                if ($credentialId !== '') {
                    $row['credential_id'] = $credentialId;
                }

                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                // Keep credential public key when present.
                if ($credentialPublicKey !== '') {
                    // Bound credential key size to prevent oversized payload persistence.
                    if (mb_strlen($credentialPublicKey) > 4096) {
                        $credentialPublicKey = mb_substr($credentialPublicKey, 0, 4096);
                    }
                    $row['credential_public_key'] = $credentialPublicKey;
                }

                $signatureCounter = (int) ($method['signature_counter'] ?? 0);
                // Signature counters are non-negative per WebAuthn semantics.
                if ($signatureCounter < 0) {
                    $signatureCounter = 0;
                }
                $row['signature_counter'] = $signatureCounter;
                // Only fully populated credential rows are considered confirmed.
                $row['status'] = (($row['credential_id'] ?? '') !== '' && ($row['credential_public_key'] ?? '') !== '')
                    ? 'confirmed'
                    : 'stub';
                $row['require_uv'] = isset($method['require_uv']) && (string) ($method['require_uv'] ?? '') === '1';
            } else {
                $email = $this->sanitizeEmail((string) ($method['target_email'] ?? ''));
                // Fall back to account email when method-specific target email is invalid.
                if ($email === null) {
                    $email = $this->sanitizeEmail($fallbackEmail);
                }
                // Drop email methods when no valid destination address is available.
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
            // Keep at most MAX_METHODS normalized rows.
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
        // Normalize each stored method row into a view-ready structure.
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            // Ignore unrecognized method types from malformed rows.
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

            // Build type-specific view payloads.
            if ($type === 'totp') {
                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                // Skip TOTP rows with invalid secrets.
                if (!Totp::isValidSecret($secret)) {
                    continue;
                }

                $row['secret'] = $secret;
                $provisioningUri = Totp::provisioningUri($totpIssuer, $fallbackEmail, $secret);
                // Include provisioning URI and QR code only when URI generation succeeds.
                if ($provisioningUri !== '') {
                    $row['provisioning_uri'] = $provisioningUri;
                    $qrDataUri = Qr::dataUriSvgBase64($provisioningUri, 220);
                    // QR data URI is optional and omitted when SVG generation fails.
                    if ($qrDataUri !== '') {
                        $row['qr_data_uri'] = $qrDataUri;
                    }
                }
            } elseif ($type === 'recovery') {
                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                // Recovery rows must keep a valid persisted recovery hash.
                if (!PhraseValidate::isValidHash($recoveryHash)) {
                    continue;
                }

                $row['label'] = Login2fa::defaultLabelForType('recovery');
                $row['status'] = 'confirmed';
                $row['recovery_hash'] = $recoveryHash;
                $row['reusable'] = (bool) ($method['reusable'] ?? false);
            } elseif ($type === 'webauthn') {
                $credentialId = trim((string) ($method['credential_id'] ?? ''));
                // Carry credential id when available.
                if ($credentialId !== '') {
                    $row['credential_id'] = $credentialId;
                }

                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                // Carry credential public key when available.
                if ($credentialPublicKey !== '') {
                    $row['credential_public_key'] = $credentialPublicKey;
                }

                $signatureCounter = (int) ($method['signature_counter'] ?? 0);
                // Clamp negative counters to zero for display consistency.
                if ($signatureCounter < 0) {
                    $signatureCounter = 0;
                }
                $row['signature_counter'] = $signatureCounter;
                // WebAuthn entries are confirmed only when both credential fields exist.
                $row['status'] = (($row['credential_id'] ?? '') !== '' && ($row['credential_public_key'] ?? '') !== '')
                    ? 'confirmed'
                    : 'stub';
                $row['require_uv'] = (bool) ($method['require_uv'] ?? false);
            } else {
                $email = $this->sanitizeEmail((string) ($method['email'] ?? ''));
                // Use fallback email when method email is absent/invalid.
                if ($email === null) {
                    $email = $this->sanitizeEmail($fallbackEmail);
                }
                // Drop email entries when no valid destination is available.
                if ($email === null) {
                    continue;
                }

                $row['email'] = $email;
                $row['status'] = 'confirmed';
            }

            $row['status_label'] = Login2fa::statusLabel((string) $row['status']);
            $prepared[] = $row;
            // Keep prepared view list within MAX_METHODS cap.
            if (count($prepared) >= self::MAX_METHODS) {
                break;
            }
        }

        usort($prepared, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) ($left['label'] ?? '')));
            $rightLabel = strtolower(trim((string) ($right['label'] ?? '')));
            // Fill empty labels with type defaults before alphabetical comparison.
            if ($leftLabel === '' || $rightLabel === '') {
                $leftFallback = strtolower(Login2fa::defaultLabelForType((string) ($left['type'] ?? '')));
                $rightFallback = strtolower(Login2fa::defaultLabelForType((string) ($right['type'] ?? '')));
                // Replace missing left label with its normalized type default.
                if ($leftLabel === '') {
                    $leftLabel = $leftFallback;
                }
                // Replace missing right label with its normalized type default.
                if ($rightLabel === '') {
                    $rightLabel = $rightFallback;
                }
            }

            // Primary sort by display label for stable UI ordering.
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
        // Generate fresh secret when submitted value is missing/invalid.
        if (!Totp::isValidSecret($secret)) {
            $generatedSecret = Totp::generateSecret($totpIssuer);
            $secret = is_string($generatedSecret) ? $generatedSecret : '';
        }

        // Abort when no valid secret can be produced.
        if (!Totp::isValidSecret($secret)) {
            return ['ok' => false, 'message' => 'Unable to generate a TOTP secret.'];
        }

        $provisioningUri = Totp::provisioningUri($totpIssuer, $accountEmail, $secret);
        // Provisioning URI is required for QR/bootstrap setup flow.
        if ($provisioningUri === '') {
            return ['ok' => false, 'message' => 'Unable to build TOTP provisioning data.'];
        }

        $accountAddress = $this->input->email($accountEmail);
        // Fall back to local placeholder when account email is invalid/missing.
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
        $phrase = PhraseGenerate::generate($wordCount);
        // Reject generation output unless it passes canonical phrase validation.
        if (!is_string($phrase) || !PhraseValidate::isValid($phrase, $wordCount)) {
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
            // Ignore blank or already-seen credential ids.
            if ($credentialIdB64 === '' || isset($seenCredentialIds[$credentialIdB64])) {
                return;
            }

            $credentialBinary = base64_decode($credentialIdB64, true);
            // Skip malformed base64 credential identifiers.
            if (!is_string($credentialBinary) || $credentialBinary === '') {
                return;
            }

            $seenCredentialIds[$credentialIdB64] = true;
            $excludeCredentialIds[] = $credentialBinary;
        };

        // Seed exclusion list from already-stored WebAuthn methods.
        foreach ($storedMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            // Only WebAuthn methods contribute registration exclude IDs.
            if (strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn') {
                continue;
            }

            $appendCredentialId((string) ($method['credential_id'] ?? ''));
        }

        // Merge any client-submitted extra exclusions.
        if (is_array($submittedExcludeIds)) {
            foreach ($submittedExcludeIds as $credentialIdCandidate) {
                // Ignore non-scalar candidates from malformed payloads.
                if (!is_scalar($credentialIdCandidate)) {
                    continue;
                }

                $appendCredentialId((string) $credentialIdCandidate);
                // Cap exclusion count to keep registration options bounded.
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
        // Fall back to email when username preference is blank.
        if ($username === '') {
            $username = trim((string) ($preferences['email'] ?? ''));
        }
        // Final fallback uses deterministic user-id identifier.
        if ($username === '') {
            $username = 'user-' . $userId;
        }

        $displayName = trim((string) ($preferences['name'] ?? ''));
        // Empty display names default to the resolved username.
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

        // Scan existing rows to reserve already-used numbered labels per type.
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            // Ignore types that do not participate in numbered labels.
            if (!array_key_exists($type, $state)) {
                continue;
            }

            $label = $this->sanitizeText((string) ($method['label'] ?? ''), 80);
            // Blank labels cannot contribute numbered reservations.
            if ($label === '') {
                continue;
            }

            $base = $this->numberedLabelBase($type);
            // Reserve only labels that match the "{base} {number}" pattern.
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
        // Types without numbered-label state use standard label normalization.
        if (!array_key_exists($type, $state)) {
            return Login2fa::normalizeLabel($rawLabel, $type);
        }

        $label = $this->sanitizeText($rawLabel, 80);
        $base = $this->numberedLabelBase($type);
        // Preserve explicit valid numbered labels submitted by the user.
        if (preg_match('/^' . preg_quote($base, '/') . '\s+([1-9][0-9]*)$/i', $label, $matches) === 1) {
            $number = (int) ($matches[1] ?? 0);
            // Reserve positive numbers and return canonicalized "{base} N" label.
            if ($number > 0) {
                $state[$type][$number] = true;
                return $base . ' ' . $number;
            }
        }

        $normalizedLabel = strtolower(trim($label));
        $autoLabelCandidates = $this->numberedLabelLegacyDefaults($type);
        $shouldAuto = $normalizedLabel === '';
        // Compare against legacy/default labels before deciding whether to auto-number.
        if (!$shouldAuto) {
            foreach ($autoLabelCandidates as $candidate) {
                // Matching a legacy candidate flips the row into auto-numbering mode.
                if ($normalizedLabel === strtolower(trim($candidate))) {
                    $shouldAuto = true;
                    break;
                }
            }
        }

        // Keep non-auto labels as standard normalized labels.
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

    /**
     * Returns the base label text used for auto-numbered method labels.
     *
     * @param string $type 2FA method type.
     * @return string Method-type-specific base label.
     */
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

    /**
     * Normalizes one `added_at` value for stable UI output.
     *
     * @param mixed $value Raw timestamp value from stored method payloads.
     * @return string Non-empty timestamp string.
     */
    private function normalizeAddedAt(mixed $value): string
    {
        $addedAt = trim((string) $value);
        // Use current UTC timestamp when no stored added-at value exists.
        if ($addedAt === '') {
            return gmdate('Y-m-d H:i:s');
        }

        return $addedAt;
    }

    /**
     * Trims, strips control bytes, and length-limits one UI text value.
     *
     * @param string $value Raw input text.
     * @param int $maxLength Maximum allowed UTF-8 character length.
     * @return string Sanitized display-safe value.
     */
    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        // Enforce max UI text length after control-byte stripping.
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Normalizes and validates one email value for 2FA method display/editing.
     *
     * @param string $value Raw email candidate.
     * @return string|null Lowercased validated email, or null when invalid.
     */
    private function sanitizeEmail(string $value): ?string
    {
        $value = strtolower($this->sanitizeText($value, 254));
        // Empty strings are treated as missing email values.
        if ($value === '') {
            return null;
        }

        // Require PHP email validation before accepting the address.
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
    }
}
