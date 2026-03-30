<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/cron.php
 * Repositories extension scheduler jobs.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

use Raven\Repo\RepoService;

return [
    [
        'name' => 'auto-sync',
        // Scan on a short cadence; RepoService applies the per-repo hourly/daily/weekly/monthly windows.
        'interval' => 300,
        'run' => static function (array $context): void {
            /** @var mixed $rawRvn */
            $rawRvn = $context['rvn'] ?? [];
            $rvn = is_array($rawRvn) ? $rawRvn : [];

            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            /** @var mixed $rawRepoServices */
            $rawRepoServices = is_array($rawExtensionServices) ? ($rawExtensionServices['repo'] ?? []) : [];
            /** @var mixed $repoServiceRaw */
            $repoServiceRaw = is_array($rawRepoServices) ? ($rawRepoServices['service'] ?? null) : null;
            if (!$repoServiceRaw instanceof RepoService) {
                return;
            }

            $repoServiceRaw->runScheduledSyncPass();
        },
    ],
];