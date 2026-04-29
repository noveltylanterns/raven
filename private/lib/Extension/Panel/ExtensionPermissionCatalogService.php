<?php

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Security\InputSanitizer;

/**
 * Discovers extension panel-permission metadata and manages stable bit allocation.
 */
final class ExtensionPermissionCatalogService
{
    private StateRead $stateStore;
    private InputSanitizer $input;

    public function __construct(StateRead $stateStore, InputSanitizer $input)
    {
        $this->stateStore = $stateStore;
        $this->input = $input;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function defaultPermissionLevels(string $extensionName): array
    {
        $label = trim($extensionName);
        $label = $label !== '' ? 'Access ' . $label : 'Access';

        return [[
            'key' => 'access',
            'label' => $label,
        ]];
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function normalizePermissionLevels(mixed $rawLevels, string $extensionName): array
    {
        $normalized = [];
        if (is_array($rawLevels)) {
            foreach ($rawLevels as $key => $entry) {
                $levelKey = '';
                $label = '';
                if (is_array($entry)) {
                    $levelKey = strtolower(trim((string) ($entry['key'] ?? '')));
                    $label = trim((string) ($entry['label'] ?? ''));
                } elseif (is_string($key)) {
                    $levelKey = strtolower(trim($key));
                    $label = trim((string) $entry);
                }

                if ($levelKey === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $label = $this->input->text($label, 80);
                if ($label === '') {
                    continue;
                }

                $normalized[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $label,
                ];
                if (count($normalized) >= 16) {
                    break;
                }
            }
        }

        if ($normalized === []) {
            return $this->defaultPermissionLevels($extensionName);
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, string> $directoryFilter
     * @param callable(string): array<string, mixed>|null $manifestReader
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }>
     */
    public function panelPermissionMapForDirectories(array $directoryFilter, callable $manifestReader): array
    {
        $catalog = $this->catalog($directoryFilter, $manifestReader);
        $bitMap = $this->ensurePermissionBits($catalog);
        $result = [];

        foreach ($catalog as $directory => $meta) {
            $levels = [];
            foreach ($meta['levels'] as $level) {
                $levelKey = (string) ($level['key'] ?? '');
                if ($levelKey === '') {
                    continue;
                }

                $bit = (int) (($bitMap[$directory][$levelKey] ?? 0));
                if ($bit <= 0) {
                    continue;
                }

                $levels[] = [
                    'key' => $levelKey,
                    'label' => (string) ($level['label'] ?? $levelKey),
                    'bit' => $bit,
                ];
            }

            if ($levels === []) {
                continue;
            }

            $result[$directory] = [
                'name' => (string) ($meta['name'] ?? $directory),
                'type' => (string) ($meta['type'] ?? 'content'),
                'default_level' => (string) ($meta['default_level'] ?? ($levels[0]['key'] ?? 'access')),
                'levels' => $levels,
            ];
        }

        ksort($result);
        return $result;
    }

    /**
     * @param array<int, string> $directoryFilter
     * @param callable(string): array<string, mixed>|null $manifestReader
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }>
     */
    public function catalog(array $directoryFilter, callable $manifestReader): array
    {
        $this->stateStore->ensureDirectory();
        $filter = [];
        foreach ($directoryFilter as $directory) {
            $normalized = strtolower(trim((string) $directory));
            if ($this->isSafeExtensionDirectoryName($normalized)) {
                $filter[$normalized] = true;
            }
        }

        $entries = scandir($this->stateStore->basePath()) ?: [];
        $catalog = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!$this->isSafeExtensionDirectoryName($entry)) {
                continue;
            }
            if ($filter !== [] && !isset($filter[$entry])) {
                continue;
            }

            $extensionPath = $this->stateStore->basePath() . '/' . $entry;
            if (!is_dir($extensionPath) || !Resolver::hasProvider($extensionPath, 'routes_panel.php')) {
                continue;
            }

            $manifest = $manifestReader($extensionPath);
            if (!is_array($manifest) || !($manifest['valid'] ?? false)) {
                continue;
            }

            $type = strtolower(trim((string) ($manifest['type'] ?? 'content')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            if ($isSystemType || !in_array($type, ['helper', 'content', 'module'], true)) {
                continue;
            }

            $levels = is_array($manifest['permission_levels'] ?? null)
                ? $manifest['permission_levels']
                : $this->defaultPermissionLevels((string) ($manifest['name'] ?? $entry));
            $normalizedLevels = [];
            foreach ($levels as $level) {
                if (!is_array($level)) {
                    continue;
                }

                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                $label = trim((string) ($level['label'] ?? ''));
                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $label = $this->input->text($label, 80);
                if ($label === '') {
                    continue;
                }

                $normalizedLevels[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $label,
                ];
            }
            if ($normalizedLevels === []) {
                foreach ($this->defaultPermissionLevels((string) ($manifest['name'] ?? $entry)) as $defaultLevel) {
                    $normalizedLevels[(string) $defaultLevel['key']] = $defaultLevel;
                }
            }

            $defaultLevel = strtolower(trim((string) ($manifest['default_permission_level'] ?? '')));
            if ($defaultLevel === '' || !isset($normalizedLevels[$defaultLevel])) {
                $firstLevel = array_values($normalizedLevels)[0] ?? ['key' => 'access'];
                $defaultLevel = (string) ($firstLevel['key'] ?? 'access');
            }

            $catalog[$entry] = [
                'name' => (string) ($manifest['name'] ?? $entry),
                'type' => $type,
                'default_level' => $defaultLevel,
                'levels' => array_values($normalizedLevels),
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @param array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }> $catalog
     * @return array<string, array<string, int>>
     */
    public function ensurePermissionBits(array $catalog): array
    {
        $existing = $this->stateStore->loadPermissionBitsMap();
        $normalized = [];
        $usedBits = [];
        foreach ($existing as $directory => $levels) {
            $normalized[$directory] = [];
            foreach ($levels as $levelKey => $bit) {
                $candidateBit = (int) $bit;
                if ($candidateBit <= 0 || !$this->isPowerOfTwoBit($candidateBit) || isset($usedBits[$candidateBit])) {
                    continue;
                }

                $normalized[$directory][(string) $levelKey] = $candidateBit;
                $usedBits[$candidateBit] = true;
            }
            if ($normalized[$directory] === []) {
                unset($normalized[$directory]);
            }
        }

        $changed = $normalized !== $existing;
        foreach ($catalog as $directory => $meta) {
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                if ($levelKey === '') {
                    continue;
                }

                $assignedBit = (int) ($normalized[$directory][$levelKey] ?? 0);
                if ($assignedBit > 0 && $this->isPowerOfTwoBit($assignedBit) && isset($usedBits[$assignedBit])) {
                    continue;
                }

                $nextBit = $this->nextAvailablePermissionBit($usedBits);
                $normalized[$directory][$levelKey] = $nextBit;
                $usedBits[$nextBit] = true;
                $changed = true;
            }
        }

        if ($changed) {
            $this->stateStore->savePermissionBitsMap($normalized);
        }

        return $normalized;
    }

    /**
     * @param array<int, bool> $usedBits
     */
    private function nextAvailablePermissionBit(array $usedBits): int
    {
        $bit = PanelAccess::EXTENSION_PERMISSION_START;
        while (isset($usedBits[$bit])) {
            if ($bit > intdiv(PHP_INT_MAX, 2)) {
                throw new \RuntimeException('No free extension permission bits remain.');
            }

            $bit *= 2;
        }

        return $bit;
    }

    private function isPowerOfTwoBit(int $bit): bool
    {
        if ($bit <= 0) {
            return false;
        }

        return ($bit & ($bit - 1)) === 0;
    }

    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }
}
