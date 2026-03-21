<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Auth\AuthService;
use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\LoginTwoFactorFlowService;
use Raven\Lib\Security\TwoFactorMethodKey;
use Raven\Lib\Security\WebAuthnService;

/**
 * Shared interactive 2FA workflow for panel and public login entrypoints.
 */
final class LoginChallengeWorkflowService
{
    private Config $config;
    private InputSanitizer $input;
    private LoginTwoFactorFlowService $twoFactorFlowService;
    private LoginWebAuthnChallengeService $loginWebAuthnChallengeService;
    private TwoFactorEmailDeliveryService $twoFactorEmailDeliveryService;

    public function __construct(
        Config $config,
        InputSanitizer $input,
        LoginTwoFactorFlowService $twoFactorFlowService,
        LoginWebAuthnChallengeService $loginWebAuthnChallengeService,
        TwoFactorEmailDeliveryService $twoFactorEmailDeliveryService
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->twoFactorFlowService = $twoFactorFlowService;
        $this->loginWebAuthnChallengeService = $loginWebAuthnChallengeService;
        $this->twoFactorEmailDeliveryService = $twoFactorEmailDeliveryService;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildViewState(AuthService $auth, LoginUiStateService $uiState): array
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
        $flowState = $this->twoFactorFlowService->challengeViewState(
            $pendingMethods,
            $selectedMethodKey,
            $webauthnFailed,
            $forceMethodPicker
        );

        $selectedMethodType = (string) ($flowState['selected_method_type'] ?? '');
        $selectedEmailInput = $uiState->emailInput();
        $emailCodeTargetMasked = '';
        if ($selectedMethodType === 'email' && $selectedEmailInput !== '') {
            $emailCodeTargetMasked = $this->twoFactorEmailDeliveryService->maskEmail($selectedEmailInput);
        }

        if (!(bool) ($flowState['show_method_picker'] ?? false)) {
            $uiState->setForceMethodPicker(false);
        }

        return [
            'ok' => true,
            'status' => 'ready',
            'pending_user_id' => (int) ($challenge['pending_user_id'] ?? 0),
            'twoFactorMethods' => $this->twoFactorFlowService->pickerCodeMethods($pendingMethods),
            'showMethodPicker' => (bool) ($flowState['show_method_picker'] ?? false),
            'showTotpForm' => (bool) ($flowState['show_totp_form'] ?? false),
            'showWebauthnPrompt' => (bool) ($flowState['show_webauthn_prompt'] ?? false),
            'webauthnFailed' => (bool) ($flowState['webauthn_failed'] ?? $webauthnFailed),
            'fallbackMethods' => is_array($flowState['fallback_methods'] ?? null) ? $flowState['fallback_methods'] : [],
            'selectedMethod' => is_array($flowState['selected_method'] ?? null) ? $flowState['selected_method'] : null,
            'selectedMethodType' => $selectedMethodType,
            'canSwitchMethod' => (bool) ($flowState['can_switch_method'] ?? false),
            'webauthnMethodKey' => (string) ($this->twoFactorFlowService->preferredMethodKeyForChallenge($pendingMethods) ?? ''),
            'emailCodeTargetMasked' => $emailCodeTargetMasked,
            'selectedEmailInput' => $selectedEmailInput,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function verifyCodeChallenge(AuthService $auth, LoginUiStateService $uiState, array $post): array
    {
        $challenge = $this->requirePendingChallenge($auth);
        if (!(bool) ($challenge['ok'] ?? false)) {
            return $challenge;
        }

        $pendingMethods = is_array($challenge['pending_methods'] ?? null)
            ? $challenge['pending_methods']
            : [];
        $selection = $this->twoFactorFlowService->resolveCodeMethodForVerification(
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
            if (TwoFactorMethodKey::isRecoveryPool($selectedRecoveryKey)) {
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
                    $delivery = $this->twoFactorEmailDeliveryService->sendLoginCode(
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
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function selectMethod(AuthService $auth, LoginUiStateService $uiState, array $post): array
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
        $selectedMethod = $this->twoFactorFlowService->resolveSelectedMethod($pendingMethods, $methodKey);
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
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function webauthnOptions(AuthService $auth, LoginUiStateService $uiState, array $server): array
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
        $context = $this->loginWebAuthnChallengeService->prepareOptionsContext(
            $auth,
            $this->twoFactorFlowService,
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

        $webAuthn = WebAuthnService::createServer(
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
            $uiState->storeSelectedMethodKey(TwoFactorMethodKey::forWebauthnCredentialId($selectedCredentialIdB64));

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
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function verifyWebauthn(
        AuthService $auth,
        LoginUiStateService $uiState,
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

        $context = $this->loginWebAuthnChallengeService->prepareVerifyContext(
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

        $webAuthn = WebAuthnService::createServer(
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

    /**
     * @return array<string, mixed>
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
