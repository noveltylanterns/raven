<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaState.php
 * Persists and compares a schema-ensure signature so hot paths can skip no-op ensure runs.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

/**
 * Persists and compares a schema-ensure signature so hot paths can skip no-op ensure runs.
 *
 * This is intentionally a local input-signature cache, not a user-facing schema-version
 * system. Raven's long-term release/update versioning plan lives in build/todo.md and
 * should stay the canonical home for version-bound upgrade shim design.
 */
final class SchemaState
{
    private string $root;
    private string $stateFile;
    private string $lockFile;
    private string $markerFile;
    private ?bool $dirtyCache = null;

    /**
     * Configures the state, lock, and marker file paths used by this store instance.
     *
     * @param string      $root       Project root used to resolve default state and marker paths.
     * @param string|null $stateFile  Absolute path to the persisted ensure-state file; defaults to the app-side path.
     * @param string|null $lockFile   Absolute path to the concurrency lock file; defaults to the app-side lock.
     * @param string|null $markerFile Absolute path to the invalidation marker file; defaults to the app-side marker.
     */
    public function __construct(string $root, ?string $stateFile = null, ?string $lockFile = null, ?string $markerFile = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->stateFile = $stateFile ?? ($this->root . '/private/dat/.schema_ensure_state.php');
        $this->lockFile = $lockFile ?? ($this->root . '/private/dat/.schema_ensure.lock');
        $this->markerFile = $markerFile ?? ($this->root . '/private/dat/.schema_ensure.marker');
    }

    /**
     * Runs the schema ensure callback only when the local invalidation marker is newer
     * than the last successful ensure state.
     *
     * The fast path avoids lock contention and filesystem walks on steady-state
     * requests. The exclusive lock still prevents a burst of concurrent requests from
     * all paying the same ensure work after one update or extension-enable change.
     *
     * @param string $driver Active database driver name.
     * @param string $prefix Active Raven table prefix.
     * @param callable(): void $ensure Callback that performs the actual schema ensure work.
     * @return void
     */
    public function ensureIfChanged(string $driver, string $prefix, callable $ensure): void
    {
        // Fast path exits when schema inputs and marker state have not changed.
        if (!$this->isDirty()) {
            return;
        }

        $lockHandle = @fopen($this->lockFile, 'c+');
        // If lock file cannot be opened, run ensure anyway for safety.
        if (!is_resource($lockHandle)) {
            // Fallback to the safe behavior when the local lock file cannot be opened.
            $ensure();
            $this->writeState($driver, $prefix);
            return;
        }

        // Lock-guard ensure so concurrent requests do not duplicate heavy schema work.
        try {
            // If lock acquisition fails, still run ensure to preserve correctness.
            if (!@flock($lockHandle, LOCK_EX)) {
                $ensure();
                $this->writeState($driver, $prefix);
                return;
            }

            // Re-check after lock acquisition without using the request-local cache.
            // Another request may have completed ensure while we were waiting.
            if (!$this->isDirty(false)) {
                return;
            }

            $ensure();
            $this->writeState($driver, $prefix);
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * Marks the schema state dirty so the next bootstrap re-runs ensure.
     *
     * Call this from update/install/extension-enable flows that can change schema inputs.
     *
     * @return void
     */
    public function invalidate(): void
    {
        // Invalidate request-local cache before mutating marker state.
        $this->dirtyCache = null;

        $markerDirectory = dirname($this->markerFile);
        // Ensure marker directory exists before touching/creating marker file.
        if (!is_dir($markerDirectory) && !mkdir($markerDirectory, 0775, true) && !is_dir($markerDirectory)) {
            return;
        }

        // Fallback to file_put_contents when touch cannot create marker file.
        if (@touch($this->markerFile) === false && @file_put_contents($this->markerFile, '', LOCK_EX) === false) {
            return;
        }

        clearstatcache(true, $this->markerFile);
        @chmod($this->markerFile, 0600);
    }

    /**
     * Returns true when the invalidation marker requires another ensure pass.
     *
     * @return bool True when schema ensure should run again.
     */
    private function isDirty(bool $useCache = true): bool
    {
        // Reuse request-local dirty result unless caller explicitly bypasses cache.
        if ($useCache && $this->dirtyCache !== null) {
            return $this->dirtyCache;
        }

        // Missing state file means ensure has never completed successfully.
        if (!is_file($this->stateFile)) {
            return $this->cacheDirtyResult(true, $useCache);
        }

        // Missing marker file is treated as dirty to force a safe ensure pass.
        if (!is_file($this->markerFile)) {
            return $this->cacheDirtyResult(true, $useCache);
        }

        $stateMtime = (int) (@filemtime($this->stateFile) ?: 0);
        $markerMtime = (int) (@filemtime($this->markerFile) ?: 0);
        // Invalid mtimes are treated as dirty because freshness cannot be proven.
        if ($stateMtime <= 0 || $markerMtime <= 0) {
            return $this->cacheDirtyResult(true, $useCache);
        }

        // Newer marker means a mutation happened after last successful ensure.
        if ($markerMtime > $stateMtime) {
            return $this->cacheDirtyResult(true, $useCache);
        }

        return $this->cacheDirtyResult(
            $this->latestSchemaSourceMtime() > $stateMtime,
            $useCache
        );
    }

    /**
     * Returns the newest mtime among core schema source files that should invalidate ensures.
     *
     * This keeps deployed schema refactors from being skipped when local marker files are
     * older than the last successful ensure state.
     *
     * @return int Latest relevant schema-source mtime, or 0 when none can be read.
     */
    private function latestSchemaSourceMtime(): int
    {
        $files = [
            $this->root . '/private/sys/Schema/SchemaBootstrap.php',
            $this->root . '/private/sys/Schema/SchemaBuilder.php',
            $this->root . '/private/sys/Schema/SchemaAuth.php',
            $this->root . '/private/sys/Schema/SchemaPipeline.php',
            $this->root . '/private/sys/Schema/SchemaExtension.php',
            $this->root . '/private/sys/Schema/SchemaInstaller.php',
        ];

        $latest = 0;
        // Track newest schema-source mtime across all schema pipeline files.
        foreach ($files as $file) {
            $mtime = (int) (@filemtime($file) ?: 0);
            // Keep the maximum readable mtime as the invalidation baseline.
            if ($mtime > $latest) {
                $latest = $mtime;
            }
        }

        return $latest;
    }

    /**
     * Persists one successful ensure stamp to local runtime state.
     *
     * The marker file is touched first, then the state file is written so the
     * state mtime naturally ends up newer than the marker mtime.
     *
     * @param string $driver Active database driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    private function writeState(string $driver, string $prefix): void
    {
        // Reset request-local cache before and after writing so future checks in this
        // request observe the new marker/state mtimes.
        $this->dirtyCache = null;

        $stateDirectory = dirname($this->stateFile);
        // Ensure state directory exists before writing ensure stamp.
        if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory)) {
            return;
        }

        $this->invalidate();

        $payload = "<?php\n\nreturn " . var_export([
            'driver' => strtolower(trim($driver)),
            'prefix' => $prefix,
            'ensured_at' => gmdate('c'),
        ], true) . ";\n";

        // Abort cache update when ensure stamp cannot be persisted.
        if (@file_put_contents($this->stateFile, $payload, LOCK_EX) === false) {
            return;
        }

        @chmod($this->stateFile, 0600);
        clearstatcache(true, $this->stateFile);
        $this->dirtyCache = false;
    }

    /**
     * Caches one dirty-check result when caching is enabled for this call.
     *
     * @param bool $dirty Computed dirty-state result.
     * @param bool $useCache Whether request-local caching is enabled for this call.
     * @return bool Dirty-state result.
     */
    private function cacheDirtyResult(bool $dirty, bool $useCache): bool
    {
        // Store request-local cache only when caller permits caching.
        if ($useCache) {
            $this->dirtyCache = $dirty;
        }

        return $dirty;
    }
}
