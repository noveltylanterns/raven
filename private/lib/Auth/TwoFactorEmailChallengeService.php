<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\TwoFactorChallengeHelper;
use Raven\Lib\Security\TwoFactorMethodKey;

/**
 * Shared login-time email-code challenge issue/verify helpers for pending 2FA sessions.
 */
final class TwoFactorEmailChallengeService
{
    private const DEFAULT_TTL_SECONDS = 600;
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 1800;

    /**
     * @param array<int, array<string, mixed>> $pendingMethods
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   sent?: bool,
     *   email?: string,
     *   code?: string,
     *   expires_at?: int,
     *   method_key?: string
     * }
     */
    public function issueChallenge(
        ?int $pendingUserId,
        array $pendingMethods,
        string $selectedMethodKey,
        TwoFactorSessionStateService $sessionState,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): array {
        if ($pendingUserId === null || $pendingUserId <= 0) {
            return ['ok' => false, 'message' => 'Login session expired.'];
        }

        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, trim($selectedMethodKey));
        if (!is_array($selectedMethod)) {
            return ['ok' => false, 'message' => 'Selected verification method is invalid.'];
        }

        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'email') {
            return ['ok' => false, 'message' => 'Selected verification method does not support email codes.'];
        }

        $email = $this->normalizeEmail((string) ($selectedMethod['email'] ?? ''));
        if ($email === null) {
            return ['ok' => false, 'message' => 'Email code target address is missing or invalid.'];
        }

        $methodKey = trim((string) ($selectedMethod['key'] ?? ''));
        if ($methodKey === '') {
            $methodKey = TwoFactorMethodKey::forEmailAddress($email);
        }

        $now = time();
        $existing = $sessionState->pendingEmailCodeChallenge($pendingUserId, $methodKey);
        if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) > $now) {
            return [
                'ok' => true,
                'sent' => false,
                'email' => $email,
                'expires_at' => (int) ($existing['expires_at'] ?? 0),
                'method_key' => $methodKey,
            ];
        }

        try {
            $code = $this->generateCode();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Unable to generate an email verification challenge.'];
        }
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($codeHash) || $codeHash === '') {
            return ['ok' => false, 'message' => 'Unable to generate an email verification challenge.'];
        }

        $expiresAt = $now + $this->normalizeTtlSeconds($ttlSeconds);
        $sessionState->storeEmailCodeChallenge($pendingUserId, $methodKey, $email, $codeHash, $now, $expiresAt);

        return [
            'ok' => true,
            'sent' => true,
            'email' => $email,
            'code' => $code,
            'expires_at' => $expiresAt,
            'method_key' => $methodKey,
        ];
    }

    public function verifySubmittedCode(
        ?int $pendingUserId,
        string $selectedMethodKey,
        string $submittedCode,
        TwoFactorSessionStateService $sessionState
    ): bool {
        $pendingUserId = (int) $pendingUserId;
        $selectedMethodKey = trim($selectedMethodKey);
        if ($pendingUserId <= 0 || $selectedMethodKey === '') {
            return false;
        }

        $challenge = $sessionState->pendingEmailCodeChallenge($pendingUserId, $selectedMethodKey);
        if (!is_array($challenge)) {
            return false;
        }

        if ((int) ($challenge['expires_at'] ?? 0) <= time()) {
            $sessionState->clearEmailCodeChallenge($selectedMethodKey);
            return false;
        }

        $normalizedCode = $this->normalizeSubmittedCode($submittedCode);
        if ($normalizedCode === '') {
            return false;
        }

        $codeHash = (string) ($challenge['code_hash'] ?? '');
        if ($codeHash === '' || !password_verify($normalizedCode, $codeHash)) {
            return false;
        }

        $sessionState->clearEmailCodeChallenge($selectedMethodKey);
        return true;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeSubmittedCode(string $submittedCode): string
    {
        $normalized = preg_replace('/\D+/', '', $submittedCode) ?? '';
        return strlen($normalized) === 6 ? $normalized : '';
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function normalizeTtlSeconds(int $ttlSeconds): int
    {
        return max(self::MIN_TTL_SECONDS, min(self::MAX_TTL_SECONDS, $ttlSeconds));
    }
}
