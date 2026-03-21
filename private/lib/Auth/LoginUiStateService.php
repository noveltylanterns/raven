<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared session-state helper for surface-specific login redirects and 2FA UI state.
 */
final class LoginUiStateService
{
    private string $postLoginRedirectKey;
    private string $selectedMethodKeyKey;
    private string $webauthnFailedKey;
    private string $webauthnChallengeKey;
    private string $forceMethodPickerKey;
    private string $emailInputKey;

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

    public static function forPanel(): self
    {
        return new self('_raven');
    }

    public static function forPublic(): self
    {
        return new self('_raven_public');
    }

    public function postLoginRedirect(): string
    {
        return trim((string) ($_SESSION[$this->postLoginRedirectKey] ?? ''));
    }

    public function storePostLoginRedirect(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            unset($_SESSION[$this->postLoginRedirectKey]);
            return;
        }

        $_SESSION[$this->postLoginRedirectKey] = $value;
    }

    public function consumePostLoginRedirect(): string
    {
        $value = $this->postLoginRedirect();
        unset($_SESSION[$this->postLoginRedirectKey]);
        return $value;
    }

    public function clearPostLoginRedirect(): void
    {
        unset($_SESSION[$this->postLoginRedirectKey]);
    }

    public function selectedMethodKey(): string
    {
        return trim((string) ($_SESSION[$this->selectedMethodKeyKey] ?? ''));
    }

    public function storeSelectedMethodKey(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            unset($_SESSION[$this->selectedMethodKeyKey]);
            return;
        }

        $_SESSION[$this->selectedMethodKeyKey] = $value;
    }

    public function clearSelectedMethodKey(): void
    {
        unset($_SESSION[$this->selectedMethodKeyKey]);
    }

    public function webauthnFailed(): bool
    {
        return !empty($_SESSION[$this->webauthnFailedKey]);
    }

    public function markWebauthnFailed(): void
    {
        $_SESSION[$this->webauthnFailedKey] = true;
    }

    public function clearWebauthnFailed(): void
    {
        unset($_SESSION[$this->webauthnFailedKey]);
    }

    public function webauthnChallenge(): string
    {
        return (string) ($_SESSION[$this->webauthnChallengeKey] ?? '');
    }

    public function storeWebauthnChallenge(string $challenge): void
    {
        if ($challenge === '') {
            unset($_SESSION[$this->webauthnChallengeKey]);
            return;
        }

        $_SESSION[$this->webauthnChallengeKey] = $challenge;
    }

    public function clearWebauthnChallenge(): void
    {
        unset($_SESSION[$this->webauthnChallengeKey]);
    }

    public function forceMethodPicker(): bool
    {
        return !empty($_SESSION[$this->forceMethodPickerKey]);
    }

    public function setForceMethodPicker(bool $force): void
    {
        if ($force) {
            $_SESSION[$this->forceMethodPickerKey] = true;
            return;
        }

        unset($_SESSION[$this->forceMethodPickerKey]);
    }

    public function emailInput(): string
    {
        return trim((string) ($_SESSION[$this->emailInputKey] ?? ''));
    }

    public function storeEmailInput(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            unset($_SESSION[$this->emailInputKey]);
            return;
        }

        $_SESSION[$this->emailInputKey] = $value;
    }

    public function clearEmailInput(): void
    {
        unset($_SESSION[$this->emailInputKey]);
    }

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

    public function clearAll(): void
    {
        $this->clearPostLoginRedirect();
        $this->clearTwoFactorState();
    }
}
