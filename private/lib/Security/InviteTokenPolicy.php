<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared invite-token generation, display, hashing, and normalization policy.
 */
final class InviteTokenPolicy
{
    public function tokenHash(string $normalizedToken): string
    {
        return hash('sha256', $normalizedToken);
    }

    public function generateNormalizedToken(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $alphabetLength = strlen($alphabet);
        $length = max(1, $length);
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $token;
    }

    public function formatDisplayToken(string $normalizedToken, int $groupSize = 5): string
    {
        $groupSize = max(1, $groupSize);
        return implode('-', str_split($normalizedToken, $groupSize));
    }

    public function normalizeSubmittedToken(string $rawToken): ?string
    {
        $normalized = strtoupper(trim($rawToken));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
        if ($normalized === '' || strlen($normalized) < 8 || strlen($normalized) > 64) {
            return null;
        }

        if (preg_match('/^[A-Z0-9]+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    public function normalizeExpiresAt(?int $expiresAt): ?int
    {
        if ($expiresAt === null || $expiresAt <= 0) {
            return null;
        }

        return $expiresAt;
    }

    public function normalizeCreatedByUserId(?int $createdByUserId): ?int
    {
        if ($createdByUserId === null || $createdByUserId <= 0) {
            return null;
        }

        return $createdByUserId;
    }
}
