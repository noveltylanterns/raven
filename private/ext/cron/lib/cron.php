<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/lib/cron.php
 * Scheduled Tasks extension scheduler jobs.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Ext\Cron\CronShellRunner;
use Raven\Ext\Cron\CronTaskService;
use Raven\Ext\Cron\CronTaskStore;

/**
 * Returns one scheduler job definition per enabled custom task.
 *
 * @return array<int, array{name: string, interval: int, run: callable}>
 */
$root = dirname(__DIR__, 4);
$localRoot = $root . '/private/dat/ext/cron';
$service = new CronTaskService(
    $root,
    new CronTaskStore($localRoot . '/crontab.json'),
    new CronShellRunner()
);

return $service->schedulerJobs();
