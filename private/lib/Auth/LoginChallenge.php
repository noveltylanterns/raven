<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginChallenge.php
 * Interactive 2FA challenge workflow for panel and public login entrypoints.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Auth\LoginEmail;
use Raven\Lib\Auth\LoginUiState;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\WebAuthn;

/**
 * Shared interactive 2FA challenge workflow for panel and public login entrypoints.
 *
 * Owns the full challenge lifecycle: building view state, verifying submitted codes
 * (TOTP, recovery, and email), WebAuthn options generation and response verification,
 * and 2FA method selection. All flow-state helpers and WebAuthn context preparation
 * are encapsulated as private methods so callers only interact with the five workflow
 * entry points and the static preferredMethodKeyForChallenge helper.
 */
final class LoginChallenge
{
    private Config $config;
    private InputSanitizer $input;
    private LoginEmail $loginEmail;

    /**
     * Prepares the challenge workflow with its configuration and delivery dependencies.
     *
     * @param Config         $config     Shared configuration service for site name, domain, and mail settings.
     * @param InputSanitizer $input      Shared payload sanitizer for challenge form fields.
     * @param LoginEmail     $loginEmail Shared email challenge session manager and delivery helper.
     */
    public function __construct(Config $config, InputSanitizer $input, LoginEmail $loginEmail)
    {
        $this->config = $config;
        $this->input = $input;
        $this->loginEmail = $loginEmail;
    }

    // -------------------------------------------------------------------------
    // Workflow entry points (called by Panel\AuthController / Public\AuthController)
    // -------------------------------------------------------------------------

    /**
     * Builds the complete view-state payload for the 2FA challenge page.
     *
     * Reads pending session state, resolves the selected method, and returns all
     * flags and data the challenge template needs to render correctly.
     *
     * @param AuthService  $auth    Shared authentication service.
     * @param LoginUiState $uiState Surface-specific login UI state.
     * @return array<string, mixed> View state payload, or an error array when the session is expired.
     */
    public function buildViewState(AuthService $auth, LoginUiState $uiState): array
    {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return $challenge;
        }

        $pendingMethods = is_array($challenge['pending_methods'] ?? null)
            ? $challenge['pending_methods']
            : [];
        $selectedMethodKey = $uiState->selectedMethodKey();
        $webauthnFailed = $uiState->webauthnFailed();
        $forceMethodPicker = $uiState->forceMethodPicker();
        $flowState = $this->challengeViewState(
            $pendingMethods,
            $selectedMethodKey,
            $webauthnFailed,
            $forceMethodPicker
        );

        $selectedMethodType = (string) ($flowState['selected_method_type'] ?? '');
        $selectedEmailInput = $uiState->emailInput();
        $emailCodeTargetMasked = '';
        if ($selectedMethodType === 'email' && $selectedEmailInput !== '') {
            $emailCodeTargetMasked = $this->loginEmail->maskEmail($selectedEmailInput);
        }

        if (!(bool) ($flowState['show_method_picker'] ?? false)) {
            $uiState->setForceMethodPicker(false);
        }

        return [
            'ok' => true,
            'status' => 'ready',
            'pending_user_id' => (int) ($challenge['pending_user_id'] ?? 0),
            'twoFactorMethods' => $this->pickerCodeMethods($pendingMethods),
            'showMethodPicker' => (bool) ($flowState['show_method_picker'] ?? false),
            'showTotpForm' => (bool) ($flowState['show_totp_form'] ?? false),
            'showWebauthnPrompt' => (bool) ($flowState['show_webauthn_prompt'] ?? false),
            'webauthnFailed' => (bool) ($flowState['webauthn_failed'] ?? $webauthnFailed),
            'fallbackMethods' => is_array($flowState['fallback_methods'] ?? null) ? $flowState['fallback_methods'] : [],
            'selectedMethod' => is_array($flowState['selected_method'] ?? null) ? $flowState['selected_method'] : null,
            'selectedMethodType' => $selectedMethodType,
            'canSwitchMethod' => (bool) ($flowState['can_switch_method'] ?? false),
            'webauthnMethodKey' => (string) (self::preferredMethodKeyForChallenge($pendingMethods) ?? ''),
            'emailCodeTargetMasked' => $emailCodeTargetMasked,
            'selectedEmailInput' => $selectedEmailInput,
        ];
    }

    /**
     * Verifies one submitted 2FA code (TOTP, recovery, or email) for the pending session.
     *
     * Dispatches to the correct verification path based on the resolved method type.
     * For email codes, issues and sends a code when the user requests one, or verifies
     * a previously issued code when one is submitted.
     *
     * @param AuthService  $auth    Shared authentication service.
     * @param LoginUiState $uiState Surface-specific login UI state.
     * @param array<string, mixed> $post Submitted challenge form payload.
     * @return array<string, mixed> Result payload with `ok`, `status`, and optional `message`.
     */
    public function verifyCodeChallenge(AuthService $auth, LoginUiState $uiState, array $post): array
    {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return $challenge;
        }

        $pendingMethods = is_array($challenge['pending_methods'] ?? null)
            ? $challenge['pending_methods']
            : [];
        $selection = $this->resolveCodeMethodForVerification(
            $pendingMethods,
            $uiState->selectedMethodKey()
        );
        $selectedMethod = $selection['method'] ?? null;
        $selectedMethodKey = trim((string) ($selection['selected_method_key'] ?? ''));
        if (is_array($selectedMethod)) {
            $uiState->storeSelectedMethodKey($selectedMethodKey);
        }

        if (!is_array($selectedMethod)) {
            return [
                'ok' => false,
                'status' => 'choose_method',
                'message' => 'Choose a verification method first.',
            ];
        }

        $selectedMethodType = strtolower(trim((string) ($selectedMethod['type'] ?? '')));
        $verificationValue = $this->input->text(
            (string) ($post['verification_code'] ?? $post['totp_code'] ?? ''),
            512
        );

        if ($selectedMethodType === 'totp') {
            $totpCode = preg_replace('/\D+/', '', $verificationValue) ?? '';
            if ($totpCode === '') {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Verification code is required.',
                ];
            }

            if (!$auth->verifyPendingTotpCode($totpCode)) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Invalid verification code.',
                ];
            }
        } elseif ($selectedMethodType === 'recovery') {
            if (trim($verificationValue) === '') {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Recovery phrase is required.',
                ];
            }

            $selectedRecoveryKey = (string) ($selectedMethod['key'] ?? '');
            if (Login2fa::isRecoveryPool($selectedRecoveryKey)) {
                $selectedRecoveryKey = '';
            }

            if (!$auth->verifyPendingRecoveryCode($verificationValue, $selectedRecoveryKey)) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Invalid recovery phrase.',
                ];
            }
        } elseif ($selectedMethodType === 'email') {
            $emailInput = trim((string) $this->input->text($post['verification_email'] ?? null, 254));
            $uiState->storeEmailInput($emailInput);
            $emailCode = preg_replace('/\D+/', '', $verificationValue) ?? '';
            $emailAction = strtolower(trim((string) ($post['email_action'] ?? '')));
            $sendRequested = $emailAction === 'send_code' || ($emailAction === '' && $emailCode === '');
            $verifyRequested = $emailAction === 'verify_code' || ($emailAction === '' && $emailCode !== '');

            if ($sendRequested) {
                $selectedEmailKey = (string) ($selectedMethod['key'] ?? '');
                $issueResult = $auth->issuePendingEmailCodeChallenge($selectedEmailKey, $emailInput);
                if ((bool) ($issueResult['ok'] ?? false) && (bool) ($issueResult['sent'] ?? false)) {
                    $emailCodeTarget = (string) ($issueResult['email'] ?? '');
                    $delivery = $this->loginEmail->sendCode(
                        $emailCodeTarget,
                        (string) ($issueResult['code'] ?? ''),
                        (string) $this->config->get('site.name', 'Raven CMS'),
                        (string) $this->config->get('site.domain', ''),
                        (string) $this->config->get('mail.sender_address', ''),
                        (string) $this->config->get('mail.sender_name', 'Postmaster'),
                        (string) $this->config->get('mail.agent', 'php_mail')
                    );

                    if (!(bool) ($delivery['ok'] ?? false)) {
                        $auth->clearPendingEmailCodeChallenge((string) ($issueResult['method_key'] ?? ''));
                    }
                }

                return [
                    'ok' => true,
                    'status' => 'email_sent',
                    'message' => 'Check your email. If it matches what we have on file, a code has been dispatched to your inbox.',
                ];
            }

            if (!$verifyRequested || $emailCode === '') {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Email code is required.',
                ];
            }

            if (!$auth->verifyPendingEmailCode($emailCode, (string) ($selectedMethod['key'] ?? ''), $emailInput)) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'Invalid or expired email code.',
                ];
            }
        } else {
            return [
                'ok' => false,
                'status' => 'unsupported',
                'message' => 'This verification method is not supported in this login flow.',
            ];
        }

        $uiState->clearTwoFactorState();
        $auth->markTwoFactorVerified((int) ($challenge['pending_user_id'] ?? 0));

        return [
            'ok' => true,
            'status' => 'verified',
            'message' => '',
        ];
    }

    /**
     * Handles a method-selection submission from the 2FA challenge picker.
     *
     * Updates UI state to reflect the newly selected method and clears any
     * stale per-method state (e.g. WebAuthn failure flag when switching away).
     *
     * @param AuthService  $auth    Shared authentication service.
     * @param LoginUiState $uiState Surface-specific login UI state.
     * @param array<string, mixed> $post Submitted method-selection payload.
     * @return array<string, mixed> Result payload with `ok`, `status`, and optional `message`.
     */
    public function selectMethod(AuthService $auth, LoginUiState $uiState, array $post): array
    {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return $challenge;
        }

        $pendingMethods = is_array($challenge['pending_methods'] ?? null)
            ? $challenge['pending_methods']
            : [];
        $showMethodPicker = (string) ($post['show_method_picker'] ?? '') === '1';
        if ($showMethodPicker) {
            $uiState->clearTwoFactorState();
            if (count($pendingMethods) > 1) {
                $uiState->setForceMethodPicker(true);
            }

            return [
                'ok' => true,
                'status' => 'show_picker',
                'message' => '',
            ];
        }

        $methodKey = $this->input->text((string) ($post['method_key'] ?? ''), 200);
        $selectedMethod = $this->resolveSelectedMethod($pendingMethods, $methodKey);
        if ($selectedMethod === null) {
            return [
                'ok' => false,
                'status' => 'invalid_method',
                'message' => 'Selected verification method is invalid.',
            ];
        }

        $uiState->setForceMethodPicker(false);
        $uiState->storeSelectedMethodKey((string) ($selectedMethod['key'] ?? ''));
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            $uiState->clearWebauthnFailed();
        }
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'email') {
            $uiState->clearEmailInput();
        }

        return [
            'ok' => true,
            'status' => 'selected',
            'message' => '',
        ];
    }

    /**
     * Generates WebAuthn assertion options for the pending session and selected credential.
     *
     * Validates the credential against stored user preferences, builds the WebAuthn
     * options object, and stores the challenge binary in UI state for later verification.
     *
     * @param AuthService  $auth    Shared authentication service.
     * @param LoginUiState $uiState Surface-specific login UI state.
     * @param array<string, mixed> $server Server context for WebAuthn origin resolution.
     * @return array<string, mixed> Result payload with `ok`, `status`, `http_status`, and optional `payload`.
     */
    public function webauthnOptions(AuthService $auth, LoginUiState $uiState, array $server): array
    {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => 'expired',
                'message' => (string) ($challenge['message'] ?? 'Login session expired.'),
                'http_status' => 401,
            ];
        }

        $pendingMethods = is_array($challenge['pending_methods'] ?? null)
            ? $challenge['pending_methods']
            : [];
        $context = $this->prepareWebauthnOptionsContext(
            $auth,
            (int) ($challenge['pending_user_id'] ?? 0),
            $pendingMethods,
            $uiState->selectedMethodKey()
        );
        if (!(bool) ($context['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => (string) ($context['message'] ?? 'Failed to initialize WebAuthn challenge.'),
                'http_status' => (int) ($context['status'] ?? 400),
            ];
        }

        $selectedCredentialIdB64 = (string) ($context['credential_id_b64'] ?? '');
        $credentialIdBinary = base64_decode($selectedCredentialIdB64, true);
        if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'Selected security key is invalid.',
                'http_status' => 400,
            ];
        }

        $uiState->storeSelectedMethodKey((string) ($context['selected_method_key'] ?? ''));

        $webAuthn = WebAuthn::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $server
        );
        if ($webAuthn === null) {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'WebAuthn runtime is unavailable.',
                'http_status' => 500,
            ];
        }

        try {
            $options = $webAuthn->getGetArgs(
                [$credentialIdBinary],
                60,
                true,
                true,
                true,
                true,
                true,
                (string) ($context['require_user_verification'] ?? 'discouraged')
            );
            $uiState->storeWebauthnChallenge($webAuthn->getChallenge()->getBinaryString());
            $uiState->storeSelectedMethodKey(Login2fa::forWebauthnCredentialId($selectedCredentialIdB64));

            return [
                'ok' => true,
                'status' => 'ready',
                'http_status' => 200,
                'payload' => ['ok' => true, 'options' => $options],
            ];
        } catch (\Throwable) {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'Failed to initialize WebAuthn challenge.',
                'http_status' => 500,
            ];
        }
    }

    /**
     * Verifies a WebAuthn assertion response for the pending session.
     *
     * Validates the credential payload against stored user preferences, runs the
     * WebAuthn library verification, and on success updates the signature counter
     * and marks the session as 2FA-verified.
     *
     * @param AuthService  $auth    Shared authentication service.
     * @param LoginUiState $uiState Surface-specific login UI state.
     * @param array<string, mixed> $post   Submitted WebAuthn assertion payload.
     * @param array<string, mixed> $server Server context for WebAuthn origin resolution.
     * @return array<string, mixed> Result payload with `ok`, `status`, and `http_status`.
     */
    public function verifyWebauthn(
        AuthService $auth,
        LoginUiState $uiState,
        array $post,
        array $server
    ): array {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => 'expired',
                'message' => (string) ($challenge['message'] ?? 'Login session expired.'),
                'http_status' => 401,
            ];
        }

        $challengeBinary = $uiState->webauthnChallenge();
        if ($challengeBinary === '') {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'WebAuthn challenge is missing.',
                'http_status' => 400,
            ];
        }

        $context = $this->prepareWebauthnVerifyContext(
            $auth,
            (int) ($challenge['pending_user_id'] ?? 0),
            $post
        );
        if (!(bool) ($context['ok'] ?? false)) {
            if (!empty($context['mark_webauthn_failed'])) {
                $uiState->markWebauthnFailed();
            }

            return [
                'ok' => false,
                'status' => 'error',
                'message' => (string) ($context['message'] ?? 'Invalid WebAuthn payload.'),
                'http_status' => (int) ($context['status'] ?? 400),
            ];
        }

        $webAuthn = WebAuthn::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $server
        );
        if ($webAuthn === null) {
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'WebAuthn runtime is unavailable.',
                'http_status' => 500,
            ];
        }

        try {
            $webAuthn->processGet(
                (string) ($context['client_data_json'] ?? ''),
                (string) ($context['authenticator_data'] ?? ''),
                (string) ($context['signature'] ?? ''),
                (string) ($context['credential_public_key'] ?? ''),
                $challengeBinary,
                (int) ($context['previous_signature_counter'] ?? 0),
                false
            );

            $signatureCounter = $webAuthn->getSignatureCounter();
            if (is_int($signatureCounter) && $signatureCounter >= 0) {
                $auth->updateWebauthnSignatureCounter(
                    (int) ($challenge['pending_user_id'] ?? 0),
                    (string) ($context['credential_id_b64'] ?? ''),
                    $signatureCounter
                );
            }

            $uiState->clearTwoFactorState();
            $auth->markTwoFactorVerified((int) ($challenge['pending_user_id'] ?? 0));

            return [
                'ok' => true,
                'status' => 'verified',
                'http_status' => 200,
            ];
        } catch (\Throwable) {
            $uiState->markWebauthnFailed();
            return [
                'ok' => false,
                'status' => 'error',
                'message' => 'Security key verification failed. You can retry or use another method.',
                'http_status' => 400,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Static helpers (callable without an instance)
    // -------------------------------------------------------------------------

    /**
     * Returns the preferred method key to auto-select when entering the challenge screen.
     *
     * Prefers WebAuthn when present (best UX for hardware key holders); falls through
     * to auto-selecting the only available method when exactly one is configured.
     *
     * @param array<int, array<string, mixed>> $interactiveMethods Interactive 2FA method rows for the pending session.
     * @return string|null Method key to pre-select, or null when the user must choose manually.
     */
    public static function preferredMethodKeyForChallenge(array $interactiveMethods): ?string
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

    // -------------------------------------------------------------------------
    // Private flow-state helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the full challenge view state for the 2FA challenge template.
     *
     * Resolves which forms and prompts to show based on available methods, the currently
     * selected method, WebAuthn failure state, and whether the picker is forced open.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows from the pending session.
     * @param string $selectedMethodKey Currently stored selected method key.
     * @param bool   $webauthnFailed    True when a previous WebAuthn attempt failed this session.
     * @param bool   $forceMethodPicker True when the user explicitly requested the method picker.
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
    private function challengeViewState(
        array $pendingMethods,
        string $selectedMethodKey,
        bool $webauthnFailed,
        bool $forceMethodPicker = false
    ): array {
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
     * Resolves the selected method and its key for code-verification submissions.
     *
     * Falls through to auto-select when exactly one code method is available and
     * no explicit selection has been stored, so single-method users skip the picker.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @param string $selectedMethodKey Currently stored selected method key.
     * @return array{method: array<string, mixed>|null, selected_method_key: string}
     */
    private function resolveCodeMethodForVerification(array $pendingMethods, string $selectedMethodKey): array
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
     * Resolves a method row from the pending set by direct key or pooled code-method key.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @param string $methodKey Method key from the method-selection form.
     * @return array<string, mixed>|null Matching method row, or null when the key is invalid.
     */
    private function resolveSelectedMethod(array $pendingMethods, string $methodKey): ?array
    {
        $methodKey = trim($methodKey);
        $selected = $this->findByKey($pendingMethods, $methodKey);
        if ($selected !== null) {
            return $selected;
        }

        return $this->findByKey($this->pooledCodeMethods($pendingMethods), $methodKey);
    }

    /**
     * Returns the sorted code-method picker options for the challenge UI.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @return array<int, array<string, mixed>> Pooled and sorted code-method rows.
     */
    private function pickerCodeMethods(array $pendingMethods): array
    {
        return $this->pooledCodeMethods($pendingMethods);
    }

    /**
     * Resolves the first WebAuthn method row suitable for options generation.
     *
     * Falls back to the first WebAuthn method in the pending set when the selected key
     * does not resolve to a WebAuthn method, so the options endpoint is always usable.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @param string $selectedMethodKey Currently stored selected method key.
     * @return array<string, mixed>|null WebAuthn method row, or null when none is available.
     */
    private function resolveWebauthnMethodForOptions(array $pendingMethods, string $selectedMethodKey): ?array
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
     * Resolves a confirmed WebAuthn method row from stored preferences by credential id.
     *
     * Used during options generation to confirm the credential is actually registered
     * before issuing a WebAuthn challenge for it.
     *
     * @param array<int, array<string, mixed>> $storedMethods 2FA method rows from user preferences.
     * @param string $selectedCredentialIdB64 Base64-encoded credential id to look up.
     * @return array<string, mixed>|null Matching confirmed WebAuthn method row, or null.
     */
    private function resolveRegisteredWebauthnMethod(array $storedMethods, string $selectedCredentialIdB64): ?array
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
     * Extracts the credential id from a WebAuthn method row.
     *
     * Prefers the explicit `credential_id` field; falls back to extracting the id from
     * the method key when the field is absent (legacy rows stored before explicit field).
     *
     * @param array<string, mixed> $method WebAuthn method row.
     * @return string Base64-encoded credential id, or empty string when absent.
     */
    private function selectedWebauthnCredentialId(array $method): string
    {
        $credentialId = trim((string) ($method['credential_id'] ?? ''));
        if ($credentialId !== '') {
            return $credentialId;
        }

        return Login2fa::extractWebauthnCredentialId(trim((string) ($method['key'] ?? '')));
    }

    /**
     * Returns code methods available as fallback when WebAuthn is the primary prompt.
     *
     * Excludes the currently selected method so the fallback list only shows alternatives.
     *
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @param array<string, mixed>|null $selectedMethod Currently selected method row.
     * @return array<int, array<string, mixed>> Fallback code-method rows.
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
     * Finds one method row by exact key from a candidate set.
     *
     * @param array<int, array<string, mixed>> $methods Candidate method rows.
     * @param string $methodKey Method key to match.
     * @return array<string, mixed>|null Matching method row, or null when absent.
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
     * Filters an interactive method list down to one exact method type.
     *
     * @param array<int, array<string, mixed>> $methods Candidate method rows.
     * @param string $type Exact method type to keep (e.g. `webauthn`, `totp`).
     * @return array<int, array<string, mixed>> Filtered method rows.
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
     * Collapses individual email methods into one pool entry and recovery methods into
     * one recovery pool entry. TOTP methods are kept as-is. Sorts the result by label
     * for stable picker ordering across method combinations.
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
                'key' => Login2fa::recoveryPool(),
                'label' => 'Enter Recovery Phrase',
            ];
        }

        if ($emailMap !== []) {
            $pooled[] = [
                'type' => 'email',
                'key' => Login2fa::emailPool(),
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

    // -------------------------------------------------------------------------
    // Private WebAuthn context helpers
    // -------------------------------------------------------------------------

    /**
     * Validates and prepares the context needed for WebAuthn options generation.
     *
     * Resolves the target WebAuthn method, confirms the credential is registered in the
     * user's stored preferences, and returns the credential id and user-verification
     * requirement for the WebAuthn library call.
     *
     * @param AuthService $auth           Shared authentication service.
     * @param int         $userId         Pending 2FA user id.
     * @param array<int, array<string, mixed>> $pendingMethods Interactive method rows.
     * @param string      $selectedMethodKey Currently stored selected method key.
     * @return array{
     *   ok: bool,
     *   status: int,
     *   message?: string,
     *   selected_method_key?: string,
     *   credential_id_b64?: string,
     *   require_user_verification?: string
     * }
     */
    private function prepareWebauthnOptionsContext(
        AuthService $auth,
        int $userId,
        array $pendingMethods,
        string $selectedMethodKey
    ): array {
        $selectedMethod = $this->resolveWebauthnMethodForOptions($pendingMethods, trim($selectedMethodKey));
        if (!is_array($selectedMethod)) {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Choose a security key method first.',
            ];
        }

        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Choose a security key method first.',
            ];
        }

        $selectedCredentialIdB64 = $this->selectedWebauthnCredentialId($selectedMethod);
        if ($selectedCredentialIdB64 === '') {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Selected security key is invalid.',
            ];
        }

        $preferences = $auth->userPreferences($userId);
        if (!is_array($preferences)) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Unable to load user preferences.',
            ];
        }

        $resolvedMethod = $this->resolveRegisteredWebauthnMethod(
            (array) ($preferences['two_factor'] ?? []),
            $selectedCredentialIdB64
        );
        if (!is_array($resolvedMethod)) {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'No WebAuthn methods are configured.',
            ];
        }

        $credentialIdBinary = base64_decode($selectedCredentialIdB64, true);
        if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Selected security key is invalid.',
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'selected_method_key' => trim((string) ($selectedMethod['key'] ?? '')),
            'credential_id_b64' => $selectedCredentialIdB64,
            'require_user_verification' => (bool) ($resolvedMethod['require_uv'] ?? false)
                ? 'required'
                : 'discouraged',
        ];
    }

    /**
     * Validates and extracts the WebAuthn assertion payload for verification.
     *
     * Decodes the base64 fields from the client response, looks up the matching
     * credential in stored user preferences, and checks user-verification requirements.
     *
     * @param AuthService $auth   Shared authentication service.
     * @param int         $userId Pending 2FA user id.
     * @param array<string, mixed> $post Submitted WebAuthn assertion payload.
     * @return array{
     *   ok: bool,
     *   status: int,
     *   message?: string,
     *   mark_webauthn_failed?: bool,
     *   credential_id_b64?: string,
     *   client_data_json?: string,
     *   authenticator_data?: string,
     *   signature?: string,
     *   credential_public_key?: string,
     *   previous_signature_counter?: int
     * }
     */
    private function prepareWebauthnVerifyContext(AuthService $auth, int $userId, array $post): array
    {
        $credentialIdBinary = base64_decode((string) ($post['id'] ?? ''), true);
        $clientDataJSON = base64_decode((string) ($post['clientDataJSON'] ?? ''), true);
        $authenticatorData = base64_decode((string) ($post['authenticatorData'] ?? ''), true);
        $signature = base64_decode((string) ($post['signature'] ?? ''), true);
        $credentialIdB64 = is_string($credentialIdBinary) ? base64_encode($credentialIdBinary) : '';

        if (
            !is_string($credentialIdBinary) || $credentialIdBinary === ''
            || !is_string($clientDataJSON) || $clientDataJSON === ''
            || !is_string($authenticatorData) || $authenticatorData === ''
            || !is_string($signature) || $signature === ''
        ) {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Invalid WebAuthn payload.',
            ];
        }

        $preferences = $auth->userPreferences($userId);
        if (!is_array($preferences)) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Unable to load user preferences.',
            ];
        }

        $credentialPublicKey = '';
        $requiresUserVerification = false;
        $previousSignatureCounter = 0;
        foreach ((array) ($preferences['two_factor'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn'
                || strtolower(trim((string) ($method['status'] ?? ''))) !== 'confirmed'
            ) {
                continue;
            }

            if (trim((string) ($method['credential_id'] ?? '')) !== $credentialIdB64) {
                continue;
            }

            $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
            $counter = (int) ($method['signature_counter'] ?? 0);
            $previousSignatureCounter = $counter >= 0 ? $counter : 0;
            $requiresUserVerification = (bool) ($method['require_uv'] ?? false);
            break;
        }

        if ($credentialPublicKey === '') {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Security key is not registered for this account.',
                'mark_webauthn_failed' => true,
            ];
        }

        if ($requiresUserVerification && !WebAuthn::authenticatorDataHasUserVerification($authenticatorData)) {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'This security key requires PIN/biometric verification.',
                'mark_webauthn_failed' => true,
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'credential_id_b64' => $credentialIdB64,
            'client_data_json' => $clientDataJSON,
            'authenticator_data' => $authenticatorData,
            'signature' => $signature,
            'credential_public_key' => $credentialPublicKey,
            'previous_signature_counter' => $previousSignatureCounter,
        ];
    }

    // -------------------------------------------------------------------------
    // Private session helpers
    // -------------------------------------------------------------------------

    /**
     * Validates the pending 2FA challenge session and returns session state on success.
     *
     * Returns an error array when the pending user id is missing or does not match the
     * logged-in user, which indicates the session expired or was tampered with.
     *
     * @param AuthService $auth Shared authentication service.
     * @return array<string, mixed> Success payload with `ok`, `pending_user_id`, and `pending_methods`,
     *                              or an error payload with `ok: false` and a `message`.
     */
    private function requirePendingChallenge(AuthService $auth): array
    {
        $userId = $auth->userId();
        $pendingUserId = $auth->pendingTwoFactorUserId();
        if ($userId === null || $pendingUserId === null || $userId !== $pendingUserId) {
            return [
                'ok' => false,
                'status' => 'expired',
                'message' => 'Your login session expired. Please log in again.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'ready',
            'pending_user_id' => $pendingUserId,
            'pending_methods' => $auth->pendingTwoFactorMethods(),
        ];
    }
}
