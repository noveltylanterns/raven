<?php

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\TotpService;
use Raven\Lib\Security\TwoFactorMethodNormalizer;

/**
 * Shared panel preferences helpers for user-managed 2FA method payloads.
 */
final class PanelTwoFactorPreferencesService
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @return array<string, string>
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
     * @param mixed $rawMethods
     * @return array<int, int>
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
     * @param mixed $rawMethods
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
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public function prepareMethodsForView(array $methods, string $fallbackEmail, string $totpIssuer): array
    {
        return TwoFactorMethodNormalizer::prepareForView($methods, $fallbackEmail, $totpIssuer);
    }

    public function resolveTotpIssuer(string $siteName): string
    {
        $issuer = trim($siteName);
        return $issuer !== '' ? $issuer : 'Raven CMS';
    }

    /**
     * @return array{ok: bool, message?: string, secret?: string, issuer?: string, account?: string, provisioning_uri?: string}
     */
    public function buildTotpSetupPayload(mixed $submittedSecret, string $accountEmail, string $totpIssuer): array
    {
        $secret = TotpService::normalizeSecret((string) $submittedSecret);
        if (!TotpService::isValidSecret($secret)) {
            $generatedSecret = TotpService::generateSecret($totpIssuer);
            $secret = is_string($generatedSecret) ? $generatedSecret : '';
        }

        if (!TotpService::isValidSecret($secret)) {
            return ['ok' => false, 'message' => 'Unable to generate a TOTP secret.'];
        }

        $provisioningUri = TotpService::provisioningUri($totpIssuer, $accountEmail, $secret);
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

    public function generateRecoveryPhrase(int $wordCount = 12): ?string
    {
        $phrase = RecoveryPhrase::generate($wordCount);
        if (!is_string($phrase) || !RecoveryPhrase::isValid($phrase, $wordCount)) {
            return null;
        }

        return $phrase;
    }

    /**
     * @param array<int, array<string, mixed>> $storedMethods
     * @return array<int, string>
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
     * @param array<string, mixed> $preferences
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
