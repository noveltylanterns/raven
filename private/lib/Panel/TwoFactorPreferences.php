<?php

/**
 * RAVEN CMS
 * ~/private/lib/Panel/TwoFactorPreferences.php
 * Panel 2FA preferences form normalization and view preparation helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Panel;

use Raven\Lib\Auth\TwoFactorMethodNormalizer;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\Totp;

/**
 * Panel-side helpers for managing user 2FA method preferences.
 * Used by PreferencesController and UserEditController; not needed on public routes.
 */
final class TwoFactorPreferences
{
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

        return TwoFactorMethodNormalizer::normalizeSubmitted($rawMethods, $fallbackEmail, $totpIssuer);
    }

    /**
     * Returns the 2FA methods list prepared for display in a panel view.
     *
     * @param array<int, array<string, mixed>> $methods      Stored methods for the user.
     * @param string                            $fallbackEmail User email fallback for email-type display.
     * @param string                            $totpIssuer   TOTP issuer name for display.
     * @return array<int, array<string, mixed>>
     */
    public function prepareMethodsForView(array $methods, string $fallbackEmail, string $totpIssuer): array
    {
        return TwoFactorMethodNormalizer::prepareForView($methods, $fallbackEmail, $totpIssuer);
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
}
