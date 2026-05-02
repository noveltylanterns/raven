<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginEmailChallenge.php
 * Login-time email-code challenge issue and verification helpers for pending 2FA sessions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\TwoFactorMethodKey;

/**
 * Shared login-time email-code challenge issue/verify helpers for pending 2FA sessions.
 *
 * Owns the session storage for pending email-code challenges: stores issued code hashes,
 * retrieves them for verification, and clears entries on success, expiry, or explicit cancel.
 */
final class LoginEmailChallenge
{
    private const DEFAULT_TTL_SECONDS = 600;
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 1800;

    /** Session key under which all pending email-code challenge entries are stored. */
    private const SESSION_EMAIL_CHALLENGES = '_raven_2fa_pending_email_challenges';

    /**
     * Issues one email-code challenge for the pending 2FA session.
     *
     * Returns an existing unexpired challenge without re-issuing if one is already active
     * for the same method key. On a fresh issue the returned array includes the plaintext
     * code for the caller to dispatch via email; on a re-use it is absent.
     *
     * @param int|null $pendingUserId     User id from the pending 2FA session (null = no session).
     * @param array<int, array<string, mixed>> $pendingMethods Active 2FA method rows for this session.
     * @param string $selectedMethodKey   Method key or pool key chosen by the user.
     * @param int    $ttlSeconds          Challenge lifetime in seconds (clamped to 60–1800).
     * @param string $submittedEmail      Submitted email address when using an email-pool method key.
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
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        string $submittedEmail = ''
    ): array {
        if ($pendingUserId === null || $pendingUserId <= 0) {
            return ['ok' => false, 'message' => 'Login session expired.'];
        }

        $selectedMethod = $this->findByKey($pendingMethods, trim($selectedMethodKey));
        if (!is_array($selectedMethod)) {
            $selectedMethod = $this->findByKey(
                $this->pooledCodeMethods($pendingMethods),
                trim($selectedMethodKey)
            );
        }
        if (!is_array($selectedMethod)) {
            return ['ok' => false, 'message' => 'Selected verification method is invalid.'];
        }

        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'email') {
            return ['ok' => false, 'message' => 'Selected verification method does not support email codes.'];
        }

        $email = null;
        $methodKey = trim((string) ($selectedMethod['key'] ?? ''));
        if (TwoFactorMethodKey::isEmailPool($methodKey)) {
            $email = $this->resolvePooledEmailTarget($selectedMethod, $submittedEmail);
            if ($email === null) {
                return ['ok' => true, 'sent' => false];
            }

            $methodKey = TwoFactorMethodKey::forEmailAddress($email);
        } else {
            $email = $this->normalizeEmail((string) ($selectedMethod['email'] ?? ''));
            if ($email === null) {
                return ['ok' => false, 'message' => 'Email code target address is missing or invalid.'];
            }

            if ($methodKey === '') {
                $methodKey = TwoFactorMethodKey::forEmailAddress($email);
            }
        }

        $now = time();
        $existing = $this->pendingEmailCodeChallenge($pendingUserId, $methodKey);
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
        $this->storeEmailCodeChallenge($pendingUserId, $methodKey, $email, $codeHash, $now, $expiresAt);

        return [
            'ok' => true,
            'sent' => true,
            'email' => $email,
            'code' => $code,
            'expires_at' => $expiresAt,
            'method_key' => $methodKey,
        ];
    }

    /**
     * Verifies one submitted email code against the stored pending 2FA challenge.
     *
     * Clears the challenge entry on success or expiry. Returns false when no challenge
     * is active, the challenge has expired, or the submitted code does not match.
     *
     * @param int|null $pendingUserId    User id from the pending 2FA session.
     * @param string $selectedMethodKey  Method key (or email-pool key) chosen by the user.
     * @param string $submittedCode      Numeric code string submitted via the login form.
     * @param string $submittedEmail     Email address submitted when using the email pool key.
     * @return bool True when code matches an unexpired challenge, false otherwise.
     */
    public function verifySubmittedCode(
        ?int $pendingUserId,
        string $selectedMethodKey,
        string $submittedCode,
        string $submittedEmail = ''
    ): bool {
        $pendingUserId = (int) $pendingUserId;
        $selectedMethodKey = trim($selectedMethodKey);
        if ($pendingUserId <= 0) {
            return false;
        }

        if (TwoFactorMethodKey::isEmailPool($selectedMethodKey)) {
            $normalizedEmail = $this->normalizeEmail($submittedEmail);
            if ($normalizedEmail === null) {
                return false;
            }

            $selectedMethodKey = TwoFactorMethodKey::forEmailAddress($normalizedEmail);
        }

        if ($selectedMethodKey === '') {
            return false;
        }

        $challenge = $this->pendingEmailCodeChallenge($pendingUserId, $selectedMethodKey);
        if (!is_array($challenge)) {
            return false;
        }

        if ((int) ($challenge['expires_at'] ?? 0) <= time()) {
            $this->clearEmailCodeChallenge($selectedMethodKey);
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

        $this->clearEmailCodeChallenge($selectedMethodKey);
        return true;
    }

    /**
     * Stores one email-code challenge entry in the session for the given method key.
     *
     * @param int    $userId    Pending 2FA user id.
     * @param string $methodKey Derived email method key (not a pool key).
     * @param string $email     Target email address for this challenge.
     * @param string $codeHash  bcrypt hash of the generated plaintext code.
     * @param int    $issuedAt  Unix timestamp when the challenge was generated.
     * @param int    $expiresAt Unix timestamp when the challenge becomes invalid.
     */
    public function storeEmailCodeChallenge(
        int $userId,
        string $methodKey,
        string $email,
        string $codeHash,
        int $issuedAt,
        int $expiresAt
    ): void {
        $methodKey = trim($methodKey);
        $email = strtolower(trim($email));
        $codeHash = trim($codeHash);
        if (
            $userId <= 0
            || $methodKey === ''
            || $email === ''
            || $codeHash === ''
            || $issuedAt <= 0
            || $expiresAt <= $issuedAt
        ) {
            return;
        }

        $map = $this->emailChallengeMap();
        $map[$methodKey] = [
            'user_id' => $userId,
            'method_key' => $methodKey,
            'email' => $email,
            'code_hash' => $codeHash,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];

        $_SESSION[self::SESSION_EMAIL_CHALLENGES] = $map;
    }

    /**
     * Returns a pending email-code challenge for the given user and method key, or null.
     *
     * @param int    $userId    Pending 2FA user id.
     * @param string $methodKey Derived email method key to look up.
     * @return array{
     *   user_id: int,
     *   method_key: string,
     *   email: string,
     *   code_hash: string,
     *   issued_at: int,
     *   expires_at: int
     * }|null
     */
    public function pendingEmailCodeChallenge(int $userId, string $methodKey): ?array
    {
        $methodKey = trim($methodKey);
        if ($userId <= 0 || $methodKey === '') {
            return null;
        }

        $map = $this->emailChallengeMap();
        $challenge = $map[$methodKey] ?? null;
        if (!is_array($challenge)) {
            return null;
        }

        $challengeUserId = (int) ($challenge['user_id'] ?? 0);
        if ($challengeUserId !== $userId) {
            return null;
        }

        $email = strtolower(trim((string) ($challenge['email'] ?? '')));
        $codeHash = trim((string) ($challenge['code_hash'] ?? ''));
        $issuedAt = (int) ($challenge['issued_at'] ?? 0);
        $expiresAt = (int) ($challenge['expires_at'] ?? 0);
        if (
            $email === ''
            || $codeHash === ''
            || $issuedAt <= 0
            || $expiresAt <= $issuedAt
        ) {
            return null;
        }

        return [
            'user_id' => $challengeUserId,
            'method_key' => $methodKey,
            'email' => $email,
            'code_hash' => $codeHash,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Clears one email-code challenge by method key, or clears all when key is empty.
     *
     * @param string $methodKey Specific method key to clear; empty string removes all entries.
     */
    public function clearEmailCodeChallenge(string $methodKey = ''): void
    {
        $methodKey = trim($methodKey);
        if ($methodKey === '') {
            unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
            return;
        }

        $map = $this->emailChallengeMap();
        if (!array_key_exists($methodKey, $map)) {
            return;
        }

        unset($map[$methodKey]);
        if ($map === []) {
            unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
            return;
        }

        $_SESSION[self::SESSION_EMAIL_CHALLENGES] = $map;
    }

    /**
     * Clears all pending email-code challenges from the session regardless of method key.
     *
     * Called by AuthService when a 2FA challenge begins or the session is fully cleared,
     * so stale email challenges from prior challenge rounds cannot survive.
     */
    public function clearAllEmailChallenges(): void
    {
        unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function normalizeSubmittedCode(string $submittedCode): string
    {
        $normalized = preg_replace('/\D+/', '', $submittedCode) ?? '';
        return strlen($normalized) === 8 ? $normalized : '';
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

    /**
     * Resolves a pooled-email-method email target from the submitted address.
     *
     * @param array<string, mixed> $selectedMethod Pool email method row containing the allowed addresses list.
     * @param string $submittedEmail User-submitted address to match against the allow list.
     * @return string|null Normalized email when found in the allow list, null otherwise.
     */
    private function resolvePooledEmailTarget(array $selectedMethod, string $submittedEmail): ?string
    {
        $normalizedSubmitted = $this->normalizeEmail($submittedEmail);
        if ($normalizedSubmitted === null) {
            return null;
        }

        $allowedEmailsRaw = $selectedMethod['emails'] ?? [];
        if (!is_array($allowedEmailsRaw)) {
            return null;
        }

        foreach ($allowedEmailsRaw as $rawEmail) {
            if ($this->normalizeEmail((string) $rawEmail) === $normalizedSubmitted) {
                return $normalizedSubmitted;
            }
        }

        return null;
    }

    /**
     * Finds one pending login method row by exact key.
     *
     * @param array<int, array<string, mixed>> $methods Pending 2FA method rows.
     * @param string $methodKey Selected method key from UI state.
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
     * Builds the pooled login code-method list used by email challenge selection.
     *
     * Collapses individual email methods into a single pool entry and recovery methods
     * into a single recovery pool entry, so the challenge UI can present a simpler choice
     * instead of exposing raw method keys from the user's preference row.
     *
     * @param array<int, array<string, mixed>> $methods Pending 2FA method rows.
     * @return array<int, array<string, mixed>> Pooled code-method rows.
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

        return $pooled;
    }

    /**
     * Returns the email-challenge map from the session, normalizing keys and values.
     *
     * @return array<string, array<string, mixed>> Keyed by method key.
     */
    private function emailChallengeMap(): array
    {
        $raw = $_SESSION[self::SESSION_EMAIL_CHALLENGES] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            $methodKey = trim((string) $key);
            if ($methodKey === '' || !is_array($value)) {
                continue;
            }

            $map[$methodKey] = $value;
        }

        return $map;
    }
}
