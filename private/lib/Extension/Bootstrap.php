<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Bootstrap.php
 * Loads and validates extension `ext.php` bootstrap + storage contract data.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Loads and validates extension `ext.php` bootstrap + storage contract data.
 */
final class Bootstrap
{
    private ValidateManifest $manifestValidator;

    /**
     * Prepares the bootstrap resolver with an optional manifest validator override.
     *
     * @param ValidateManifest|null $manifestValidator Optional validator; defaults to a fresh instance.
     */
    public function __construct(?ValidateManifest $manifestValidator = null)
    {
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
    }

    /**
     * Loads and validates an extension bootstrap provider from `ext.php`.
     *
     * @param string                    $root          Project root path.
     * @param string                    $directoryName Extension directory name.
     * @param array<string, mixed>|null $manifest      Pre-loaded manifest array, or null to read from disk.
     * @return array{
     *   valid: bool,
     *   error: string,
     *   boot: callable|null,
     *   scheduler: bool,
     *   storage: array{
     *     local: bool,
     *     table: bool,
     *     tables: array<int, string>,
     *     aux: array<int, string>,
     *     panel: bool,
     *     public: bool,
     *     bin: bool
     *   }
     * }
     */
    public function resolve(string $root, string $directoryName, ?array $manifest = null): array
    {
        // Reject unsafe extension directory names before any filesystem access.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        $extensionRoot = rtrim($root, '/\\') . '/private/ext/' . $directoryName;
        $providerPath = $extensionRoot . '/ext.php';
        $manifest = is_array($manifest) ? $manifest : ($this->manifestValidator->readManifest($root, $directoryName) ?? []);
        $type = strtolower(trim((string) ($manifest['type'] ?? 'content')));

        // Missing ext.php is valid and simply means no runtime bootstrap contract.
        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        // Load ext.php defensively so provider exceptions surface as validation errors.
        try {
            /** @var mixed $provider */
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'ext.php threw an error while loading.',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        // Provider contract must be an array for downstream key lookups.
        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'ext.php must return an array contract.',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        $boot = $provider['boot'] ?? null;
        // Boot hook, when present, must be callable.
        if ($boot !== null && !is_callable($boot)) {
            return [
                'valid' => false,
                'error' => 'ext.php "boot" must be callable when present.',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        // Whether this extension opts in to the scheduler (causes core to load cron.php via rvn-cron).
        $scheduler = $this->boolish($provider['scheduler'] ?? false);

        $storage = $this->normalizeStorageRequest($provider['storage'] ?? null, $type);
        // Bubble storage-contract validation failures with a normalized error payload.
        if (!$storage['valid']) {
            return [
                'valid' => false,
                'error' => (string) ($storage['error'] ?? 'Invalid extension storage contract.'),
                'boot' => is_callable($boot) ? $boot : null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'boot' => is_callable($boot) ? $boot : null,
            'scheduler' => $scheduler,
            'storage' => $storage['storage'],
        ];
    }

    /**
     * Validates and normalizes the `storage` declaration from an extension's array contract.
     *
     * @param mixed  $rawStorage Raw value from ext.php `storage` key.
     * @param string $type       Normalized extension type (helper/content/framework/module/system).
     * @return array{
     *   valid: bool,
     *   error: string,
     *   storage: array{
     *     local: bool,
     *     table: bool,
     *     tables: array<int, string>,
     *     aux: array<int, string>,
     *     panel: bool,
     *     public: bool,
     *     bin: bool
     *   }
     * }
     */
    public function normalizeStorageRequest(mixed $rawStorage, string $type): array
    {
        // Omitted storage section means extension requests no extra storage resources.
        if ($rawStorage === null) {
            return [
                'valid' => true,
                'error' => '',
                'storage' => $this->emptyStorage(),
            ];
        }

        // Storage declaration must be an associative array when supplied.
        if (!is_array($rawStorage)) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage" must be an array when present.',
                'storage' => $this->emptyStorage(),
            ];
        }

        $allowedKeys = ['local', 'table', 'tables', 'aux', 'panel', 'public', 'bin'];
        // Reject unknown storage keys to keep the contract strict and forward-safe.
        foreach (array_keys($rawStorage) as $key) {
            // Every key must be a known string option.
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                return [
                    'valid' => false,
                    'error' => 'ext.php "storage" contains an unknown option.',
                    'storage' => $this->emptyStorage(),
                ];
            }
        }

        $local = $this->boolish($rawStorage['local'] ?? false);
        $table = $this->boolish($rawStorage['table'] ?? false);
        $aux = $this->normalizeAuxDirectories($rawStorage['aux'] ?? []);
        $panel = $this->boolish($rawStorage['panel'] ?? false);
        $public = $this->boolish($rawStorage['public'] ?? false);
        $bin = $this->boolish($rawStorage['bin'] ?? false);
        $tables = $this->normalizeTableSuffixes($rawStorage['tables'] ?? []);
        // Invalid table-suffix lists are rejected with an explicit contract error.
        if ($tables === null) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage.tables" must be a list of safe table suffix strings.',
                'storage' => $this->emptyStorage(),
            ];
        }
        // Invalid aux-directory lists are rejected with an explicit contract error.
        if ($aux === null) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage.aux" must be a list of safe root-level directory names.',
                'storage' => $this->emptyStorage(),
            ];
        }

        // Legacy `table` and modern `tables` options are mutually exclusive.
        if ($table && $tables !== []) {
            return [
                'valid' => false,
                'error' => 'Use either ext.php storage "table" or "tables", not both.',
                'storage' => $this->emptyStorage(),
            ];
        }

        // Public asset storage is restricted to module extensions.
        if ($public && $type !== 'module') {
            return [
                'valid' => false,
                'error' => 'Only module extensions may request public asset storage.',
                'storage' => $this->emptyStorage(),
            ];
        }

        // Panel asset storage is restricted to supported extension types.
        if ($panel && !in_array($type, ['helper', 'content', 'module', 'system'], true)) {
            return [
                'valid' => false,
                'error' => 'Only helper/content/module/system extensions may request panel asset storage.',
                'storage' => $this->emptyStorage(),
            ];
        }

        // Framework extensions cannot expose panel/public assets.
        if ($type === 'framework' && ($panel || $public)) {
            return [
                'valid' => false,
                'error' => 'Framework extensions cannot request panel/public asset storage.',
                'storage' => $this->emptyStorage(),
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'storage' => [
                'local' => $local,
                'table' => $table,
                'tables' => $tables,
                'aux' => $aux,
                'panel' => $panel,
                'public' => $public,
                'bin' => $bin,
            ],
        ];
    }

    /**
     * Returns an all-false storage contract for use in error/no-storage cases.
     *
     * @return array{local: bool, table: bool, tables: array<int, string>, aux: array<int, string>, panel: bool, public: bool, bin: bool}
     */
    private function emptyStorage(): array
    {
        return [
            'local' => false,
            'table' => false,
            'tables' => [],
            'aux' => [],
            'panel' => false,
            'public' => false,
            'bin' => false,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeAuxDirectories(mixed $rawAux): ?array
    {
        // Empty/falsey aux declarations normalize to an empty list.
        if ($rawAux === null || $rawAux === false || $rawAux === '') {
            return [];
        }

        // Aux declarations must be array lists.
        if (!is_array($rawAux)) {
            return null;
        }

        $reserved = [
            'composer',
            'debug',
            'docs',
            'panel',
            'private',
            'public',
        ];

        $normalized = [];
        // Normalize, validate, and deduplicate requested aux directory names.
        foreach ($rawAux as $entry) {
            // Reject non-scalar entries in aux directory lists.
            if (!is_scalar($entry)) {
                return null;
            }

            $directory = strtolower(trim((string) $entry));
            // Enforce safe root-directory naming rules for aux entries.
            if ($directory === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $directory) !== 1) {
                return null;
            }

            // Block reserved top-level project directory names.
            if (in_array($directory, $reserved, true)) {
                return null;
            }

            // Keep one canonical copy of each normalized directory name.
            if (!isset($normalized[$directory])) {
                $normalized[$directory] = $directory;
            }
        }

        return array_values($normalized);
    }

    /**
     * Coerces a scalar to bool, accepting common truthy string tokens.
     *
     * @param mixed $value Raw value from an ext.php contract key.
     * @return bool Resolved boolean value.
     */
    private function boolish(mixed $value): bool
    {
        // Preserve boolean values as-is.
        if (is_bool($value)) {
            return $value;
        }

        // Numeric values use non-zero truth semantics.
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        // Non-string/non-numeric scalars are treated as false.
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeTableSuffixes(mixed $raw): ?array
    {
        // Empty/falsey table declarations normalize to an empty list.
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }

        // Table declarations must be array lists.
        if (!is_array($raw)) {
            return null;
        }

        $suffixes = [];
        // Normalize, validate, and deduplicate table suffix declarations.
        foreach ($raw as $value) {
            // Reject non-scalar values in table suffix lists.
            if (!is_scalar($value)) {
                return null;
            }

            $suffix = strtolower(trim((string) $value));
            // Enforce safe SQL suffix naming constraints.
            if ($suffix === '' || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $suffix) !== 1) {
                return null;
            }

            $suffixes[$suffix] = $suffix;
        }

        return array_values($suffixes);
    }
}
