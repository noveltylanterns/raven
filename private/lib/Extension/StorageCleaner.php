<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/StorageCleaner.php
 * Deletes extension-owned storage directories and database tables during uninstall.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

use PDO;
use Raven\Lib\Archive\Folder as ArchiveDelete;
use RuntimeException;

/**
 * Deletes extension-owned local storage directories and DB tables.
 */
final class StorageCleaner
{
    private string $projectRoot;
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ValidateManifest $manifestValidator;
    private ArchiveDelete $directoryTreeService;

    public function __construct(
        string $projectRoot,
        PDO $db,
        string $driver,
        string $prefix,
        ?ValidateManifest $manifestValidator = null,
        ?ArchiveDelete $directoryTreeService = null
    ) {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->db = $db;
        $this->driver = strtolower(trim($driver));
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
        $this->directoryTreeService = $directoryTreeService ?? new ArchiveDelete();
    }

    /**
     * @param array{
     *   local?: bool,
     *   table?: bool,
     *   tables?: array<int, string>,
     *   aux?: array<int, string>,
     *   panel?: bool,
     *   public?: bool,
     *   bin?: bool
     * } $storage
     */
    public function deleteStorageByContract(string $directoryName, array $storage): void
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for storage cleanup.');
        }

        if (!empty($storage['local'])) {
            $this->deleteDirectory($this->projectRoot . '/private/dat/ext/' . $directoryName, 'private/dat/ext/' . $directoryName);
        }

        foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
            if (!is_string($auxDirectory) || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $auxDirectory) !== 1) {
                continue;
            }

            $this->deleteDirectory($this->projectRoot . '/' . $auxDirectory, 'aux/' . $auxDirectory);
        }

        if (!empty($storage['panel'])) {
            $this->deleteDirectory($this->projectRoot . '/panel/ext/' . $directoryName, 'panel/ext/' . $directoryName);
        }

        if (!empty($storage['public'])) {
            $this->deleteDirectory($this->projectRoot . '/public/uploads/ext/' . $directoryName, 'public/uploads/ext/' . $directoryName);
        }

        if (!empty($storage['bin'])) {
            $this->removeBinSymlinks($directoryName);
        }

        if (!empty($storage['table']) || !empty($storage['tables'])) {
            $this->dropDatabaseTables($directoryName);
        }
    }

    /**
     * Removes symlinks from private/bin/ that point into the extension's bin/ directory.
     *
     * Only removes symlinks whose target resolves inside the extension's own bin/ directory,
     * so there is no risk of accidentally removing unrelated entries that happen to share a name.
     *
     * @param string $directoryName Extension directory name.
     */
    private function removeBinSymlinks(string $directoryName): void
    {
        $targetBin = $this->projectRoot . '/private/bin';
        $extensionBin = $this->projectRoot . '/private/ext/' . $directoryName . '/bin';

        if (!is_dir($targetBin)) {
            return;
        }

        $iterator = new \DirectoryIterator($targetBin);
        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isLink()) {
                continue;
            }

            $realTarget = realpath($item->getPathname());
            if ($realTarget === false) {
                // Dangling symlink — check by reading the link target string instead.
                $linkTarget = (string) readlink($item->getPathname());
                if (!str_starts_with($linkTarget, $extensionBin . '/')) {
                    continue;
                }
            } elseif (!str_starts_with($realTarget, $extensionBin . '/') && $realTarget !== $extensionBin) {
                continue;
            }

            unlink($item->getPathname());
        }
    }

    private function deleteDirectory(string $path, string $label): void
    {
        if (!is_dir($path)) {
            return;
        }

        $this->directoryTreeService->removeTree($path);
        if (is_dir($path)) {
            throw new RuntimeException('Failed to delete ' . $label . ' directory.');
        }
    }

    private function dropDatabaseTables(string $directoryName): void
    {
        $stem = $this->physicalTableStem($directoryName);
        $tables = $this->matchingTables($stem);
        if ($tables === []) {
            return;
        }

        usort($tables, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tables as $table) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $sql = 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
            if ($this->driver === 'pgsql') {
                $sql .= ' CASCADE';
            }

            $this->db->exec($sql);
        }
    }

    /**
     * @return array<int, string>
     */
    private function matchingTables(string $stem): array
    {
        $pattern = $this->likePrefix($stem) . '\\_%';

        if ($this->driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'SELECT name
                 FROM sqlite_master
                 WHERE type = :type
                   AND (name = :stem OR name LIKE :pattern ESCAPE \'\\\')
                 ORDER BY name ASC'
            );
            $stmt->execute([
                ':type' => 'table',
                ':stem' => $stem,
                ':pattern' => $pattern,
            ]);

            return array_values(array_filter(array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
            ), static fn (string $value): bool => $value !== ''));
        }

        if ($this->driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT table_name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = :table_type
                   AND (table_name = :stem OR table_name LIKE :pattern ESCAPE \'\\\')
                 ORDER BY table_name ASC'
            );
            $stmt->execute([
                ':table_type' => 'BASE TABLE',
                ':stem' => $stem,
                ':pattern' => $pattern,
            ]);

            return array_values(array_filter(array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
            ), static fn (string $value): bool => $value !== ''));
        }

        $stmt = $this->db->prepare(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND table_type = :table_type
               AND (table_name = :stem OR table_name LIKE :pattern ESCAPE \'\\\')
             ORDER BY table_name ASC'
        );
        $stmt->execute([
            ':table_type' => 'BASE TABLE',
            ':stem' => $stem,
            ':pattern' => $pattern,
        ]);

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        ), static fn (string $value): bool => $value !== ''));
    }

    private function physicalTableStem(string $directoryName): string
    {
        $normalized = strtolower(trim($directoryName));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $this->prefix . 'ext_' . $normalized;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function likePrefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
