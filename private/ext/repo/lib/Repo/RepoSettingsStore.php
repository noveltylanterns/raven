<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/Repo/RepoSettingsStore.php
 * File-backed settings store for the Repo extension.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Ext\Repo;

/**
 * Persists and normalizes global Repo extension settings.
 */
final class RepoSettingsStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Returns the absolute settings file path.
     *
     * @param void
     * @return string Absolute `private/dat/ext/repo/.settings.json` path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns normalized settings from disk or defaults when missing.
     *
     * @param void
     * @return array<string, mixed> Repo extension settings payload.
     */
    public function load(): array
    {
        return $this->normalize(RepoStorageSupport::loadJsonObjectFile($this->path));
    }

    /**
     * Normalizes and persists one settings payload.
     *
     * @param array<string, mixed> $settings Raw settings payload.
     * @return array<string, mixed> Normalized settings payload.
     */
    public function save(array $settings): array
    {
        $normalized = $this->normalize($settings);
        RepoStorageSupport::writeJsonObjectFile(
            $this->path,
            $normalized
        );

        return $normalized;
    }

    /**
     * Returns factory-default Repo settings.
     *
     * @param void
     * @return array<string, mixed> Default settings payload.
     */
    public function defaults(): array
    {
        return [
            'auto_update_enabled' => false,
            'update_frequency' => 'daily',
            'default_visibility' => 'private',
            'log_prune_days' => 30,
            'log_events' => [
                'settings_updated' => true,
                'repo_saved' => true,
                'repo_deleted' => true,
                'sync_started' => true,
                'sync_succeeded' => true,
                'sync_failed' => true,
                'sync_skipped' => true,
            ],
        ];
    }

    /**
     * Returns supported frequency values for global and per-repo scheduling intent.
     *
     * @param void
     * @return array<int, string> Allowed frequency keys.
     */
    public function allowedFrequencies(): array
    {
        return ['hourly', 'daily', 'weekly', 'monthly'];
    }

    /**
     * Returns supported global/public visibility defaults.
     *
     * @param void
     * @return array<int, string> Allowed visibility keys.
     */
    public function allowedVisibilityModes(): array
    {
        return ['private', 'public_meta_private_objects', 'public_browser', 'public_downloads'];
    }

    /**
     * Returns the supported log event keys.
     *
     * @param void
     * @return array<int, string> Allowed event-type keys.
     */
    public function allowedLogEvents(): array
    {
        return [
            'settings_updated',
            'repo_saved',
            'repo_deleted',
            'sync_started',
            'sync_succeeded',
            'sync_failed',
            'sync_skipped',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalize(array $settings): array
    {
        $defaults = $this->defaults();

        $frequency = strtolower(trim((string) ($settings['update_frequency'] ?? $defaults['update_frequency'])));
        if (!in_array($frequency, $this->allowedFrequencies(), true)) {
            $frequency = (string) $defaults['update_frequency'];
        }

        $visibility = strtolower(trim((string) ($settings['default_visibility'] ?? $defaults['default_visibility'])));
        if (!in_array($visibility, $this->allowedVisibilityModes(), true)) {
            $visibility = (string) $defaults['default_visibility'];
        }

        $logPruneDays = (int) ($settings['log_prune_days'] ?? $defaults['log_prune_days']);
        if ($logPruneDays < 1) {
            $logPruneDays = 1;
        }
        if ($logPruneDays > 3650) {
            $logPruneDays = 3650;
        }

        $eventDefaults = is_array($defaults['log_events'] ?? null) ? $defaults['log_events'] : [];
        $rawEvents = is_array($settings['log_events'] ?? null) ? $settings['log_events'] : [];
        $normalizedEvents = [];
        foreach ($this->allowedLogEvents() as $eventKey) {
            $normalizedEvents[$eventKey] = array_key_exists($eventKey, $rawEvents)
                ? !empty($rawEvents[$eventKey])
                : !empty($eventDefaults[$eventKey]);
        }

        return [
            'auto_update_enabled' => !empty($settings['auto_update_enabled']),
            'update_frequency' => $frequency,
            'default_visibility' => $visibility,
            'log_prune_days' => $logPruneDays,
            'log_events' => $normalizedEvents,
        ];
    }
}
