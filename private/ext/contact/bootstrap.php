<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/bootstrap.php
 * Contact extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\ContactFormRepository;
use Raven\Repository\ContactSubmissionRepository;

/**
 * Registers Contact extension services into the shared app container.
 *
 * @param array<string, mixed> $app
 */
return static function (array &$app): void {
    if (
        !isset($app['db'], $app['driver'], $app['prefix'])
        || !$app['db'] instanceof PDO
    ) {
        return;
    }

    $driver = (string) $app['driver'];
    $prefix = (string) $app['prefix'];

    $app['contact_forms'] = new ContactFormRepository($app['db'], $driver, $prefix);
    $app['contact_submissions'] = new ContactSubmissionRepository($app['db'], $driver, $prefix);
};
