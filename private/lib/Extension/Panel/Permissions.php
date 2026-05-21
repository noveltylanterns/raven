<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Panel/Permissions.php
 * Extension panel-permission catalog: discovery, normalization, and stable bit allocation.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Security\InputSanitizer;

/**
 * Discovers extension panel-permission metadata and manages stable bit allocation.
 */
final class Permissions
{
    private StateRead $stateStore;
    private InputSanitizer $input;

    /**
     * @param StateRead $stateStore Shared extension state reader for extension-directory enumeration.
     * @param InputSanitizer $input Shared sanitizer for permission level labels.
     */
    public function __construct(StateRead $stateStore, InputSanitizer $input)
    {
        $this->stateStore = $stateStore;
        $this->input = $input;
    }

    /**
     * Returns the default single-level permission set for an extension declaring no custom levels.
     *
     * @param string $extensionName Human-readable extension name used to label the access level.
     * @return array<int, array{key: string, label: string}> Single-entry permission level array.
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
     * Normalizes raw panel_permissions manifest data into a validated level array.
     *
     * Falls back to the default single-level set when the raw data is absent or invalid.
     *
     * @param mixed $rawLevels Raw panel_permissions value from ext.json (may be any type).
     * @param string $extensionName Human-readable extension name used for the fallback default level label.
     * @return array<int, array{key: string, label: string}> Normalized permission level array.
     */
    public function normalizePermissionLevels(mixed $rawLevels, string $extensionName): array
    {
        $normalized = [];
        // Parse custom permission levels only when manifest data is an array.
        if (is_array($rawLevels)) {
            // Normalize list/object-style entries into key/label records.
            foreach ($rawLevels as $key => $entry) {
                $levelKey = '';
                $label = '';
                // Array entries may explicitly define key/label fields.
                if (is_array($entry)) {
                    $levelKey = strtolower(trim((string) ($entry['key'] ?? '')));
                    $label = trim((string) ($entry['label'] ?? ''));
                // Associative string-key entries map key => label.
                } elseif (is_string($key)) {
                    $levelKey = strtolower(trim($key));
                    $label = trim((string) $entry);
                }

                // Keep only safe permission-level keys.
                if ($levelKey === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                // Default blank labels from a humanized key fallback.
                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $label = $this->input->text($label, 80);
                // Skip levels whose label sanitizes to empty.
                if ($label === '') {
                    continue;
                }

                $normalized[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $label,
                ];
                // Cap custom levels to keep bit allocation bounded.
                if (count($normalized) >= 16) {
                    break;
                }
            }
        }

        // Fall back to a single default level when no valid custom levels remain.
        if ($normalized === []) {
            return $this->defaultPermissionLevels($extensionName);
        }

        return array_values($normalized);
    }

    /**
     * Returns the full permission map with stable bit assignments for a set of extension directories.
     *
     * @param array<int, string> $directoryFilter Extension directory slugs to include; empty array includes all.
     * @param callable(string): array<string, mixed> $manifestReader Callback returning one extension manifest payload.
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }> Permission map keyed by extension directory slug.
     */
    public function extensionPermissionMap(array $directoryFilter, callable $manifestReader): array
    {
        $catalog = $this->catalog($directoryFilter, $manifestReader);
        $bitMap = $this->ensurePermissionBits($catalog);
        $result = [];

        // Attach stable bit assignments to each catalog level.
        foreach ($catalog as $directory => $meta) {
            $levels = [];
            // Keep only levels with valid keys and allocated bits.
            foreach ($meta['levels'] as $level) {
                $levelKey = (string) ($level['key'] ?? '');
                // Ignore malformed level rows missing a key.
                if ($levelKey === '') {
                    continue;
                }

                $bit = (int) (($bitMap[$directory][$levelKey] ?? 0));
                // Ignore levels without a positive assigned bit.
                if ($bit <= 0) {
                    continue;
                }

                $levels[] = [
                    'key' => $levelKey,
                    'label' => (string) ($level['label'] ?? $levelKey),
                    'bit' => $bit,
                ];
            }

            // Skip extension entries that ended up with no usable levels.
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
     * Builds a permission catalog entry for each eligible enabled extension directory.
     *
     * Only extensions that have a routes_panel.php provider and are non-system content/helper/module
     * types are included. Results are sorted by directory slug.
     *
     * @param array<int, string> $directoryFilter Extension directory slugs to include; empty array includes all.
     * @param callable(string): array<string, mixed> $manifestReader Callback returning one extension manifest payload.
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }> Catalog entries keyed by extension directory slug.
     */
    public function catalog(array $directoryFilter, callable $manifestReader): array
    {
        $this->stateStore->ensureDirectory();
        $filter = [];
        // Normalize caller-supplied directory filter to safe lowercase slugs.
        foreach ($directoryFilter as $directory) {
            $normalized = strtolower(trim((string) $directory));
            // Keep only safe directory names in the filter map.
            if ($this->isSafeDirectoryName($normalized)) {
                $filter[$normalized] = true;
            }
        }

        $entries = scandir($this->stateStore->basePath()) ?: [];
        $catalog = [];
        // Walk extension directories and collect eligible panel permission metadata.
        foreach ($entries as $entry) {
            // Skip pseudo entries and hidden directories.
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            // Ignore unsafe directory names.
            if (!$this->isSafeDirectoryName($entry)) {
                continue;
            }
            // Apply optional caller filter when present.
            if ($filter !== [] && !isset($filter[$entry])) {
                continue;
            }

            $extensionPath = $this->stateStore->basePath() . '/' . $entry;
            // Include only real extension dirs that expose panel routes.
            if (!is_dir($extensionPath) || !Resolver::hasProvider($extensionPath, 'routes_panel.php')) {
                continue;
            }

            $manifest = $manifestReader($extensionPath);
            // Skip missing/invalid manifests.
            if (!is_array($manifest) || !($manifest['valid'] ?? false)) {
                continue;
            }

            $type = strtolower(trim((string) ($manifest['type'] ?? 'content')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            // Keep only non-system helper/content/module extension types.
            if ($isSystemType || !in_array($type, ['helper', 'content', 'module'], true)) {
                continue;
            }

            $levels = is_array($manifest['permission_levels'] ?? null)
                ? $manifest['permission_levels']
                : $this->defaultPermissionLevels((string) ($manifest['name'] ?? $entry));
            $normalizedLevels = [];
            // Normalize and validate manifest-provided permission levels.
            foreach ($levels as $level) {
                // Skip malformed level rows.
                if (!is_array($level)) {
                    continue;
                }

                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                // Keep only safe level-key patterns.
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                $label = trim((string) ($level['label'] ?? ''));
                // Default blank labels from a humanized key fallback.
                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $label = $this->input->text($label, 80);
                // Skip levels whose label sanitizes to empty.
                if ($label === '') {
                    continue;
                }

                $normalizedLevels[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $label,
                ];
            }
            // Fall back to default levels when custom levels normalize to empty.
            if ($normalizedLevels === []) {
                // Re-index defaults by key for consistent downstream behavior.
                foreach ($this->defaultPermissionLevels((string) ($manifest['name'] ?? $entry)) as $defaultLevel) {
                    $normalizedLevels[(string) $defaultLevel['key']] = $defaultLevel;
                }
            }

            $defaultLevel = strtolower(trim((string) ($manifest['default_permission_level'] ?? '')));
            // Ensure default level points at an existing normalized key.
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
     * Ensures stable power-of-two bit assignments exist for every level in the catalog.
     *
     * Reads existing bit assignments from state, validates them, and allocates new bits for
     * any levels not yet assigned. Persists only when the bit map changes.
     *
     * @param array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }> $catalog Permission catalog returned by `catalog()`.
     * @return array<string, array<string, int>> Bit map keyed by directory then level key.
     */
    public function ensurePermissionBits(array $catalog): array
    {
        $existing = $this->stateStore->loadPermissionBitsMap();
        $normalized = [];
        $usedBits = [];
        // Normalize persisted map: keep unique positive power-of-two assignments only.
        foreach ($existing as $directory => $levels) {
            $normalized[$directory] = [];
            // Validate each stored level-bit pair for this directory.
            foreach ($levels as $levelKey => $bit) {
                $candidateBit = (int) $bit;
                // Drop invalid, duplicate, or non-power-of-two bit assignments.
                if ($candidateBit <= 0 || !$this->isPowerOfTwo($candidateBit) || isset($usedBits[$candidateBit])) {
                    continue;
                }

                $normalized[$directory][(string) $levelKey] = $candidateBit;
                $usedBits[$candidateBit] = true;
            }
            // Remove directory entries that end up with no valid assignments.
            if ($normalized[$directory] === []) {
                unset($normalized[$directory]);
            }
        }

        $changed = $normalized !== $existing;
        // Ensure every catalog level has a stable assigned bit.
        foreach ($catalog as $directory => $meta) {
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            // Allocate bits only for valid normalized level keys.
            foreach ($levels as $level) {
                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                // Skip malformed levels lacking a key.
                if ($levelKey === '') {
                    continue;
                }

                $assignedBit = (int) ($normalized[$directory][$levelKey] ?? 0);
                // Keep existing valid assigned bits without reallocation.
                if ($assignedBit > 0 && $this->isPowerOfTwo($assignedBit) && isset($usedBits[$assignedBit])) {
                    continue;
                }

                $nextBit = $this->nextAvailablePermissionBit($usedBits);
                $normalized[$directory][$levelKey] = $nextBit;
                $usedBits[$nextBit] = true;
                $changed = true;
            }
        }

        // Persist assignments only when normalization/allocation changed the map.
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
        // Walk powers of two until an unused bit slot is found.
        while (isset($usedBits[$bit])) {
            // Abort when shifting would overflow the supported integer range.
            if ($bit > intdiv(PHP_INT_MAX, 2)) {
                throw new \RuntimeException('No free extension permission bits remain.');
            }

            $bit *= 2;
        }

        return $bit;
    }

    /**
     * Validates that one permission-bit value is a single non-zero bit flag.
     *
     * @param int $bit Permission bit candidate.
     * @return bool True when the value is an exact power-of-two flag.
     */
    private function isPowerOfTwo(int $bit): bool
    {
        // Non-positive values cannot represent a single-bit flag.
        if ($bit <= 0) {
            return false;
        }

        return ($bit & ($bit - 1)) === 0;
    }

    /**
     * Validates one extension-directory slug for map-key and path safety.
     *
     * @param string $name Extension directory slug candidate.
     * @return bool True when the slug is filesystem-safe and policy-compliant.
     */
    private function isSafeDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }
}
