<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared email-delivery helper for login-time Email Code 2FA challenges.
 */
final class TwoFactorEmailDeliveryService
{
    /**
     * @return array{ok: bool, message?: string}
     */
    public function sendLoginCode(
        string $recipientEmail,
        string $code,
        string $siteName,
        string $siteDomain,
        string $senderAddress = '',
        string $senderName = 'Postmaster',
        string $mailAgent = 'php_mail',
        int $ttlSeconds = 600
    ): array {
        $recipientEmail = $this->normalizeEmail($recipientEmail);
        if ($recipientEmail === null) {
            return ['ok' => false, 'message' => 'Email code recipient is invalid.'];
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 8) {
            return ['ok' => false, 'message' => 'Email code payload is invalid.'];
        }

        $mailAgent = strtolower(trim($mailAgent));
        if ($mailAgent === '') {
            $mailAgent = 'php_mail';
        }
        if ($mailAgent !== 'php_mail') {
            return ['ok' => false, 'message' => 'Configured mail agent is not supported yet.'];
        }

        $safeSiteName = $this->sanitizeText($siteName, 120);
        if ($safeSiteName === '') {
            $safeSiteName = 'Raven CMS';
        }

        $subject = '[' . $safeSiteName . '] Your login verification code';
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $subject = trim($subject);

        $fromAddress = $this->normalizeEmail($senderAddress) ?? $this->defaultNoReplyAddress($siteDomain);
        $fromName = $this->sanitizeText($senderName, 120);
        if ($fromName === '') {
            $fromName = 'Postmaster';
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

        $fromHeader = $fromName . ' <' . $fromAddress . '>';
        try {
            $messageEntropy = bin2hex(random_bytes(12));
        } catch (\Throwable $exception) {
            $messageEntropy = str_replace('.', '', uniqid('fallback', true));
        }

        $messageId = '<raven-2fa-' . $messageEntropy . '@' . $this->mailHeaderDomain($siteDomain) . '>';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
            'Message-ID: ' . $messageId,
            'X-Raven-Auth-Flow: login-2fa-email-code',
        ];

        $ok = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));
        if ($ok !== true) {
            return ['ok' => false, 'message' => 'Failed to send verification email.'];
        }

        return ['ok' => true];
    }

    public function maskEmail(string $email): string
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return '';
        }

        $parts = explode('@', $normalized, 2);
        $local = (string) ($parts[0] ?? '');
        $domain = (string) ($parts[1] ?? '');
        if ($local === '' || $domain === '') {
            return '';
        }

        $localMasked = strlen($local) <= 2
            ? substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1))
            : substr($local, 0, 2) . '***' . substr($local, -1);

        $domainParts = explode('.', $domain);
        $domainRoot = (string) ($domainParts[0] ?? '');
        $domainTld = count($domainParts) > 1 ? '.' . end($domainParts) : '';
        $domainMasked = $domainRoot === ''
            ? '***'
            : substr($domainRoot, 0, 1) . '***' . $domainTld;

        return $localMasked . '@' . $domainMasked;
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function defaultNoReplyAddress(string $siteDomain): string
    {
        $domain = $this->mailHeaderDomain($siteDomain);
        if (!str_contains($domain, '.')) {
            $domain = 'localhost.localdomain';
        }

        return 'no-reply@' . $domain;
    }

    private function mailHeaderDomain(string $siteDomain): string
    {
        $siteDomain = strtolower(trim($siteDomain));
        $host = '';
        if ($siteDomain !== '') {
            $host = (string) parse_url('//' . $siteDomain, PHP_URL_HOST);
            if ($host === '') {
                $host = $siteDomain;
            }
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '') {
            $host = 'localhost.localdomain';
        }

        return $host;
    }
}
