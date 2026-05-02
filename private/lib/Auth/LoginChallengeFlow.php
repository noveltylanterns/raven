<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginChallengeFlow.php
 * Login-only helper for pending 2FA method selection and WebAuthn fallback flow state.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\TwoFactorMethodKey;

/**
 * Shared helper for panel/public login-time interactive 2FA flow state.
 */
final class LoginChallengeFlow
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
        $pooledCodeMethods = $this->pooledCodeMethods($pendingMethods);
        $selectedMethod = $this->findByKey($pendingMethods, trim($selectedMethodKey));
        if ($selectedMethod === null) {
            $selectedMethod = $this->findByKey($pooledCodeMethods, trim($selectedMethodKey));
        }
        $webauthnMethods = $this->filterByType($pendingMethods, 'webauthn');
        $hasWebauthn = $webauthnMethods !== [];
        if ($selectedMethod === null && !$hasWebauthn && count($pooledCodeMethods) === 1) {
            $selectedMethod = $pooledCodeMethods[0];
        }
        $selectedMethodType = strtolower(trim((string) ($selectedMethod['type'] ?? '')));
        $canSwitchMethod = count($this->fallbackCodeMethods($pendingMethods, $selectedMethod)) > 0;

        $showMethodPicker = false;
        $showTotpForm = false;
        $showWebauthn = false;
        if ($forceMethodPicker && count($pendingMethods) > 1) {
            $showMethodPicker = true;
        } else {
            $showMethodPicker = !$hasWebauthn && count($pooledCodeMethods) > 1 && $selectedMethod === null;
            $showTotpForm = in_array($selectedMethodType, ['totp', 'recovery', 'email'], true);
            $showWebauthn = $hasWebauthn && (
                $selectedMethod === null
                || $selectedMethodType === 'webauthn'
            );
        }

        $fallbackMethods = $showWebauthn
            ? $this->fallbackCodeMethods($pendingMethods, $selectedMethod)
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
        $selectedMethod = $this->findByKey($pendingMethods, trim($selectedMethodKey));
        if ($selectedMethod === null) {
            $pooledCodeMethods = $this->pooledCodeMethods($pendingMethods);
            $selectedMethod = $this->findByKey($pooledCodeMethods, trim($selectedMethodKey));
        }

        if ($selectedMethod === null) {
            $codeMethods = $this->pooledCodeMethods($pendingMethods);
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
        $methodKey = trim($methodKey);
        $selected = $this->findByKey($pendingMethods, $methodKey);
        if ($selected !== null) {
            return $selected;
        }

        return $this->findByKey(
            $this->pooledCodeMethods($pendingMethods),
            $methodKey
        );
    }

    /**
     * Returns sorted code-method picker options for login challenge UI.
     *
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array<int, array<string, mixed>>
     */
    public function pickerCodeMethods(array $pendingMethods): array
    {
        return $this->pooledCodeMethods($pendingMethods);
    }

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array<string, mixed>|null
     */
    public function resolveWebauthnMethodForOptions(array $pendingMethods, string $selectedMethodKey): ?array
    {
        $selectedMethod = $this->findByKey($pendingMethods, trim($selectedMethodKey));
        if ($selectedMethod === null || strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            $pendingWebauthn = $this->filterByType($pendingMethods, 'webauthn');
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

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @param array<string, mixed>|null $selectedMethod
     * @return array<int, array<string, mixed>>
     */
    private function fallbackCodeMethods(array $pendingMethods, ?array $selectedMethod = null): array
    {
        $selectedKey = trim((string) ($selectedMethod['key'] ?? ''));
        $fallback = [];
        foreach ($this->pooledCodeMethods($pendingMethods) as $method) {
            $methodKey = trim((string) ($method['key'] ?? ''));
            if ($methodKey === '') {
                continue;
            }

            if ($selectedKey !== '' && $methodKey === $selectedKey) {
                continue;
            }

            $fallback[] = $method;
        }

        return $fallback;
    }

    /**
     * Finds one interactive method row by exact key.
     *
     * @param array<int, array<string, mixed>> $methods Candidate method rows.
     * @param string $methodKey Selected method key from login UI state.
     * @return array<string, mixed>|null Matching method row when present.
     */
    private function findByKey(array $methods, string $methodKey): ?array
    {
        $methodKey = trim($methodKey);
        if ($methodKey === '') {
            return null;
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (trim((string) ($method['key'] ?? '')) === $methodKey) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Filters interactive methods down to one exact method type.
     *
     * @param array<int, array<string, mixed>> $methods Candidate method rows.
     * @param string $type Exact method type to keep.
     * @return array<int, array<string, mixed>> Matching method rows.
     */
    private function filterByType(array $methods, string $type): array
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return [];
        }

        $filtered = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (strtolower(trim((string) ($method['type'] ?? ''))) !== $type) {
                continue;
            }

            $filtered[] = $method;
        }

        return $filtered;
    }

    /**
     * Builds the pooled login code-method list used by the challenge UI.
     *
     * @param array<int, array<string, mixed>> $methods Interactive method rows from auth state.
     * @return array<int, array<string, mixed>> Sorted code-method rows with pooled recovery/email entries.
     */
    private function pooledCodeMethods(array $methods): array
    {
        $pooled = [];
        $hasRecovery = false;
        $emailMap = [];

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type === 'totp') {
                $methodKey = trim((string) ($method['key'] ?? ''));
                if ($methodKey === '') {
                    continue;
                }

                $pooled[] = $method;
                continue;
            }

            if ($type === 'recovery') {
                $hasRecovery = true;
                continue;
            }

            if ($type !== 'email') {
                continue;
            }

            $email = strtolower(trim((string) ($method['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $emailMap[$email] = true;
        }

        if ($hasRecovery) {
            $pooled[] = [
                'type' => 'recovery',
                'key' => TwoFactorMethodKey::recoveryPool(),
                'label' => 'Enter Recovery Phrase',
            ];
        }

        if ($emailMap !== []) {
            $pooled[] = [
                'type' => 'email',
                'key' => TwoFactorMethodKey::emailPool(),
                'label' => 'Email Code',
                'emails' => array_keys($emailMap),
            ];
        }

        usort($pooled, static function (array $a, array $b): int {
            $labelA = strtolower(trim((string) ($a['label'] ?? '')));
            $labelB = strtolower(trim((string) ($b['label'] ?? '')));
            if ($labelA !== $labelB) {
                return $labelA <=> $labelB;
            }

            $typeA = strtolower(trim((string) ($a['type'] ?? '')));
            $typeB = strtolower(trim((string) ($b['type'] ?? '')));
            if ($typeA !== $typeB) {
                return $typeA <=> $typeB;
            }

            $keyA = strtolower(trim((string) ($a['key'] ?? '')));
            $keyB = strtolower(trim((string) ($b['key'] ?? '')));
            return $keyA <=> $keyB;
        });

        return $pooled;
    }
}
