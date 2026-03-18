<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared helpers for interactive 2FA challenge method selection.
 */
final class TwoFactorChallengeHelper
{
    /**
     * @param array<int, array<string, mixed>> $methods
     */
    public static function findByKey(array $methods, string $methodKey): ?array
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
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function filterByType(array $methods, string $type): array
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return [];
        }

        $filtered = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (strtolower(trim((string) ($method['type'] ?? ''))) !== $type) {
                continue;
            }

            $filtered[] = $method;
        }

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function codeMethods(array $methods): array
    {
        return array_values(array_merge(
            self::filterByType($methods, 'totp'),
            self::filterByType($methods, 'recovery'),
            self::filterByType($methods, 'email')
        ));
    }

    /**
     * Returns code-based methods for UI lists with pooled recovery/email options.
     *
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public static function pooledCodeMethods(array $methods): array
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

        usort($pooled, static function (array $a, array $b): int {
            $labelA = strtolower(trim((string) ($a['label'] ?? '')));
            $labelB = strtolower(trim((string) ($b['label'] ?? '')));
            if ($labelA !== $labelB) {
                return $labelA <=> $labelB;
            }

            $typeA = strtolower(trim((string) ($a['type'] ?? '')));
            $typeB = strtolower(trim((string) ($b['type'] ?? '')));
            if ($typeA !== $typeB) {
                return $typeA <=> $typeB;
            }

            $keyA = strtolower(trim((string) ($a['key'] ?? '')));
            $keyB = strtolower(trim((string) ($b['key'] ?? '')));
            return $keyA <=> $keyB;
        });

        return $pooled;
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @param array<string, mixed>|null $selectedMethod
     * @return array<int, array<string, mixed>>
     */
    public static function fallbackMethods(array $methods, ?array $selectedMethod = null): array
    {
        $selectedKey = trim((string) ($selectedMethod['key'] ?? ''));
        $fallbackMethods = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $methodKey = trim((string) ($method['key'] ?? ''));
            if ($methodKey === '') {
                continue;
            }

            if ($selectedKey !== '' && $methodKey === $selectedKey) {
                continue;
            }

            $fallbackMethods[] = $method;
        }

        return $fallbackMethods;
    }
}
