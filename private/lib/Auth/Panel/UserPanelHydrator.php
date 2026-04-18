<?php

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

/**
 * Builds panel-facing user rows with consistent group display metadata.
 */
final class UserPanelHydrator
{
    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<int, array{name: string, permissions: int}>> $groupMap
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $users, array $groupMap): array
    {
        $result = [];
        foreach ($users as $row) {
            $userId = (int) ($row['id'] ?? 0);
            /** @var array<int, array{name: string, permissions: int}> $groupEntries */
            $groupEntries = $groupMap[$userId] ?? [];
            $groupNames = array_map(
                static fn (array $entry): string => (string) ($entry['name'] ?? ''),
                $groupEntries
            );

            $result[] = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'string' => (string) ($row['string'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                    ? (string) $row['avatar']
                    : null,
                'groups' => $groupNames,
                'group_entries' => $groupEntries,
                'groups_text' => implode(', ', $groupNames),
            ];
        }

        return $result;
    }
}
