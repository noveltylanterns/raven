<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\Totp;
use Raven\Lib\Auth\TwoFactorMethodKey;
use Raven\Lib\Auth\TwoFactorMethodNormalizer;
use Raven\Lib\Auth\TwoFactorMethodRules;

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
     *   string: string,
     *   name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   timezone: string,
     *   avatar: string|null,
     *   cover_image: string|null,
     *   contact: array<int, array{type: string, value: string}>,
     *   two_factor: array<int, array<string, mixed>>
     * }
     */
    public function decodeUserPreferencesRow(array $row, AuthPayloadCodec $codec): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            // Empty string means "inherit from site/server default"; never default to UTC.
            'timezone' => (string) ($row['timezone'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                ? (string) $row['cover_image']
                : null,
            'contact' => $codec->decodeContactProfiles($row['contact'] ?? null),
            'two_factor' => $codec->decodeTwoFactorMethods($row['two_factor'] ?? null),
        ];
    }

    /**
     * @param array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   bio?: string,
     *   theme: string,
     *   timezone?: string,
     *   password: string|null,
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   two_factor_methods?: array<int, array<string, mixed>>,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image?: string|null
     * } $payload
     * @return array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   timezone: string,
     *   password: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   contact_profiles_encoded: ?string,
     *   two_factor_methods: array<int, array<string, mixed>>,
     *   two_factor_methods_encoded: ?string,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image: string|null
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
            'bio' => trim((string) ($payload['bio'] ?? '')),
            'theme' => trim((string) ($payload['theme'] ?? 'default')),
            // Empty string is valid and means "inherit from site/server default".
            'timezone' => trim((string) ($payload['timezone'] ?? '')),
            'password' => $payload['password'] ?? null,
            'contact_profiles' => $contactProfiles,
            'contact_profiles_encoded' => $codec->encodeContactProfiles($contactProfiles),
            'two_factor_methods' => $twoFactorMethods,
            'two_factor_methods_encoded' => $codec->encodeTwoFactorMethods($twoFactorMethods),
            'set_avatar' => (bool) ($payload['set_avatar'] ?? false),
            'avatar_path' => $payload['avatar_path'] ?? null,
            'cover_image' => is_string($payload['cover_image'] ?? null)
                ? trim((string) $payload['cover_image'])
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function interactiveTwoFactorMethods(array $methods, string $fallbackEmail = ''): array
    {
        $interactive = [];
        $fallbackEmail = strtolower(trim($fallbackEmail));
        if ($fallbackEmail === '' || filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) === false) {
            $fallbackEmail = '';
        }

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

                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!Totp::isValidSecret($secret)) {
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

                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'recovery',
                    'key' => TwoFactorMethodKey::forRecoveryHash($recoveryHash),
                    'label' => (bool) ($method['reusable'] ?? false)
                        ? 'Recovery Phrase (Reusable)'
                        : 'Recovery Phrase',
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
                continue;
            }

            if ($type === 'email') {
                $email = strtolower(trim((string) ($method['email'] ?? '')));
                if ($email === '' && $fallbackEmail !== '') {
                    $email = $fallbackEmail;
                }

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'email',
                    'key' => TwoFactorMethodKey::forEmailAddress($email),
                    'label' => TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), 'email'),
                    'email' => $email,
                ];
            }
        }

        return $interactive;
    }

    public function verifyTotpCode(array $methods, string $submittedCode, string $issuer = 'Raven CMS'): bool
    {
        $submittedCode = Totp::normalizeCode($submittedCode);
        if (!Totp::isValidCode($submittedCode)) {
            return false;
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
            if ($type !== 'totp' || $status !== 'confirmed' || !Totp::isValidSecret($secret)) {
                continue;
            }

            if (Totp::verifyCode($secret, $submittedCode, 1, $issuer)) {
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

        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type !== 'recovery' || $status !== 'confirmed') {
                continue;
            }

            $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
            if (!RecoveryPhrase::isValidHash($recoveryHash)) {
                continue;
            }

            if (
                $selectedMethodKey !== ''
                && !TwoFactorMethodKey::isRecoveryPool($selectedMethodKey)
                && $selectedMethodKey !== TwoFactorMethodKey::forRecoveryHash($recoveryHash)
            ) {
                continue;
            }

            if (!RecoveryPhrase::verify($normalizedSubmittedPhrase, $recoveryHash, 12)) {
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
