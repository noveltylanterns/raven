<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Normalizes and deduplicates user contact-profile rows.
 */
final class ContactProfileNormalizer
{
    /**
     * @param array<int, mixed> $profiles
     * @return array<int, array{type: string, value: string}>
     */
    public function normalize(array $profiles, int $maxItems = 20): array
    {
        $maxItems = max(1, $maxItems);
        $normalized = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $type = strtolower(trim((string) ($profile['type'] ?? '')));
            $value = trim((string) ($profile['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            $type = preg_replace('/[^a-z0-9-]+/', '-', $type) ?? '';
            $type = trim($type, '-');
            $type = preg_replace('/-+/', '-', $type) ?? '';
            if ($type === '') {
                continue;
            }

            if (mb_strlen($type) > 80) {
                $type = mb_substr($type, 0, 80);
            }
            if (mb_strlen($value) > 255) {
                $value = mb_substr($value, 0, 255);
            }
            if ($value === '') {
                continue;
            }

            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type' => $type,
                'value' => $value,
            ];

            if (count($normalized) >= $maxItems) {
                break;
            }
        }

        $result = array_values($normalized);
        usort(
            $result,
            static function (array $left, array $right): int {
                $leftType = strtolower(trim((string) ($left['type'] ?? '')));
                $rightType = strtolower(trim((string) ($right['type'] ?? '')));
                if ($leftType !== $rightType) {
                    return $leftType <=> $rightType;
                }

                $leftValue = strtolower(trim((string) ($left['value'] ?? '')));
                $rightValue = strtolower(trim((string) ($right['value'] ?? '')));
                if ($leftValue !== $rightValue) {
                    return $leftValue <=> $rightValue;
                }

                return 0;
            }
        );

        return $result;
    }
}
