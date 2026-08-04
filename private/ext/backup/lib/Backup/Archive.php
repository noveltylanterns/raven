<?php

/**
 * RAVEN CMS
 * ~/private/ext/backup/lib/Backup/Archive.php
 * Complete page-content backup and restore service for the stock Backup & Restore extension.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Ext\Backup;

use PDO;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\SetWrite;
use Raven\Lib\Database\SqlTable;
use RuntimeException;

/**
 * Reads and restores Raven page content, taxonomy, routing, and relationship data.
 *
 * Media rows and media files are intentionally excluded until the media backup contract
 * is defined separately.
 */
final class Archive
{
    private const TABLES = [
        'pages',
        'categories',
        'tags',
        'redirects',
        'page_categories',
        'page_tags',
    ];

    private PDO $db;
    private string $driver;
    private string $prefix;
    private string $root;

    /**
     * Prepares the archive service for the active Raven installation.
     *
     * @param PDO $db Active Raven application database.
     * @param string $driver Database driver identifier.
     * @param string $prefix Application table prefix.
     * @param string $root Absolute Raven project root.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, string $root)
    {
        $this->db = $db;
        $this->driver = strtolower(trim($driver));
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->root = rtrim($root, '/');
    }

    /**
     * Exports all supported page-content and taxonomy records with their numeric ids intact.
     *
     * @return array<string, mixed> JSON-serializable complete content archive.
     */
    public function export(): array
    {
        $tables = [];
        foreach (self::TABLES as $tableName) {
            $table = $this->table($tableName);
            $statement = $this->db->query('SELECT * FROM ' . $table);
            $tables[$tableName] = $statement === false ? [] : ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        $channelRead = new ChannelRead($this->db, $this->driver, $this->prefix, $this->root . '/private/dat/channel');
        $categorySetRead = new SetRead('category', $this->root . '/private/dat/category-set');
        $tagSetRead = new SetRead('tag', $this->root . '/private/dat/tag-set');

        return [
            'format' => 'raven-backup',
            'version' => 1,
            'exported_at' => gmdate('c'),
            'includes' => [
                'pages' => true,
                'categories' => true,
                'tags' => true,
                'channels' => true,
                'sets' => true,
                'redirects' => true,
                'media' => false,
            ],
            'tables' => $tables,
            'channels' => $channelRead->listRecords(),
            'sets' => [
                'category' => $categorySetRead->listAll(),
                'tag' => $tagSetRead->listAll(),
            ],
        ];
    }

    /**
     * Restores a complete archive into the current installation using the ids in the payload.
     *
     * The panel warns operators to use this only on a new system. Existing conflicting rows
     * are intentionally not merged or renamed, so a restore cannot silently corrupt content.
     *
     * @param array<string, mixed> $archive Decoded archive payload.
     * @return array{tables: int, channels: int, sets: int} Restore counts.
     * @throws RuntimeException When the archive shape or persistence operation is invalid.
     */
    public function restore(array $archive): array
    {
        if (($archive['format'] ?? '') !== 'raven-backup' || (int) ($archive['version'] ?? 0) !== 1) {
            throw new RuntimeException('This file is not a supported Raven backup archive.');
        }

        $rawTables = $archive['tables'] ?? null;
        if (!is_array($rawTables)) {
            throw new RuntimeException('The backup archive does not contain database records.');
        }

        $this->db->beginTransaction();
        try {
            $tableCount = 0;
            foreach (['categories', 'tags', 'pages', 'redirects', 'page_categories', 'page_tags'] as $tableName) {
                $rows = $rawTables[$tableName] ?? [];
                if (!is_array($rows)) {
                    throw new RuntimeException('Backup table "' . $tableName . '" is malformed.');
                }

                $tableCount += $this->insertRows($tableName, $rows);
            }

            $this->resetSequences();
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw new RuntimeException('Database restore failed: ' . $exception->getMessage(), 0, $exception);
        }

        $channelCount = $this->restoreChannels($archive['channels'] ?? []);
        $setCount = $this->restoreSets($archive['sets'] ?? []);

        return [
            'tables' => $tableCount,
            'channels' => $channelCount,
            'sets' => $setCount,
        ];
    }

    /**
     * Inserts one archive table's rows while preserving explicit numeric ids.
     *
     * @param string $tableName Supported table name.
     * @param array<int, mixed> $rows Candidate row list.
     * @return int Number of inserted rows.
     */
    private function insertRows(string $tableName, array $rows): int
    {
        if (!in_array($tableName, self::TABLES, true)) {
            throw new RuntimeException('Unsupported backup table.');
        }

        $inserted = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $columns = [];
            $values = [];
            foreach ($row as $column => $value) {
                $columnName = (string) $column;
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $columnName) !== 1) {
                    throw new RuntimeException('Backup contains an invalid column name.');
                }

                $columns[] = $columnName;
                $values[] = $value;
            }

            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = 'INSERT INTO ' . $this->table($tableName)
                . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
            $statement = $this->db->prepare($sql);
            $statement->execute($values);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Restores file-backed channel records, skipping the reserved root record.
     *
     * @param mixed $rawChannels Candidate channel records.
     * @return int Number of restored channels.
     */
    private function restoreChannels(mixed $rawChannels): int
    {
        if (!is_array($rawChannels)) {
            throw new RuntimeException('Backup channels are malformed.');
        }

        $directory = $this->root . '/private/dat/channel';
        $count = 0;
        foreach ($rawChannels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $id = (int) ($channel['id'] ?? -1);
            // The implicit root channel is generated by Raven and is never restored from an archive.
            if ($id === 0) {
                continue;
            }

            $slug = strtolower(trim((string) ($channel['slug'] ?? '')));
            if ($id < 1 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
                throw new RuntimeException('Backup contains an invalid channel record.');
            }

            ChannelWrite::writeRecordById($directory, $id, $slug, $channel);
            $count++;
        }

        return $count;
    }

    /**
     * Restores category and tag set files with their explicit ids.
     *
     * @param mixed $rawSets Candidate set groups keyed by taxonomy type.
     * @return int Number of restored sets.
     */
    private function restoreSets(mixed $rawSets): int
    {
        if (!is_array($rawSets)) {
            throw new RuntimeException('Backup sets are malformed.');
        }

        $count = 0;
        foreach (['category', 'tag'] as $taxonomyType) {
            $rows = $rawSets[$taxonomyType] ?? [];
            if (!is_array($rows)) {
                throw new RuntimeException('Backup ' . $taxonomyType . ' sets are malformed.');
            }

            $directory = $this->root . '/private/dat/' . $taxonomyType . '-set';
            foreach ($rows as $set) {
                if (!is_array($set)) {
                    continue;
                }

                $id = (int) ($set['id'] ?? -1);
                $slug = strtolower(trim((string) ($set['slug'] ?? '')));
                if ($id < 1 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
                    throw new RuntimeException('Backup contains an invalid ' . $taxonomyType . ' set.');
                }

                SetWrite::writeRecordById($directory, $taxonomyType, $id, $set);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Resets auto-increment sequences after explicit-id inserts where the driver exposes them.
     *
     * @return void
     */
    private function resetSequences(): void
    {
        foreach (['pages', 'categories', 'tags', 'redirects'] as $tableName) {
            $table = $this->table($tableName);
            $statement = $this->db->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table);
            $maxId = $statement === false ? 0 : (int) ($statement->fetchColumn() ?: 0);

            if ($this->driver === 'mysql') {
                $this->db->exec('ALTER TABLE ' . $table . ' AUTO_INCREMENT = ' . max(1, $maxId + 1));
            } elseif ($this->driver === 'pgsql') {
                $sequence = $table . '_id_seq';
                $this->db->exec(
                    'SELECT setval(\'' . str_replace("'", "''", $sequence) . '\', '
                    . max(1, $maxId) . ', ' . ($maxId > 0 ? 'true' : 'false') . ')'
                );
            } elseif ($this->driver === 'sqlite') {
                try {
                    $statement = $this->db->prepare('UPDATE sqlite_sequence SET seq = :seq WHERE name = :name');
                    $statement->execute([':seq' => $maxId, ':name' => $table]);
                } catch (\Throwable) {
                    // SQLite only creates sqlite_sequence for AUTOINCREMENT tables.
                }
            }
        }
    }

    /**
     * Returns one validated application table name.
     *
     * @param string $name Base table name.
     * @return string Prefixed SQL table name.
     */
    private function table(string $name): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $name);
    }
}
