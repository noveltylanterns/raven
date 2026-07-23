<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/StorageCleaner.php
 * Deletes extension-owned storage directories and database tables during uninstall.
 * Docs: https://lanterns.io/raven
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

    /**
     * Prepares the storage cleaner for one project tree.
     *
     * @param string $projectRoot Absolute project root path.
     * @param PDO $db Active database connection for DROP TABLE operations.
     * @param string $driver Database driver token (mysql, sqlite, pgsql).
     * @param string $prefix Table prefix used to derive the extension table stem.
     * @param ValidateManifest|null $manifestValidator Optional validator; defaults to a fresh instance.
     * @param ArchiveDelete|null $directoryTreeService Optional directory-removal helper; defaults to a fresh instance.
     */
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
     * Deletes all extension-owned storage that was requested in the bootstrap storage contract.
     *
     * Removes local data directories, aux directories, panel/public asset directories, bin
     * launchers, and database tables according to the flags set in `$storage`. Only paths
     * that were provisioned by the contract are touched; unrelated directories are never deleted.
     *
     * @param string $directoryName Extension directory name (slug).
     * @param array{
     *   local?: bool,
     *   table?: bool,
     *   tables?: array<int, string>,
     *   aux?: array<int, string>,
     *   panel?: bool,
     *   public?: bool,
     *   bin?: bool
     * } $storage Storage contract flags from Bootstrap::resolve().
     * @return void
     *
     * @throws RuntimeException When a directory or table cannot be removed.
     */
    public function deleteStorageByContract(string $directoryName, array $storage): void
    {
        // Storage cleanup requires a safe extension directory slug.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for storage cleanup.');
        }

        // Remove extension-local data directory when requested by contract.
        if (!empty($storage['local'])) {
            $this->deleteDirectory($this->projectRoot . '/private/dat/ext/' . $directoryName, 'private/dat/ext/' . $directoryName);
        }

        // Remove each declared auxiliary directory.
        foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
            // Skip malformed/unsafe aux directory declarations.
            if (!is_string($auxDirectory) || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $auxDirectory) !== 1) {
                continue;
            }

            $this->deleteDirectory($this->projectRoot . '/' . $auxDirectory, 'aux/' . $auxDirectory);
        }

        // Remove panel asset directory when requested by contract.
        if (!empty($storage['panel'])) {
            $this->deleteDirectory($this->projectRoot . '/panel/ext/' . $directoryName, 'panel/ext/' . $directoryName);
        }

        // Remove public uploads directory when requested by contract.
        if (!empty($storage['public'])) {
            $this->deleteDirectory($this->projectRoot . '/public/uploads/ext/' . $directoryName, 'public/uploads/ext/' . $directoryName);
        }

        // Remove extension-owned bin launchers when bin storage was requested.
        if (!empty($storage['bin'])) {
            $this->removeBinLaunchers($directoryName);
        }

        // Drop extension-owned tables when table storage was requested.
        if (!empty($storage['table']) || !empty($storage['tables'])) {
            $this->dropDatabaseTables($directoryName);
        }
    }

    /**
     * Removes generated launchers and legacy symlinks from private/bin.
     *
     * Only entries owned by the requested extension are removed; unrelated operator files and
     * first-party documentation aliases elsewhere in the project remain untouched.
     *
     * @param string $directoryName Extension directory name.
     * @return void
     */
    private function removeBinLaunchers(string $directoryName): void
    {
        $targetBin = $this->projectRoot . '/private/bin';
        $extensionBin = $this->projectRoot . '/private/ext/' . $directoryName . '/bin';

        // No-op when the central private/bin directory does not exist.
        if (!is_dir($targetBin)) {
            return;
        }

        $iterator = new \DirectoryIterator($targetBin);
        // Scan private/bin entries and remove only extension-owned launchers or old aliases.
        foreach ($iterator as $item) {
            // Skip dot entries.
            if ($item->isDot()) {
                continue;
            }

            $path = $item->getPathname();
            if (!$item->isLink()) {
                $content = is_file($path) ? file_get_contents($path) : false;
                if ($content === false || !str_contains($content, 'RAVEN EXTENSION BIN LAUNCHER: ' . $directoryName . '/' . $item->getFilename())) {
                    continue;
                }
                if (!unlink($path)) {
                    throw new RuntimeException('Failed to remove extension bin launcher: ' . $path);
                }
                continue;
            }

            $realTarget = realpath($path);
            // Resolve dangling links by raw target string instead of realpath().
            if ($realTarget === false) {
                $linkTarget = (string) readlink($path);
                if (!str_starts_with($linkTarget, '../ext/' . $directoryName . '/bin/')) {
                    continue;
                }
            } elseif (!str_starts_with($realTarget, $extensionBin . '/') && $realTarget !== $extensionBin) {
                continue;
            }

            if (!unlink($path)) {
                throw new RuntimeException('Failed to remove legacy extension bin symlink: ' . $path);
            }
        }
    }

    /**
     * Removes one directory tree when it exists; throws on failure.
     *
     * @param string $path Absolute path to the directory to remove.
     * @param string $label Human-readable label used in the exception message.
     * @return void
     *
     * @throws RuntimeException When the directory exists but cannot be fully removed.
     */
    private function deleteDirectory(string $path, string $label): void
    {
        // Remove a bucket symlink itself without ever traversing into its target.
        if (is_link($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Failed to remove symlinked ' . $label . ' bucket.');
            }
            return;
        }

        // Missing directories are already effectively cleaned.
        if (!is_dir($path)) {
            return;
        }

        $this->directoryTreeService->removeTree($path);
        // Treat remaining directory presence as deletion failure.
        if (is_dir($path)) {
            throw new RuntimeException('Failed to delete ' . $label . ' directory.');
        }
    }

    /**
     * Drops all database tables whose names match the extension's table stem.
     *
     * Tables are resolved dynamically via information_schema / sqlite_master so the
     * drop is always consistent with what was actually created, regardless of whether
     * `table` or `tables` was used in the bootstrap contract.
     *
     * @param string $directoryName Extension directory name (slug).
     * @return void
     */
    private function dropDatabaseTables(string $directoryName): void
    {
        $stem = $this->physicalTableStem($directoryName);
        $tables = $this->matchingTables($stem);
        // No matching tables means there is nothing to drop.
        if ($tables === []) {
            return;
        }

        usort($tables, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        // Drop longest names first to avoid prefix-related dependency edge cases.
        foreach ($tables as $table) {
            // Drop only safe SQL identifiers.
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $sql = 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
            // PostgreSQL may require CASCADE for dependent objects.
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

        // SQLite catalogs tables in sqlite_master.
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

        // MySQL catalogs tables via information_schema.tables + DATABASE().
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

    /**
     * Derives the physical table-name stem for one extension directory.
     *
     * Maps the extension slug to the canonical `{prefix}ext_{slug}` form used by
     * the schema provisioner, normalizing underscores and stripping unsafe characters.
     *
     * @param string $directoryName Extension directory name (slug).
     * @return string Physical table stem, e.g. `rvn_ext_contact`.
     */
    private function physicalTableStem(string $directoryName): string
    {
        $normalized = strtolower(trim($directoryName));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $this->prefix . 'ext_' . $normalized;
    }

    /**
     * Quotes one SQL identifier according to the active database driver.
     *
     * @param string $identifier Unquoted identifier (table name).
     * @return string Driver-appropriate quoted identifier.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return $this->driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Escapes a string for safe use as a LIKE pattern prefix.
     *
     * @param string $value Unescaped value to use as the prefix.
     * @return string Escaped LIKE prefix safe for prepared-statement patterns.
     */
    private function likePrefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
