<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorAuthor.php
 * Panel page-editor author option normalization helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Builds normalized page-author option rows for panel page editor selects.
 */
final class EditorAuthor
{
    /**
     * @param array<int, mixed> $users
     * @param callable(string): ?string $normalizeIdentifier
     * @return array<int, array{id: int, username: string, name: string}>
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
                'name' => $input->text((string) ($entry['name'] ?? ''), 120),
            ];
        }

        $options = array_values($options);
        usort($options, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) (($left['name'] ?? '') ?: ($left['username'] ?? ''))));
            $rightLabel = strtolower(trim((string) (($right['name'] ?? '') ?: ($right['username'] ?? ''))));
            if ($leftLabel !== $rightLabel) {
                return $leftLabel <=> $rightLabel;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $options;
    }
}
