<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared helper for panel login-time interactive 2FA flow state.
 */
final class LoginTwoFactorFlowService
{
    /**
     * @param array<int, array<string, mixed>> $interactiveMethods
     */
    public function preferredMethodKeyForChallenge(array $interactiveMethods): ?string
    {
        foreach ($interactiveMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn') {
                continue;
            }

            $methodKey = trim((string) ($method['key'] ?? ''));
            if ($methodKey !== '') {
                return $methodKey;
            }
        }

        if (count($interactiveMethods) === 1) {
            $singleKey = trim((string) ($interactiveMethods[0]['key'] ?? ''));
            if ($singleKey !== '') {
                return $singleKey;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array{
     *   selected_method: array<string, mixed>|null,
     *   selected_method_type: string,
     *   show_method_picker: bool,
     *   show_totp_form: bool,
     *   show_webauthn_prompt: bool,
     *   can_switch_method: bool,
     *   fallback_methods: array<int, array<string, mixed>>,
     *   has_webauthn: bool,
     *   webauthn_failed: bool
     * }
     */
    public function challengeViewState(
        array $pendingMethods,
        string $selectedMethodKey,
        bool $webauthnFailed,
        bool $forceMethodPicker = false
    ): array
    {
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, trim($selectedMethodKey));
        $codeMethods = TwoFactorChallengeHelper::codeMethods($pendingMethods);
        $webauthnMethods = TwoFactorChallengeHelper::filterByType($pendingMethods, 'webauthn');
        $hasWebauthn = $webauthnMethods !== [];
        $selectedMethodType = strtolower(trim((string) ($selectedMethod['type'] ?? '')));
        $canSwitchMethod = count(TwoFactorChallengeHelper::fallbackMethods($pendingMethods, $selectedMethod)) > 0;

        $showMethodPicker = false;
        $showTotpForm = false;
        $showWebauthn = false;
        if ($forceMethodPicker && count($pendingMethods) > 1) {
            $showMethodPicker = true;
        } else {
            $showMethodPicker = !$hasWebauthn && count($codeMethods) > 1 && $selectedMethod === null;
            $showTotpForm = in_array($selectedMethodType, ['totp', 'recovery', 'email'], true);
            $showWebauthn = $hasWebauthn && (
                $selectedMethod === null
                || $selectedMethodType === 'webauthn'
            );
        }

        $fallbackMethods = $showWebauthn
            ? TwoFactorChallengeHelper::fallbackMethods($pendingMethods, $selectedMethod)
            : [];

        return [
            'selected_method' => $selectedMethod,
            'selected_method_type' => $selectedMethodType,
            'show_method_picker' => $showMethodPicker,
            'show_totp_form' => $showTotpForm,
            'show_webauthn_prompt' => $showWebauthn,
            'can_switch_method' => $canSwitchMethod,
            'fallback_methods' => $fallbackMethods,
            'has_webauthn' => $hasWebauthn,
            'webauthn_failed' => $webauthnFailed,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array{method: array<string, mixed>|null, selected_method_key: string}
     */
    public function resolveCodeMethodForVerification(array $pendingMethods, string $selectedMethodKey): array
    {
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, trim($selectedMethodKey));
        if ($selectedMethod === null) {
            $codeMethods = TwoFactorChallengeHelper::codeMethods($pendingMethods);
            if (count($codeMethods) === 1) {
                $selectedMethod = $codeMethods[0];
                $selectedMethodKey = (string) ($selectedMethod['key'] ?? '');
            }
        }

        return [
            'method' => $selectedMethod,
            'selected_method_key' => trim($selectedMethodKey),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     */
    public function resolveSelectedMethod(array $pendingMethods, string $methodKey): ?array
    {
        return TwoFactorChallengeHelper::findByKey($pendingMethods, trim($methodKey));
    }

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array<string, mixed>|null
     */
    public function resolveWebauthnMethodForOptions(array $pendingMethods, string $selectedMethodKey): ?array
    {
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, trim($selectedMethodKey));
        if ($selectedMethod === null || strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            $pendingWebauthn = TwoFactorChallengeHelper::filterByType($pendingMethods, 'webauthn');
            if ($pendingWebauthn !== []) {
                $selectedMethod = $pendingWebauthn[0];
            }
        }

        if ($selectedMethod === null || strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            return null;
        }

        return $selectedMethod;
    }

    /**
     * @param array<int, array<string, mixed>> $storedMethods
     * @return array<string, mixed>|null
     */
    public function resolveRegisteredWebauthnMethod(array $storedMethods, string $selectedCredentialIdB64): ?array
    {
        $selectedCredentialIdB64 = trim($selectedCredentialIdB64);
        if ($selectedCredentialIdB64 === '') {
            return null;
        }

        foreach ($storedMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn'
                || strtolower(trim((string) ($method['status'] ?? ''))) !== 'confirmed'
            ) {
                continue;
            }

            $credentialIdB64 = trim((string) ($method['credential_id'] ?? ''));
            $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
            if ($credentialIdB64 === '' || $credentialPublicKey === '') {
                continue;
            }

            if ($credentialIdB64 !== $selectedCredentialIdB64) {
                continue;
            }

            return $method;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $method
     */
    public function selectedWebauthnCredentialId(array $method): string
    {
        $credentialId = trim((string) ($method['credential_id'] ?? ''));
        if ($credentialId !== '') {
            return $credentialId;
        }

        return TwoFactorMethodKey::extractWebauthnCredentialId(trim((string) ($method['key'] ?? '')));
    }
}
