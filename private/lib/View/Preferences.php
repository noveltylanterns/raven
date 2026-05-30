<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Preferences.php
 * User-preferences helpers for form normalization, validation, and persistence.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Repository\AuthWrite;
use Raven\Core\Repository\UserRead;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Format\Json;
use Raven\Lib\Parser\UserContactParser;
use Raven\Lib\Security\PhraseValidate;
use Raven\Lib\Security\Totp;
use Raven\Lib\Security\TotpCipher;

/**
 * Shared helpers for building, validating, and persisting preference form data.
 */
final class Preferences
{
    /**
     * Filters a raw 2FA method list down to only interactive (challengeable) methods.
     *
     * Validates each method's status, secret/credential completeness, and type before
     * including it. Appends a fallback email entry when the account email is provided
     * and no explicit email method has been confirmed.
     *
     * @param array<int, array<string, mixed>> $methods       Raw 2FA method rows from the user preferences row.
     * @param string                           $fallbackEmail Account email used when an email method omits its own address.
     * @return array<int, array<string, mixed>> Interactive method rows with normalized keys and labels.
     */
    public static function interactiveTwoFactorMethods(array $methods, string $fallbackEmail = ''): array
    {
        $interactive = [];
        $fallbackEmail = strtolower(trim($fallbackEmail));
        // Use fallback email only when it survives strict address validation.
        if ($fallbackEmail === '' || filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) === false) {
            $fallbackEmail = '';
        }

        // Evaluate each stored 2FA method independently and keep only interactive ones.
        foreach ($methods as $method) {
            // Ignore malformed rows so one bad payload entry does not block others.
            if (!is_array($method)) {
                continue;
            }

            $type   = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            $status = Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type);
            // TOTP methods are interactive only after confirmation.
            if ($type === 'totp') {
                // Pending/unconfirmed seeds cannot be used at login challenge time.
                if ($status !== 'confirmed') {
                    continue;
                }

                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
                // Keep only syntactically valid shared secrets.
                if (!Totp::isValidSecret($secret)) {
                    continue;
                }

                $interactive[] = [
                    'type'  => 'totp',
                    'key'   => Login2fa::forTotpSecret($secret),
                    'label' => Login2fa::normalizeLabel((string) ($method['label'] ?? ''), 'totp'),
                ];
                continue;
            }

            // Recovery methods require confirmed status and a valid stored hash.
            if ($type === 'recovery') {
                // Pending recovery setup is not yet eligible for challenges.
                if ($status !== 'confirmed') {
                    continue;
                }

                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                // Invalid hash payloads are skipped to avoid unusable recovery entries.
                if (!PhraseValidate::isValidHash($recoveryHash)) {
                    continue;
                }

                $interactive[] = [
                    'type'  => 'recovery',
                    'key'   => Login2fa::forRecoveryHash($recoveryHash),
                    'label' => (bool) ($method['reusable'] ?? false)
                        ? 'Recovery Phrase (Reusable)'
                        : 'Recovery Phrase',
                ];
                continue;
            }

            // WebAuthn methods require confirmed registration and complete credential material.
            if ($type === 'webauthn') {
                // Pending registration entries are excluded from interactive options.
                if ($status !== 'confirmed') {
                    continue;
                }

                $credentialId       = trim((string) ($method['credential_id'] ?? ''));
                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                // Both credential id and public key must exist for challenge verification.
                if ($credentialId === '' || $credentialPublicKey === '') {
                    continue;
                }

                $interactive[] = [
                    'type'          => 'webauthn',
                    'key'           => Login2fa::forWebauthnCredentialId($credentialId),
                    'label'         => Login2fa::normalizeLabel((string) ($method['label'] ?? ''), 'webauthn'),
                    'credential_id' => $credentialId,
                    'require_uv'    => (bool) ($method['require_uv'] ?? false),
                ];
                continue;
            }

            // Email method can use its own address or a validated account fallback address.
            if ($type === 'email') {
                $email = strtolower(trim((string) ($method['email'] ?? '')));
                // Fill missing method email from validated account email when available.
                if ($email === '' && $fallbackEmail !== '') {
                    $email = $fallbackEmail;
                }

                // Skip invalid destination addresses because email OTP would be undeliverable.
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $interactive[] = [
                    'type'  => 'email',
                    'key'   => Login2fa::forEmailAddress($email),
                    'label' => Login2fa::normalizeLabel((string) ($method['label'] ?? ''), 'email'),
                    'email' => $email,
                ];
            }
        }

        return $interactive;
    }

    /**
     * Validates editable preference fields and returns any error strings.
     *
     * Checks email presence, password length when provided, and unique-constraint
     * results supplied by the caller (username and email uniqueness must be resolved
     * before calling this method).
     *
     * @param string  $email         Normalized email value from the update payload.
     * @param ?string $password      New password when the user is changing it, or null to leave unchanged.
     * @param bool    $usernameTaken True when the submitted username belongs to a different user.
     * @param bool    $emailTaken    True when the submitted email belongs to a different user.
     * @return array<int, string> Validation error strings; empty when the payload is valid.
     */
    public static function validatePreferenceUpdate(
        string $email,
        ?string $password,
        bool $usernameTaken,
        bool $emailTaken
    ): array {
        $errors = [];
        // Preferences require a non-empty email address.
        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        // Password length checks apply only when user submitted a replacement password.
        if ($password !== null && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        // Surface caller-resolved username uniqueness conflicts.
        if ($usernameTaken) {
            $errors[] = 'Username is already in use.';
        }

        // Surface caller-resolved email uniqueness conflicts.
        if ($emailTaken) {
            $errors[] = 'Email is already in use.';
        }

        return $errors;
    }

    /**
     * Normalizes, validates, and persists one user-preferences payload.
     *
     * @param int $userId Target user id for the update.
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
     * } $payload Submitted preference-update payload.
     * @param UserRead $userRead User read repository used for uniqueness checks.
     * @param AuthWrite $authWrite Auth write repository used to persist updated fields.
     * @return array{ok: bool, errors: array<int, string>} Write status and validation errors.
     */
    public static function updateUserPreferences(
        int $userId,
        array $payload,
        UserRead $userRead,
        AuthWrite $authWrite
    ): array {
        $normalized = self::normalizePreferenceUpdatePayload($payload);
        $username = (string) ($normalized['username'] ?? '');
        $email = (string) ($normalized['email'] ?? '');
        $password = $normalized['password'] ?? null;

        $errors = self::validatePreferenceUpdate(
            $email,
            is_string($password) ? $password : null,
            $username !== '' && $userRead->usernameExistsForOtherUser($userId, $username),
            $userRead->emailExistsForOtherUser($userId, $email)
        );
        // Stop before persistence when validation failed.
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $authWrite->updatePreferences($userId, [
            'username' => $username,
            'display_name' => (string) ($normalized['display_name'] ?? ''),
            'email' => $email,
            'bio' => (string) ($normalized['bio'] ?? ''),
            'theme' => (string) ($normalized['theme'] ?? 'default'),
            'timezone' => (string) ($normalized['timezone'] ?? ''),
            'password_hash' => ($password !== null && $password !== '')
                ? password_hash($password, PASSWORD_DEFAULT)
                : null,
            'contact_profiles_encoded' => $normalized['contact_profiles_encoded'] ?? null,
            'two_factor_methods_encoded' => $normalized['two_factor_methods_encoded'] ?? null,
            'set_avatar' => (bool) ($normalized['set_avatar'] ?? false),
            'avatar_path' => $normalized['avatar_path'] ?? null,
            'cover_image' => $normalized['cover_image'] ?? null,
        ]);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Normalizes one raw preference payload for persistence.
     *
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
     * } $payload Raw user-submitted payload.
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
    private static function normalizePreferenceUpdatePayload(array $payload): array
    {
        $contactProfiles = UserContactParser::normalize((array) ($payload['contact_profiles'] ?? []));
        $twoFactorMethods = Login2fa::normalizeStored((array) ($payload['two_factor_methods'] ?? []));

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
            'contact_profiles_encoded' => UserContactParser::encode($contactProfiles),
            'two_factor_methods' => $twoFactorMethods,
            'two_factor_methods_encoded' => self::encodeTwoFactorMethods($twoFactorMethods),
            'set_avatar' => (bool) ($payload['set_avatar'] ?? false),
            'avatar_path' => $payload['avatar_path'] ?? null,
            // Preserve cover image only when payload provides a string-like value.
            'cover_image' => is_string($payload['cover_image'] ?? null)
                ? trim((string) $payload['cover_image'])
                : null,
        ];
    }

    /**
     * Encodes normalized two-factor methods for storage in the auth users table.
     *
     * @param array<int, array<string, mixed>> $methods Normalized 2FA method rows.
     * @return string|null JSON-encoded payload, or null when methods is empty.
     */
    private static function encodeTwoFactorMethods(array $methods): ?string
    {
        // Persist null for empty sets so preference storage remains compact.
        if ($methods === []) {
            return null;
        }

        $totpCipher = new TotpCipher();
        return Json::encode($totpCipher->encryptMethodSecrets($methods), JSON_UNESCAPED_SLASHES);
    }
}
