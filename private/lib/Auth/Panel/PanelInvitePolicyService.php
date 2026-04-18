<?php

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared panel invite-form parsing/normalization policy helpers.
 */
final class PanelInvitePolicyService
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    public function isReusableInviteType(mixed $rawType): bool
    {
        $inviteType = strtolower(trim((string) $this->input->text($rawType, 20)));
        return $inviteType === 'reusable';
    }

    public function normalizeBatchCount(mixed $rawCount, int $default = 10, int $min = 1, int $max = 100): int
    {
        $default = max($min, min($max, $default));
        return $this->input->int($rawCount, $min, $max) ?? $default;
    }

    public function parseExpirationTimestamp(mixed $rawValue): ?int
    {
        $value = trim((string) $this->input->text(is_string($rawValue) ? $rawValue : null, 40));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException('Invite expiration must be a valid date/time or left blank.');
        }

        if ($timestamp <= time()) {
            throw new \RuntimeException('Invite expiration must be in the future.');
        }

        return $timestamp;
    }
}
