<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\TotpService;
use Raven\Lib\Security\TwoFactorMethodKey;
use Raven\Lib\Security\TwoFactorMethodNormalizer;
use Raven\Lib\Security\TwoFactorMethodRules;

/**
 * Shared 2FA/contact preference payload normalization and verification helpers.
 */
final class UserSecurityProfileService
{
    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   avatar_path: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   two_factor_methods: array<int, array<string, mixed>>
     * }
     */
    public function decodeUserPreferencesRow(array $row, AuthPayloadCodec $codec): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                ? (string) $row['avatar_path']
                : null,
            'contact_profiles' => $codec->decodeContactProfiles($row['contact_profiles'] ?? null),
            'two_factor_methods' => $codec->decodeTwoFactorMethods($row['two_factor_methods'] ?? null),
        ];
    }

    /**
     * @param array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   password: string|null,
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   two_factor_methods?: array<int, array<string, mixed>>,
     *   set_avatar: bool,
     *   avatar_path: string|null
     * } $payload
     * @return array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   password: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   contact_profiles_encoded: ?string,
     *   two_factor_methods: array<int, array<string, mixed>>,
     *   two_factor_methods_encoded: ?string,
     *   set_avatar: bool,
     *   avatar_path: string|null
     * }
     */
    public function normalizePreferenceUpdatePayload(array $payload, AuthPayloadCodec $codec): array
    {
        $contactProfiles = $codec->normalizeContactProfiles((array) ($payload['contact_profiles'] ?? []));
        $twoFactorMethods = TwoFactorMethodNormalizer::normalizeStored((array) ($payload['two_factor_methods'] ?? []));

        return [
            'username' => trim((string) ($payload['username'] ?? '')),
            'display_name' => trim((string) ($payload['display_name'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'theme' => trim((string) ($payload['theme'] ?? 'default')),
            'password' => $payload['password'] ?? null,
            'contact_profiles' => $contactProfiles,
            'contact_profiles_encoded' => $codec->encodeContactProfiles($contactProfiles),
            'two_factor_methods' => $twoFactorMethods,
            'two_factor_methods_encoded' => $codec->encodeTwoFactorMethods($twoFactorMethods),
            'set_avatar' => (bool) ($payload['set_avatar'] ?? false),
            'avatar_path' => $payload['avatar_path'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function interactiveTwoFactorMethods(array $methods): array
    {
        $interactive = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type === 'totp') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!TotpService::isValidSecret($secret)) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'totp',
                    'key' => TwoFactorMethodKey::forTotpSecret($secret),
                    'label' => TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), 'totp'),
                ];
                continue;
            }

            if ($type === 'recovery') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'recovery',
                    'key' => TwoFactorMethodKey::forRecoveryPhrase($recoveryCode),
                    'label' => (bool) ($method['reusable'] ?? false)
                        ? 'Recovery Code (Reusable)'
                        : 'Recovery Code',
                ];
                continue;
            }

            if ($type === 'webauthn') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $credentialId = trim((string) ($method['credential_id'] ?? ''));
                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                if ($credentialId === '' || $credentialPublicKey === '') {
                    continue;
                }

                $requireUv = (bool) ($method['require_uv'] ?? false);

                $interactive[] = [
                    'type' => 'webauthn',
                    'key' => TwoFactorMethodKey::forWebauthnCredentialId($credentialId),
                    'label' => TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), 'webauthn'),
                    'credential_id' => $credentialId,
                    'require_uv' => $requireUv,
                ];
            }
        }

        return $interactive;
    }

    public function verifyTotpCode(array $methods, string $submittedCode, string $issuer = 'Raven CMS'): bool
    {
        $submittedCode = TotpService::normalizeCode($submittedCode);
        if (!TotpService::isValidCode($submittedCode)) {
            return false;
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
            if ($type !== 'totp' || $status !== 'confirmed' || !TotpService::isValidSecret($secret)) {
                continue;
            }

            if (TotpService::verifyCode($secret, $submittedCode, 1, $issuer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{index: int, reusable: bool}|null
     */
    public function matchRecoveryMethod(array $methods, string $submittedPhrase, string $selectedMethodKey = ''): ?array
    {
        $normalizedSubmittedPhrase = RecoveryPhrase::normalize($submittedPhrase);
        if (!RecoveryPhrase::isValid($normalizedSubmittedPhrase, 12)) {
            return null;
        }

        $selectedMethodKey = trim($selectedMethodKey);
        $expectedMethodKey = TwoFactorMethodKey::forRecoveryPhrase($normalizedSubmittedPhrase);
        if ($selectedMethodKey !== '' && $selectedMethodKey !== $expectedMethodKey) {
            return null;
        }

        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type !== 'recovery' || $status !== 'confirmed') {
                continue;
            }

            $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
            if (!RecoveryPhrase::isValid($recoveryCode, 12) || $recoveryCode !== $normalizedSubmittedPhrase) {
                continue;
            }

            return [
                'index' => (int) $index,
                'reusable' => (bool) ($method['reusable'] ?? false),
            ];
        }

        return null;
    }

    /**
     * @return array{methods: array<int, array<string, mixed>>, updated: bool}
     */
    public function withUpdatedWebauthnSignatureCounter(array $methods, string $credentialId, int $signatureCounter): array
    {
        if ($credentialId === '' || $signatureCounter < 0) {
            return [
                'methods' => array_values($methods),
                'updated' => false,
            ];
        }

        $updated = false;
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) === 'webauthn'
                && trim((string) ($method['credential_id'] ?? '')) === $credentialId
            ) {
                $methods[$index]['signature_counter'] = $signatureCounter;
                $updated = true;
                break;
            }
        }

        return [
            'methods' => array_values($methods),
            'updated' => $updated,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function validatePreferenceUpdate(
        string $email,
        ?string $password,
        bool $usernameTaken,
        bool $emailTaken
    ): array {
        $errors = [];
        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if ($password !== null && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($usernameTaken) {
            $errors[] = 'Username is already in use.';
        }

        if ($emailTaken) {
            $errors[] = 'Email is already in use.';
        }

        return $errors;
    }
}
