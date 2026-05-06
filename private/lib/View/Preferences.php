<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Preferences.php
 * Pure form-building and validation helpers for user preference flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Security\PhraseValidate;
use Raven\Lib\Security\Totp;

/**
 * Agnostic helpers for building and validating user preference form data.
 *
 * All methods are pure (static) and carry no dependency on DB access or the auth
 * session, making them safe to call from public, panel, and extension contexts alike.
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
        if ($fallbackEmail === '' || filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) === false) {
            $fallbackEmail = '';
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type   = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            $status = Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type === 'totp') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
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

            if ($type === 'recovery') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
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

            if ($type === 'webauthn') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $credentialId       = trim((string) ($method['credential_id'] ?? ''));
                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
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

            if ($type === 'email') {
                $email = strtolower(trim((string) ($method['email'] ?? '')));
                if ($email === '' && $fallbackEmail !== '') {
                    $email = $fallbackEmail;
                }

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
