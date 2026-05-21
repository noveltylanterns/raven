<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginUiState.php
 * Session state manager for login UI redirects and 2FA challenge flow state.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared session-state helper for surface-specific login redirects and 2FA UI state.
 *
 * Instances are surface-specific: use forPanel() on panel routes and forPublic() on
 * public routes. Each instance stores keys under a distinct prefix so the two surfaces
 * never collide in a shared session.
 */
final class LoginUiState
{
    private string $postLoginRedirectKey;
    private string $selectedMethodKeyKey;
    private string $webauthnFailedKey;
    private string $webauthnChallengeKey;
    private string $forceMethodPickerKey;
    private string $emailInputKey;

    /**
     * Builds one surface-scoped session-key namespace for login UI state.
     *
     * @param string $prefix Session-key prefix (`_raven` or `_raven_public`).
     */
    private function __construct(string $prefix)
    {
        $prefix = trim($prefix);
        $this->postLoginRedirectKey = $prefix . '_post_login_redirect';
        $this->selectedMethodKeyKey = $prefix . '_2fa_selected_method_key';
        $this->webauthnFailedKey = $prefix . '_2fa_webauthn_failed';
        $this->webauthnChallengeKey = $prefix . '_2fa_webauthn_challenge';
        $this->forceMethodPickerKey = $prefix . '_2fa_force_method_picker';
        $this->emailInputKey = $prefix . '_2fa_email_input';
    }

    /**
     * Returns a LoginUiState instance scoped to the panel login surface.
     *
     * @return self Panel-scoped login UI state.
     */
    public static function forPanel(): self
    {
        return new self('_raven');
    }

    /**
     * Returns a LoginUiState instance scoped to the public login surface.
     *
     * @return self Public-scoped login UI state.
     */
    public static function forPublic(): self
    {
        return new self('_raven_public');
    }

    /**
     * Returns the stored post-login redirect path for this surface.
     *
     * @return string Stored redirect path, or empty string when none is set.
     */
    public function postLoginRedirect(): string
    {
        return trim((string) ($_SESSION[$this->postLoginRedirectKey] ?? ''));
    }

    /**
     * Stores a post-login redirect path in the session.
     *
     * @param string $value Redirect path to persist; clears the key when empty.
     */
    public function storePostLoginRedirect(string $value): void
    {
        $value = trim($value);
        // Empty values clear redirect state instead of storing a blank route.
        if ($value === '') {
            unset($_SESSION[$this->postLoginRedirectKey]);
            return;
        }

        $_SESSION[$this->postLoginRedirectKey] = $value;
    }

    /**
     * Reads and clears the post-login redirect path in one operation.
     *
     * @return string Stored redirect path, or empty string when none was set.
     */
    public function consumePostLoginRedirect(): string
    {
        $value = $this->postLoginRedirect();
        unset($_SESSION[$this->postLoginRedirectKey]);
        return $value;
    }

    /**
     * Clears the stored post-login redirect path.
     */
    public function clearPostLoginRedirect(): void
    {
        unset($_SESSION[$this->postLoginRedirectKey]);
    }

    /**
     * Returns the currently selected 2FA method key for this surface.
     *
     * @return string Selected method key, or empty string when none is set.
     */
    public function selectedMethodKey(): string
    {
        return trim((string) ($_SESSION[$this->selectedMethodKeyKey] ?? ''));
    }

    /**
     * Stores the selected 2FA method key in the session.
     *
     * @param string $value Method key to persist; clears the key when empty.
     */
    public function storeSelectedMethodKey(string $value): void
    {
        $value = trim($value);
        // Empty method key input clears prior 2FA selection state.
        if ($value === '') {
            unset($_SESSION[$this->selectedMethodKeyKey]);
            return;
        }

        $_SESSION[$this->selectedMethodKeyKey] = $value;
    }

    /**
     * Clears the stored selected 2FA method key.
     */
    public function clearSelectedMethodKey(): void
    {
        unset($_SESSION[$this->selectedMethodKeyKey]);
    }

    /**
     * Returns true when the WebAuthn attempt for this surface is currently flagged as failed.
     *
     * @return bool True when WebAuthn failure flag is set.
     */
    public function webauthnFailed(): bool
    {
        return !empty($_SESSION[$this->webauthnFailedKey]);
    }

    /**
     * Sets the WebAuthn failure flag so the UI can offer fallback options.
     */
    public function markWebauthnFailed(): void
    {
        $_SESSION[$this->webauthnFailedKey] = true;
    }

    /**
     * Clears the WebAuthn failure flag.
     */
    public function clearWebauthnFailed(): void
    {
        unset($_SESSION[$this->webauthnFailedKey]);
    }

    /**
     * Returns the stored WebAuthn challenge binary string, or empty string when absent.
     *
     * @return string Binary challenge string stored during options generation.
     */
    public function webauthnChallenge(): string
    {
        return (string) ($_SESSION[$this->webauthnChallengeKey] ?? '');
    }

    /**
     * Stores a WebAuthn challenge binary string in the session for later verification.
     *
     * @param string $challenge Binary challenge string from the WebAuthn library.
     */
    public function storeWebauthnChallenge(string $challenge): void
    {
        // Empty challenge payload clears stale WebAuthn challenge state.
        if ($challenge === '') {
            unset($_SESSION[$this->webauthnChallengeKey]);
            return;
        }

        $_SESSION[$this->webauthnChallengeKey] = $challenge;
    }

    /**
     * Clears the stored WebAuthn challenge.
     */
    public function clearWebauthnChallenge(): void
    {
        unset($_SESSION[$this->webauthnChallengeKey]);
    }

    /**
     * Returns true when the method picker should be forced to show on next render.
     *
     * @return bool True when the force-picker flag is set.
     */
    public function forceMethodPicker(): bool
    {
        return !empty($_SESSION[$this->forceMethodPickerKey]);
    }

    /**
     * Sets or clears the force-method-picker flag.
     *
     * @param bool $force True to show the picker on next render; false to clear the flag.
     */
    public function setForceMethodPicker(bool $force): void
    {
        // True stores the force-picker flag for the next challenge render.
        if ($force) {
            $_SESSION[$this->forceMethodPickerKey] = true;
            return;
        }

        unset($_SESSION[$this->forceMethodPickerKey]);
    }

    /**
     * Returns the stored email address input from the email-code challenge form.
     *
     * @return string Trimmed email input, or empty string when absent.
     */
    public function emailInput(): string
    {
        return trim((string) ($_SESSION[$this->emailInputKey] ?? ''));
    }

    /**
     * Stores the submitted email address input for the email-code challenge step.
     *
     * @param string $value Email address string; clears the key when empty.
     */
    public function storeEmailInput(string $value): void
    {
        $value = trim($value);
        // Empty email input clears the persisted challenge email field.
        if ($value === '') {
            unset($_SESSION[$this->emailInputKey]);
            return;
        }

        $_SESSION[$this->emailInputKey] = $value;
    }

    /**
     * Clears the stored email address input.
     */
    public function clearEmailInput(): void
    {
        unset($_SESSION[$this->emailInputKey]);
    }

    /**
     * Clears all 2FA challenge UI state for this surface without touching the redirect key.
     *
     * Called at the start of a new challenge round or after successful verification.
     */
    public function clearTwoFactorState(): void
    {
        unset(
            $_SESSION[$this->selectedMethodKeyKey],
            $_SESSION[$this->webauthnFailedKey],
            $_SESSION[$this->webauthnChallengeKey],
            $_SESSION[$this->forceMethodPickerKey],
            $_SESSION[$this->emailInputKey]
        );
    }

    /**
     * Clears all login UI state for this surface including the post-login redirect.
     */
    public function clearAll(): void
    {
        $this->clearPostLoginRedirect();
        $this->clearTwoFactorState();
    }
}
