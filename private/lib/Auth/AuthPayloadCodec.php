<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\TwoFactorMethodNormalizer;

/**
 * Shared codec for auth-adjacent JSON payload persistence.
 */
final class AuthPayloadCodec
{
    private ContactProfileNormalizer $contactProfileNormalizer;

    public function __construct(ContactProfileNormalizer $contactProfileNormalizer)
    {
        $this->contactProfileNormalizer = $contactProfileNormalizer;
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public function decodeContactProfiles(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeContactProfiles($decoded);
    }

    /**
     * @param array<int, array{type: string, value: string}> $profiles
     */
    public function encodeContactProfiles(array $profiles): ?string
    {
        if ($profiles === []) {
            return null;
        }

        try {
            return json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, mixed> $profiles
     * @return array<int, array{type: string, value: string}>
     */
    public function normalizeContactProfiles(array $profiles): array
    {
        return $this->contactProfileNormalizer->normalize($profiles, 20);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decodeTwoFactorMethods(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return TwoFactorMethodNormalizer::normalizeStored($decoded);
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    public function encodeTwoFactorMethods(array $methods): ?string
    {
        if ($methods === []) {
            return null;
        }

        try {
            return json_encode($methods, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }
}
