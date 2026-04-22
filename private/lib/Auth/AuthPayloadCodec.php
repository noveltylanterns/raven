<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\TotpCipher;
use Raven\Lib\Security\TwoFactorMethodNormalizer;

/**
 * Shared codec for auth-adjacent JSON payload persistence.
 */
final class AuthPayloadCodec
{
    private ContactProfileNormalizer $contactProfileNormalizer;
    private TotpCipher $totpSecretCipher;

    public function __construct(
        ContactProfileNormalizer $contactProfileNormalizer,
        ?TotpCipher $totpSecretCipher = null
    )
    {
        $this->contactProfileNormalizer = $contactProfileNormalizer;
        $this->totpSecretCipher = $totpSecretCipher ?? new TotpCipher();
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

        return TwoFactorMethodNormalizer::normalizeStored($this->decryptTotpSecrets($decoded));
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
            return json_encode($this->encryptTotpSecrets($methods), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    private function encryptTotpSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '') {
                continue;
            }

            if ($this->totpSecretCipher->isEncrypted($secret)) {
                continue;
            }

            $encrypted = $this->totpSecretCipher->encryptSecret($secret);
            if (!is_string($encrypted) || $encrypted === '') {
                continue;
            }

            $method['secret'] = $encrypted;
            $methods[$index] = $method;
        }

        return $methods;
    }

    /**
     * @param array<int, mixed> $methods
     * @return array<int, mixed>
     */
    private function decryptTotpSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '') {
                continue;
            }

            $decrypted = $this->totpSecretCipher->decryptSecret($secret);
            $method['secret'] = is_string($decrypted) ? $decrypted : '';
            $methods[$index] = $method;
        }

        return $methods;
    }
}
