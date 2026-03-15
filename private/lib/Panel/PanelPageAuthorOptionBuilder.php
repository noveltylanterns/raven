<?php

declare(strict_types=1);

namespace Raven\Lib\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Builds normalized page-author option rows for panel page editor selects.
 */
final class PanelPageAuthorOptionBuilder
{
    /**
     * @param array<int, mixed> $users
     * @param callable(string): ?string $normalizeIdentifier
     * @return array<int, array{id: int, username: string, display_name: string}>
     */
    public function build(array $users, InputSanitizer $input, callable $normalizeIdentifier): array
    {
        $options = [];
        foreach ($users as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $userId = (int) ($entry['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $username = $normalizeIdentifier((string) ($entry['username'] ?? ''));
            if (!is_string($username) || $username === '') {
                continue;
            }

            $options[$userId] = [
                'id' => $userId,
                'username' => $username,
                'display_name' => $input->text((string) ($entry['display_name'] ?? ''), 120),
            ];
        }

        $result = array_values($options);
        usort($result, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) (($left['display_name'] ?? '') !== '' ? $left['display_name'] : $left['username'])));
            $rightLabel = strtolower(trim((string) (($right['display_name'] ?? '') !== '' ? $right['display_name'] : $right['username'])));
            if ($leftLabel !== $rightLabel) {
                return $leftLabel <=> $rightLabel;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $result;
    }
}

