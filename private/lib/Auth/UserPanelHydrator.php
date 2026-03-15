<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Builds panel-facing user rows with consistent group display metadata.
 */
final class UserPanelHydrator
{
    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<int, array{name: string, permission_mask: int}>> $groupMap
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $users, array $groupMap): array
    {
        $result = [];
        foreach ($users as $row) {
            $userId = (int) ($row['id'] ?? 0);
            /** @var array<int, array{name: string, permission_mask: int}> $groupEntries */
            $groupEntries = $groupMap[$userId] ?? [];
            $groupNames = array_map(
                static fn (array $entry): string => (string) ($entry['name'] ?? ''),
                $groupEntries
            );

            $result[] = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                    ? (string) $row['avatar_path']
                    : null,
                'groups' => $groupNames,
                'group_entries' => $groupEntries,
                'groups_text' => implode(', ', $groupNames),
            ];
        }

        return $result;
    }
}

