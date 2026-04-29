<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Loads and validates extension `ext.php` bootstrap + storage contract data.
 */
final class ExtensionBootstrapContractResolver
{
    private ValidateManifest $manifestValidator;

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

        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'boot' => null,
                'scheduler' => false,
                'storage' => $this->emptyStorage(),
            ];
        }

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
        if ($rawStorage === null) {
            return [
                'valid' => true,
                'error' => '',
                'storage' => $this->emptyStorage(),
            ];
        }

        if (!is_array($rawStorage)) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage" must be an array when present.',
                'storage' => $this->emptyStorage(),
            ];
        }

        $allowedKeys = ['local', 'table', 'tables', 'aux', 'panel', 'public', 'bin'];
        foreach (array_keys($rawStorage) as $key) {
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
        if ($tables === null) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage.tables" must be a list of safe table suffix strings.',
                'storage' => $this->emptyStorage(),
            ];
        }
        if ($aux === null) {
            return [
                'valid' => false,
                'error' => 'ext.php "storage.aux" must be a list of safe root-level directory names.',
                'storage' => $this->emptyStorage(),
            ];
        }

        if ($table && $tables !== []) {
            return [
                'valid' => false,
                'error' => 'Use either ext.php storage "table" or "tables", not both.',
                'storage' => $this->emptyStorage(),
            ];
        }

        if ($public && $type !== 'module') {
            return [
                'valid' => false,
                'error' => 'Only module extensions may request public asset storage.',
                'storage' => $this->emptyStorage(),
            ];
        }

        if ($panel && !in_array($type, ['helper', 'content', 'module', 'system'], true)) {
            return [
                'valid' => false,
                'error' => 'Only helper/content/module/system extensions may request panel asset storage.',
                'storage' => $this->emptyStorage(),
            ];
        }

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
        if ($rawAux === null || $rawAux === false || $rawAux === '') {
            return [];
        }

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
        foreach ($rawAux as $entry) {
            if (!is_scalar($entry)) {
                return null;
            }

            $directory = strtolower(trim((string) $entry));
            if ($directory === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $directory) !== 1) {
                return null;
            }

            if (in_array($directory, $reserved, true)) {
                return null;
            }

            if (!isset($normalized[$directory])) {
                $normalized[$directory] = $directory;
            }
        }

        return array_values($normalized);
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

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
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }

        if (!is_array($raw)) {
            return null;
        }

        $suffixes = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                return null;
            }

            $suffix = strtolower(trim((string) $value));
            if ($suffix === '' || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $suffix) !== 1) {
                return null;
            }

            $suffixes[$suffix] = $suffix;
        }

        return array_values($suffixes);
    }
}
