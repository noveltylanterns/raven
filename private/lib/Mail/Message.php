<?php

/**
 * RAVEN CMS
 * ~/private/lib/Mail/Message.php
 * Immutable outgoing mail message value object.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Mail;

/**
 * Immutable outgoing mail message value object.
 *
 * Constructed with the three required fields (recipients, subject, body); optional
 * fields are appended via fluent `with*` builders that each return a new instance,
 * leaving the original unchanged. Callers build the domain-logic representation;
 * Postmaster assembles raw MIME headers and handles transport.
 */
final class Message
{
    /** @var array<int, string> Primary `To:` recipient addresses. */
    private array $to;
    private string $subject;
    private string $body;
    /** Reply-To address, or empty string when not set. */
    private string $replyTo;
    /** @var array<int, string> CC addresses. */
    private array $cc;
    /** @var array<int, string> BCC addresses. */
    private array $bcc;
    /** @var array<int, string> Extra raw header lines appended by the caller (e.g. `X-Raven-Auth-Flow: login`). */
    private array $customHeaders;

    /**
     * Constructs a minimal outgoing message with the three required envelope fields.
     *
     * @param array<int, string> $to      One or more primary recipient addresses.
     * @param string             $subject Message subject line (unsanitized; Postmaster handles escaping).
     * @param string             $body    Plain-text message body.
     */
    public function __construct(array $to, string $subject, string $body)
    {
        $this->to            = $to;
        $this->subject       = $subject;
        $this->body          = $body;
        $this->replyTo       = '';
        $this->cc            = [];
        $this->bcc           = [];
        $this->customHeaders = [];
    }

    /**
     * Returns a copy of this message with the Reply-To address set.
     *
     * @param string $replyTo Explicit Reply-To email address.
     * @return self New message instance with Reply-To populated.
     */
    public function withReplyTo(string $replyTo): self
    {
        $clone          = clone $this;
        $clone->replyTo = $replyTo;
        return $clone;
    }

    /**
     * Returns a copy of this message with the CC addresses set.
     *
     * @param array<int, string> $cc CC recipient addresses.
     * @return self New message instance with CC addresses populated.
     */
    public function withCc(array $cc): self
    {
        $clone     = clone $this;
        $clone->cc = $cc;
        return $clone;
    }

    /**
     * Returns a copy of this message with the BCC addresses set.
     *
     * @param array<int, string> $bcc BCC recipient addresses.
     * @return self New message instance with BCC addresses populated.
     */
    public function withBcc(array $bcc): self
    {
        $clone      = clone $this;
        $clone->bcc = $bcc;
        return $clone;
    }

    /**
     * Returns a copy of this message with one additional raw header line appended.
     *
     * The line must be a complete `Name: value` string without CRLF. Postmaster strips
     * newlines from custom header lines before writing them, so injection is prevented
     * even when values come from partially-trusted sources.
     *
     * @param string $header Complete raw header line (e.g. `X-Raven-Auth-Flow: login-2fa-email-code`).
     * @return self New message instance with the header appended.
     */
    public function withHeader(string $header): self
    {
        $clone                  = clone $this;
        $clone->customHeaders[] = $header;
        return $clone;
    }

    /**
     * Returns the primary `To:` recipient addresses.
     *
     * @return array<int, string>
     */
    public function to(): array
    {
        return $this->to;
    }

    /**
     * Returns the message subject line.
     *
     * @return string
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * Returns the plain-text message body.
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Returns the Reply-To address, or an empty string when not set.
     *
     * @return string
     */
    public function replyTo(): string
    {
        return $this->replyTo;
    }

    /**
     * Returns the CC recipient addresses.
     *
     * @return array<int, string>
     */
    public function cc(): array
    {
        return $this->cc;
    }

    /**
     * Returns the BCC recipient addresses.
     *
     * @return array<int, string>
     */
    public function bcc(): array
    {
        return $this->bcc;
    }

    /**
     * Returns the extra raw header lines appended via withHeader().
     *
     * @return array<int, string>
     */
    public function customHeaders(): array
    {
        return $this->customHeaders;
    }
}
