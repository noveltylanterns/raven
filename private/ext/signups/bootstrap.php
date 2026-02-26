<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/bootstrap.php
 * Signup Sheets extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\SignupFormRepository;
use Raven\Repository\WaitlistSignupRepository;

/**
 * Registers Signup Sheets extension services into the shared app container.
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

    $app['signup_forms'] = new SignupFormRepository($app['db'], $driver, $prefix);
    $app['signup_submissions'] = new WaitlistSignupRepository($app['db'], $driver, $prefix);
};
