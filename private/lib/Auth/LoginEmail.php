<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginEmail.php
 * Login-time email-code challenge session management and delivery helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Postmaster;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Mail\Address;
use Raven\Lib\Mail\Message;
use Raven\Lib\Security\EmailGenerate;
use Raven\Lib\Security\EmailValidate;

/**
 * Shared helpers for login-time email-code 2FA challenges.
 *
 * Owns the session storage for pending email-code challenges (issue, verify, retrieve,
 * clear) and the delivery layer that formats and sends the one-time code to the user.
 * Both concerns live here because they are only ever used together in the same login flow.
 */
final class LoginEmail
{
    private const DEFAULT_TTL_SECONDS = 600;
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 1800;

    /** Session key under which all pending email-code challenge entries are stored. */
    private const SESSION_EMAIL_CHALLENGES = '_raven_2fa_pending_email_challenges';

    // -------------------------------------------------------------------------
    // Challenge session management
    // -------------------------------------------------------------------------

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
        // A pending 2FA user id is required to bind challenge issuance to one login session.
        if ($pendingUserId === null || $pendingUserId <= 0) {
            return ['ok' => false, 'message' => 'Login session expired.'];
        }

        $selectedMethod = $this->findByKey($pendingMethods, trim($selectedMethodKey));
        // Resolve pooled method keys when no direct method key match exists.
        if (!is_array($selectedMethod)) {
            $selectedMethod = $this->findByKey(
                $this->pooledCodeMethods($pendingMethods),
                trim($selectedMethodKey)
            );
        }
        // Stop when the selected key cannot be resolved to a method entry.
        if (!is_array($selectedMethod)) {
            return ['ok' => false, 'message' => 'Selected verification method is invalid.'];
        }

        // Email challenge flow only supports email-type methods.
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'email') {
            return ['ok' => false, 'message' => 'Selected verification method does not support email codes.'];
        }

        $email = null;
        $methodKey = trim((string) ($selectedMethod['key'] ?? ''));
        // Email pool entries require submitted target address selection.
        if (Login2fa::isEmailPool($methodKey)) {
            $email = $this->resolvePooledEmailTarget($selectedMethod, $submittedEmail);
            // Soft-fail without error when no allowed pooled target is selected.
            if ($email === null) {
                return ['ok' => true, 'sent' => false];
            }

            $methodKey = Login2fa::forEmailAddress($email);
        } else {
            $email = EmailValidate::normalize((string) ($selectedMethod['email'] ?? ''));
            // Non-pooled email methods must carry one valid target address.
            if ($email === null) {
                return ['ok' => false, 'message' => 'Email code target address is missing or invalid.'];
            }

            // Backfill method key for legacy email entries missing normalized key storage.
            if ($methodKey === '') {
                $methodKey = Login2fa::forEmailAddress($email);
            }
        }

        $now = time();
        $existing = $this->pendingEmailCodeChallenge($pendingUserId, $methodKey);
        // Reuse an active challenge instead of issuing and sending duplicate codes.
        if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) > $now) {
            return [
                'ok' => true,
                'sent' => false,
                'email' => $email,
                'expires_at' => (int) ($existing['expires_at'] ?? 0),
                'method_key' => $methodKey,
            ];
        }

        // Code generation may throw; convert to a user-safe issuance failure.
        try {
            $code = EmailGenerate::code();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Unable to generate an email verification challenge.'];
        }
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        // Hash creation must succeed before storing challenge material.
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
        // Verification is invalid without a pending 2FA user context.
        if ($pendingUserId <= 0) {
            return false;
        }

        // Email pool keys require one concrete submitted email target.
        if (Login2fa::isEmailPool($selectedMethodKey)) {
            $normalizedEmail = EmailValidate::normalize($submittedEmail);
            // Pool verification cannot proceed without a valid submitted address.
            if ($normalizedEmail === null) {
                return false;
            }

            $selectedMethodKey = Login2fa::forEmailAddress($normalizedEmail);
        }

        // Derived method key must be non-empty before challenge lookup.
        if ($selectedMethodKey === '') {
            return false;
        }

        $challenge = $this->pendingEmailCodeChallenge($pendingUserId, $selectedMethodKey);
        // Stop when no pending challenge exists for this user and method key.
        if (!is_array($challenge)) {
            return false;
        }

        // Expired challenges are cleared immediately and treated as invalid.
        if ((int) ($challenge['expires_at'] ?? 0) <= time()) {
            $this->clearEmailCodeChallenge($selectedMethodKey);
            return false;
        }

        $normalizedCode = EmailValidate::normalizeCode($submittedCode);
        // Empty/invalid code input can never pass hash verification.
        if ($normalizedCode === '') {
            return false;
        }

        $codeHash = (string) ($challenge['code_hash'] ?? '');
        // Verify provided code against stored hash; fail closed on missing hash.
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
        // Require complete and internally consistent challenge payloads before storage.
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
        // Lookup requires both a valid pending user id and a non-empty method key.
        if ($userId <= 0 || $methodKey === '') {
            return null;
        }

        $map = $this->emailChallengeMap();
        $challenge = $map[$methodKey] ?? null;
        // Missing or malformed map entries are treated as absent challenges.
        if (!is_array($challenge)) {
            return null;
        }

        $challengeUserId = (int) ($challenge['user_id'] ?? 0);
        // Enforce per-user challenge isolation by matching stored user id.
        if ($challengeUserId !== $userId) {
            return null;
        }

        $email = strtolower(trim((string) ($challenge['email'] ?? '')));
        $codeHash = trim((string) ($challenge['code_hash'] ?? ''));
        $issuedAt = (int) ($challenge['issued_at'] ?? 0);
        $expiresAt = (int) ($challenge['expires_at'] ?? 0);
        // Reject stored challenge rows with incomplete or invalid timing/data.
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
        // Empty key means clear every pending email challenge in session.
        if ($methodKey === '') {
            unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
            return;
        }

        $map = $this->emailChallengeMap();
        // No-op when the requested method key is not present.
        if (!array_key_exists($methodKey, $map)) {
            return;
        }

        unset($map[$methodKey]);
        // Remove the session container entirely when last entry was removed.
        if ($map === []) {
            unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
            return;
        }

        $_SESSION[self::SESSION_EMAIL_CHALLENGES] = $map;
    }

    /**
     * Clears all pending email-code challenges from the session regardless of method key.
     *
     * Called by Gatekeeper when a 2FA challenge begins or the session is fully cleared,
     * so stale email challenges from prior challenge rounds cannot survive.
     */
    public function clearAllEmailChallenges(): void
    {
        unset($_SESSION[self::SESSION_EMAIL_CHALLENGES]);
    }

    // -------------------------------------------------------------------------
    // Email delivery
    // -------------------------------------------------------------------------

    /**
     * Formats and sends the login email-code message to the recipient via Postmaster.
     *
     * Builds the subject and body from site name and TTL, then delegates transport and
     * sender configuration entirely to the shared Postmaster service.
     *
     * @param string     $recipientEmail Destination email address.
     * @param string     $code           Eight-digit plaintext code to include in the message.
     * @param string     $siteName       Site display name for the subject line.
     * @param Postmaster $postmaster     Shared delivery service that owns sender config and transport.
     * @param int        $ttlSeconds     Code lifetime in seconds, shown in the message body.
     * @return array{ok: bool, message?: string} Delivery result; `message` is set on failure.
     */
    public function sendCode(
        string $recipientEmail,
        string $code,
        string $siteName,
        Postmaster $postmaster,
        int $ttlSeconds = 600
    ): array {
        $recipientEmail = EmailValidate::normalize($recipientEmail);
        // Delivery requires one syntactically valid recipient address.
        if ($recipientEmail === null) {
            return ['ok' => false, 'message' => 'Email code recipient is invalid.'];
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';
        // Login email codes are fixed-width eight-digit values.
        if (strlen($code) !== 8) {
            return ['ok' => false, 'message' => 'Email code payload is invalid.'];
        }

        $safeSiteName = Address::sanitizeHeader($siteName, 120);
        // Fall back to canonical product name when site title sanitizes to empty.
        if ($safeSiteName === '') {
            $safeSiteName = 'Raven CMS';
        }

        $ttlSeconds = max(60, min(1800, $ttlSeconds));
        $ttlMinutes = (int) ceil($ttlSeconds / 60);
        $body = implode("\n", [
            'Use this code to finish signing in:',
            '',
            $code,
            '',
            'This code expires in about ' . $ttlMinutes . ' minute' . ($ttlMinutes === 1 ? '' : 's') . '.',
            'If you did not request this code, you can ignore this email.',
        ]);

        $subject = '[' . $safeSiteName . '] Your login verification code';
        $message = (new Message([$recipientEmail], $subject, $body))
            ->withHeader('X-Raven-Auth-Flow: login-2fa-email-code');

        return $postmaster->send($message);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Clamps one email-code lifetime to Raven's supported range.
     *
     * @param int $ttlSeconds Requested TTL in seconds.
     * @return int TTL constrained to MIN/MAX bounds.
     */
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
        $normalizedSubmitted = EmailValidate::normalize($submittedEmail);
        // Submitted pooled target must be one valid normalized email.
        if ($normalizedSubmitted === null) {
            return null;
        }

        $allowedEmailsRaw = $selectedMethod['emails'] ?? [];
        // Pooled email methods must provide an array of allowed target addresses.
        if (!is_array($allowedEmailsRaw)) {
            return null;
        }

        // Accept only submitted addresses that appear in the allowed pool list.
        foreach ($allowedEmailsRaw as $rawEmail) {
            // Match using normalized addresses to avoid case/format mismatches.
            if (EmailValidate::normalize((string) $rawEmail) === $normalizedSubmitted) {
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
        // Empty keys cannot resolve to any method row.
        if ($methodKey === '') {
            return null;
        }

        // Scan candidate rows until one exact key match is found.
        foreach ($methods as $method) {
            // Ignore malformed candidate rows.
            if (!is_array($method)) {
                continue;
            }

            // Return the first method row whose key matches exactly.
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

        // Collapse raw method rows into pooled code-selection entries.
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            // Keep TOTP methods as direct entries in pooled options.
            if ($type === 'totp') {
                $methodKey = trim((string) ($method['key'] ?? ''));
                // Drop malformed TOTP rows with missing method keys.
                if ($methodKey === '') {
                    continue;
                }

                $pooled[] = $method;
                continue;
            }

            // Any recovery method yields one pooled recovery option.
            if ($type === 'recovery') {
                $hasRecovery = true;
                continue;
            }

            // Ignore non-email/non-recovery method types for email challenge picker.
            if ($type !== 'email') {
                continue;
            }

            $email = strtolower(trim((string) ($method['email'] ?? '')));
            // Ignore email methods with missing target address.
            if ($email === '') {
                continue;
            }

            $emailMap[$email] = true;
        }

        // Append one pooled recovery row when recovery methods are present.
        if ($hasRecovery) {
            $pooled[] = [
                'type' => 'recovery',
                'key' => Login2fa::recoveryPool(),
                'label' => 'Enter Recovery Phrase',
            ];
        }

        // Append one pooled email row listing all distinct available addresses.
        if ($emailMap !== []) {
            $pooled[] = [
                'type' => 'email',
                'key' => Login2fa::emailPool(),
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
        // Session storage may be absent or malformed; normalize to empty map.
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        // Keep only entries with non-empty keys and array payloads.
        foreach ($raw as $key => $value) {
            $methodKey = trim((string) $key);
            // Drop malformed session rows lacking a usable key or array payload.
            if ($methodKey === '' || !is_array($value)) {
                continue;
            }

            $map[$methodKey] = $value;
        }

        return $map;
    }

}
