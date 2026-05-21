<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/cron.php
 * Repositories extension scheduler jobs.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Ext\Repo\RepoService;

return [
    [
        'name' => 'auto-sync',
        // Scan on a short cadence; RepoService applies the per-repo hourly/daily/weekly/monthly windows.
        'interval' => 300,
        'run' => static function (array $context): void {
            /** @var mixed $rawRvn */
            $rawRvn = $context['rvn'] ?? [];
            $rvn = is_array($rawRvn) ? $rawRvn : [];

            /** @var mixed $resolver */
            $resolver = $rvn['extension_services_for'] ?? null;
            $rawRepoServices = is_callable($resolver) ? $resolver('repo') : [];
            /** @var mixed $repoServiceRaw */
            $repoServiceRaw = is_array($rawRepoServices) ? ($rawRepoServices['service'] ?? null) : null;
            if (!$repoServiceRaw instanceof RepoService) {
                return;
            }

            $repoServiceRaw->runScheduledSyncPass();
        },
    ],
];
