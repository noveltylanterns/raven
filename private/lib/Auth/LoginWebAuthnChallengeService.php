<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Auth\AuthService;
use Raven\Lib\Security\LoginTwoFactorFlowService;
use Raven\Lib\Security\WebAuthnService;

/**
 * Shared panel/public login WebAuthn challenge context preparation helpers.
 */
final class LoginWebAuthnChallengeService
{
    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array{
     *   ok: bool,
     *   status: int,
     *   message?: string,
     *   selected_method_key?: string,
     *   credential_id_b64?: string,
     *   require_user_verification?: string
     * }
     */
    public function prepareOptionsContext(
        AuthService $auth,
        LoginTwoFactorFlowService $flow,
        int $userId,
        array $pendingMethods,
        string $selectedMethodKey
    ): array {
        $selectedMethod = $flow->resolveWebauthnMethodForOptions($pendingMethods, trim($selectedMethodKey));
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

        $selectedCredentialIdB64 = $flow->selectedWebauthnCredentialId($selectedMethod);
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

        $resolvedMethod = $flow->resolveRegisteredWebauthnMethod(
            (array) ($preferences['two_factor_methods'] ?? []),
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
     * @param array<string, mixed> $post
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
    public function prepareVerifyContext(AuthService $auth, int $userId, array $post): array
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
        foreach ((array) ($preferences['two_factor_methods'] ?? []) as $method) {
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

        if ($requiresUserVerification && !WebAuthnService::authenticatorDataHasUserVerification($authenticatorData)) {
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
}
