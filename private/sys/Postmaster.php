<?php

/**
 * RAVEN CMS
 * ~/private/sys/Postmaster.php
 * Shared outgoing mail delivery service for core and extensions.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core;

use Raven\Core\Config;
use Raven\Lib\Mail\Address;
use Raven\Lib\Mail\Message;

/**
 * Shared outgoing mail delivery service.
 *
 * Owns sender configuration (address, display name), sendmail-first delivery with a
 * php_mail fallback, and header/Message-ID assembly. Both core auth (login email codes)
 * and extension code (contact forms) obtain one shared instance from the service
 * container so mail config is read exactly once and transport logic is not duplicated.
 */
final class Postmaster
{
    private string $senderAddress;
    private string $senderName;
    private string $siteDomain;
    private string $mailAgent;

    /**
     * Reads mail and site configuration to prepare the shared delivery service.
     *
     * Resolves the sender address (falling back to a no-reply address when the config
     * value is absent or invalid) and sanitizes the sender display name.
     *
     * @param Config $config Shared configuration service for `mail.*` and `site.*` settings.
     */
    public function __construct(Config $config)
    {
        $this->siteDomain = (string) $config->get('site.domain', '');
        $this->mailAgent  = strtolower(trim((string) $config->get('mail.agent', 'php_mail')));

        // Prefer the configured sender address; fall back to a no-reply derived from site.domain.
        $configuredAddress   = Address::normalize((string) $config->get('mail.sender_address', ''));
        $this->senderAddress = $configuredAddress ?? Address::defaultNoReply($this->siteDomain);

        $configuredName   = Address::sanitizeHeader((string) $config->get('mail.sender_name', ''), 120);
        $this->senderName = $configuredName !== '' ? $configuredName : 'Postmaster';
    }

    /**
     * Sends one outgoing message using the configured mail transport.
     *
     * Tries the sendmail binary first when available (allows correct multi-recipient
     * and Reply-To delivery without relying on php.ini header gymnastics), then falls
     * back to the built-in `mail()` function. Returns an error result when the
     * configured mail agent is unsupported or when all delivery attempts fail.
     *
     * @param Message $message Fully constructed outgoing message value object.
     * @return array{ok: bool, message?: string} Delivery result; `message` is set on failure.
     */
    public function send(Message $message): array
    {
        // Fail early for unsupported transport selections until additional agents are implemented.
        if ($this->mailAgent !== 'php_mail') {
            return ['ok' => false, 'message' => 'Configured mail agent is not supported yet.'];
        }

        $to = $message->to();
        // A message without To recipients cannot be delivered by either transport path.
        if ($to === []) {
            return ['ok' => false, 'message' => 'No recipients specified.'];
        }

        // Deduplicate across priority groups: CC cannot overlap with To, BCC cannot
        // overlap with To or CC, preventing duplicate deliveries to the same address.
        $toMap = array_fill_keys($to, true);
        $cc    = array_values(array_filter(
            $message->cc(),
            static fn (string $e): bool => !isset($toMap[$e])
        ));
        $toAndCcMap = $toMap;
        // Build lookup map so BCC filtering can exclude both To and CC recipients.
        foreach ($cc as $ccEmail) {
            $toAndCcMap[$ccEmail] = true;
        }
        $bcc = array_values(array_filter(
            $message->bcc(),
            static fn (string $e): bool => !isset($toAndCcMap[$e])
        ));

        $fromHeader = $this->senderName !== ''
            ? ($this->senderName . ' <' . $this->senderAddress . '>')
            : $this->senderAddress;

        $baseHeaders   = $this->buildBaseHeaders($message, $fromHeader);
        $baseHeaders[] = 'Message-ID: ' . $this->buildMessageId($this->siteDomain);

        // Sanitize subject once so both transport paths use the same value.
        $subject            = str_replace(["\r", "\n"], ' ', $message->subject());
        $envelopeRecipients = array_values(array_unique(array_merge($to, $cc, $bcc)));

        $transportError = '';
        $sendmailBinary = $this->sendmailBinary();
        // Prefer direct sendmail delivery when available for more reliable multi-recipient handling.
        if ($sendmailBinary !== null) {
            // Stop after first successful transport attempt; no php_mail fallback needed.
            if ($this->viaSendmail($sendmailBinary, $envelopeRecipients, $to, $cc, $subject, $message->body(), $baseHeaders, $this->senderAddress, $transportError)) {
                return ['ok' => true];
            }
        }

        // Fallback: build a single mail() call with CC/BCC folded into the header string.
        $headers = $baseHeaders;
        if ($cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        // BCC stays in headers only on php_mail fallback path where envelope args are unavailable.
        if ($bcc !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $bcc);
        }
        $toRecipients = implode(', ', $to);
        $ok = @\mail($toRecipients, $subject, $message->body(), implode("\r\n", $headers));
        // Surface sendmail diagnostics when available to aid mail transport troubleshooting.
        if ($ok !== true) {
            $suffix = $transportError !== '' ? (' ' . $transportError) : '';
            return ['ok' => false, 'message' => 'Failed to send email via php_mail.' . $suffix];
        }

        return ['ok' => true];
    }

    /**
     * Returns the configured sender From address used on all outgoing messages.
     *
     * @return string Normalized sender email address (e.g. `donotreply@example.com`).
     */
    public function senderAddress(): string
    {
        return $this->senderAddress;
    }

    /**
     * Returns the configured sender display name used on all outgoing messages.
     *
     * @return string Sender display name (e.g. `Postmaster`).
     */
    public function senderName(): string
    {
        return $this->senderName;
    }

    // -------------------------------------------------------------------------
    // Private delivery helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the sendmail binary path from the `sendmail_path` ini setting, or null.
     *
     * Extracts only the binary segment (before any flags), then verifies that the
     * resolved path is an existing, executable file. Returns null when unavailable.
     *
     * @return string|null Absolute path to a usable sendmail binary, or null.
     */
    private function sendmailBinary(): ?string
    {
        $rawPath = trim((string) ini_get('sendmail_path'));
        // Empty ini setting means sendmail transport is unavailable in this runtime.
        if ($rawPath === '') {
            return null;
        }

        // Match the binary portion only: quoted, single-quoted, or bare non-space token.
        if (preg_match('/^(?:"([^"]+)"|\'([^\']+)\'|(\S+))/', $rawPath, $matches) !== 1) {
            return null;
        }

        $binary = (string) ($matches[1] ?? $matches[2] ?? $matches[3] ?? '');
        // Require an executable file path before attempting direct sendmail invocation.
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
            return null;
        }

        return $binary;
    }

    /**
     * Delivers one message by invoking sendmail directly without the `-t` flag.
     *
     * Builds a complete RFC 2822 message (headers + body) and writes it to sendmail's
     * stdin. Envelope recipients are passed as CLI arguments so delivery does not
     * depend on parsing the `To:` header — BCC recipients receive the message without
     * their addresses appearing in the headers visible to other recipients.
     *
     * @param string             $sendmailBinary     Absolute path to the sendmail binary.
     * @param array<int, string> $envelopeRecipients All envelope recipients (To + Cc + Bcc).
     * @param array<int, string> $toRecipients       Primary `To:` recipients for header assembly.
     * @param array<int, string> $ccRecipients       `Cc:` recipients for header assembly.
     * @param string             $subject            Pre-sanitized subject line.
     * @param string             $body               Plain-text message body.
     * @param array<int, string> $baseHeaders        Pre-built base header lines (without To/Cc/Subject).
     * @param string             $fromAddress        Envelope sender address passed to sendmail via `-f`.
     * @param string             $error              Set to a diagnostic string on failure, untouched on success.
     * @return bool True when sendmail exits with status 0, false on any failure.
     */
    private function viaSendmail(
        string $sendmailBinary,
        array $envelopeRecipients,
        array $toRecipients,
        array $ccRecipients,
        string $subject,
        string $body,
        array $baseHeaders,
        string $fromAddress,
        string &$error
    ): bool {
        $error = '';
        // Sendmail needs at least one envelope recipient and one visible To recipient.
        if ($envelopeRecipients === [] || $toRecipients === []) {
            $error = 'No valid recipients available for sendmail delivery.';
            return false;
        }

        // Build the command array: -i prevents treating a lone dot as message terminator;
        // -f sets the envelope sender for bounce handling.
        $command        = array_merge([$sendmailBinary, '-i', '-f', $fromAddress], $envelopeRecipients);
        $descriptorSpec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes);
        // Abort cleanly when the process cannot be started.
        if (!is_resource($process)) {
            $error = 'Could not start sendmail process.';
            return false;
        }

        // Build the complete RFC 2822 message by appending addressing and subject to base headers.
        $headers = [];
        foreach ($baseHeaders as $headerLine) {
            $line = trim((string) $headerLine);
            // Preserve only non-empty header lines after trimming user-supplied input.
            if ($line !== '') {
                // Strip any newlines that slipped through to prevent header injection.
                $headers[] = str_replace(["\r", "\n"], '', $line);
            }
        }
        $headers[] = 'To: ' . implode(', ', $toRecipients);
        // Add CC header only when the deduplicated CC list is non-empty.
        if ($ccRecipients !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
        }
        $headers[] = 'Subject: ' . $subject;

        // Normalize line endings: headers use CRLF, body lines use CRLF per RFC 2822.
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $fullMessage    = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalizedBody);

        // Write message payload only when stdin pipe is available.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $fullMessage);
            fclose($pipes[0]);
        }

        $stdout = '';
        // Read and close stdout pipe when exposed by proc_open.
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        $stderr = '';
        // Read and close stderr pipe when exposed by proc_open.
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        // Non-zero exit code means sendmail rejected or failed delivery.
        if ($exitCode !== 0) {
            $combined = trim(trim($stdout) . PHP_EOL . trim($stderr));
            $error    = $combined !== ''
                ? ('sendmail exited with status ' . $exitCode . ': ' . $combined)
                : ('sendmail exited with status ' . $exitCode . '.');
            return false;
        }

        return true;
    }

    /**
     * Builds the base header lines shared by both sendmail and php_mail delivery paths.
     *
     * Includes MIME-Version, Content-Type, From, Reply-To (when set), and any custom
     * headers from the message. Does NOT include To, Cc, Bcc, Subject, or Message-ID,
     * which are added separately depending on the transport path.
     *
     * @param Message $message    Outgoing message supplying Reply-To and custom headers.
     * @param string  $fromHeader Assembled `From:` field value (e.g. `Postmaster <no-reply@example.com>`).
     * @return array<int, string> Ordered base header lines ready for transport assembly.
     */
    private function buildBaseHeaders(Message $message, string $fromHeader): array
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
        ];

        // Reply-To is optional and should only be emitted when explicitly configured per message.
        if ($message->replyTo() !== '') {
            $headers[] = 'Reply-To: ' . $message->replyTo();
        }

        // Accept custom headers only after trimming and newline stripping to prevent injection.
        foreach ($message->customHeaders() as $customHeader) {
            $line = trim((string) $customHeader);
            // Skip blank custom header fragments after normalization.
            if ($line !== '') {
                $headers[] = str_replace(["\r", "\n"], '', $line);
            }
        }

        return $headers;
    }

    /**
     * Generates a unique RFC 2822 Message-ID value for one outgoing message.
     *
     * Uses random_bytes for CSPRNG entropy; falls back to uniqid on failure so delivery
     * is never blocked by a missing entropy source. The right-hand side of the ID uses
     * Address::headerDomain() to ensure MTA-accepted formatting.
     *
     * @param string $siteDomain Raw site domain from config, used for the right-hand side token.
     * @return string Complete Message-ID value including angle brackets (e.g. `<raven-abc123@example.com>`).
     */
    private function buildMessageId(string $siteDomain): string
    {
        // Prefer CSPRNG entropy for message ids to avoid predictable identifiers.
        try {
            $entropy = bin2hex(random_bytes(12));
        } catch (\Throwable) {
            // Fallback keeps message delivery functional even when CSPRNG entropy is unavailable.
            $entropy = str_replace('.', '', uniqid('fallback', true));
        }

        return '<raven-' . $entropy . '@' . Address::headerDomain($siteDomain) . '>';
    }
}
