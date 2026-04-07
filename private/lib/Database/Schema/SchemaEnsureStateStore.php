<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use Raven\Lib\Extension\ExtensionRegistry;

/**
 * Persists and compares a schema-ensure signature so hot paths can skip no-op ensure runs.
 *
 * This is intentionally a local input-signature cache, not a user-facing schema-version
 * system. Raven's long-term release/update versioning plan lives in build/todo.md and
 * should stay the canonical home for version-bound upgrade shim design.
 */
final class SchemaEnsureStateStore
{
    private string $root;
    private string $stateFile;
    private string $lockFile;

    /**
     * @param string $root Project root used to resolve schema and state paths.
     * @param string|null $stateFile Optional absolute path to the persisted state file.
     * @param string|null $lockFile Optional absolute path to the lock file guarding concurrent ensures.
     */
    public function __construct(string $root, ?string $stateFile = null, ?string $lockFile = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->stateFile = $stateFile ?? ($this->root . '/private/dat/.schema_ensure_state.php');
        $this->lockFile = $lockFile ?? ($this->root . '/private/dat/.schema_ensure.lock');
    }

    /**
     * Runs the schema ensure callback only when the current signature changed.
     *
     * The exclusive lock prevents a burst of concurrent requests from all paying the
     * same expensive schema walk after one deployment or extension change.
     *
     * @param string $driver Active database driver name.
     * @param string $prefix Active Raven table prefix.
     * @param callable(): void $ensure Callback that performs the actual schema ensure work.
     * @return void
     */
    public function ensureIfChanged(string $driver, string $prefix, callable $ensure): void
    {
        $lockHandle = @fopen($this->lockFile, 'c+');
        if (!is_resource($lockHandle)) {
            // Fallback to the safe behavior when the local lock file cannot be opened.
            $ensure();
            return;
        }

        try {
            if (!@flock($lockHandle, LOCK_EX)) {
                $ensure();
                return;
            }

            $signature = $this->signature($driver, $prefix);
            if ($this->storedSignature() === $signature) {
                return;
            }

            $ensure();
            $this->writeState($signature);
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * Builds a deterministic signature for the currently relevant schema inputs.
     *
     * @param string $driver Active database driver name.
     * @param string $prefix Active Raven table prefix.
     * @return string Stable hash covering core schema files and enabled extension schema inputs.
     */
    public function signature(string $driver, string $prefix): string
    {
        $parts = [
            'driver=' . strtolower(trim($driver)),
            'prefix=' . $prefix,
        ];

        foreach ($this->coreSchemaFiles() as $file) {
            $parts[] = $this->fileSignaturePart($file);
        }

        $extensionStateFile = $this->root . '/private/dat/ext/.state.php';
        $parts[] = $this->fileSignaturePart($extensionStateFile);

        foreach (ExtensionRegistry::enabledDirectories($this->root, true) as $directory) {
            $parts[] = 'extension=' . $directory;
            $extensionRoot = $this->root . '/private/ext/' . $directory;
            $parts[] = $this->fileSignaturePart($extensionRoot . '/ext.json');
            $parts[] = $this->fileSignaturePart($extensionRoot . '/ext.php');
            $parts[] = $this->fileSignaturePart($extensionRoot . '/lib/schema.php');
        }

        return sha1(implode("\n", $parts));
    }

    /**
     * Returns the currently stored signature, or an empty string when no state exists yet.
     */
    private function storedSignature(): string
    {
        if (!is_file($this->stateFile)) {
            return '';
        }

        /** @var mixed $state */
        $state = require $this->stateFile;
        if (!is_array($state)) {
            return '';
        }

        $signature = trim((string) ($state['signature'] ?? ''));
        return $signature !== '' ? $signature : '';
    }

    /**
     * Persists one successful schema signature to local runtime state.
     *
     * @param string $signature Signature that completed a full ensure pass successfully.
     * @return void
     */
    private function writeState(string $signature): void
    {
        $payload = "<?php\n\nreturn " . var_export([
            'signature' => $signature,
            'ensured_at' => gmdate('c'),
        ], true) . ";\n";

        if (@file_put_contents($this->stateFile, $payload, LOCK_EX) === false) {
            return;
        }

        @chmod($this->stateFile, 0600);
    }

    /**
     * Returns all core schema files whose changes should invalidate the ensure signature.
     *
     * @return array<int, string>
     */
    private function coreSchemaFiles(): array
    {
        $files = glob($this->root . '/private/lib/Database/Schema/*.php') ?: [];
        sort($files);
        return array_values(array_filter($files, 'is_string'));
    }

    /**
     * Returns one stable file-signature part covering path, existence, size, and mtime.
     *
     * @param string $file Absolute file path.
     * @return string One signature fragment for this file.
     */
    private function fileSignaturePart(string $file): string
    {
        if (!is_file($file)) {
            return $file . '|missing';
        }

        $mtime = (int) (@filemtime($file) ?: 0);
        $size = (int) (@filesize($file) ?: 0);
        return $file . '|mtime=' . $mtime . '|size=' . $size;
    }
}
