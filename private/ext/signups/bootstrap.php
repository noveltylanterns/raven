<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/bootstrap.php
 * Signup Sheets extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\SignupFormRepository;
use Raven\Repository\SignupSubmissionRepository;
use Raven\SignupPublicFormRuntime;

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
    $formsRepository = new SignupFormRepository($app['db'], $driver, $prefix);
    $submissionsRepository = new SignupSubmissionRepository($app['db'], $driver, $prefix);
    $publicFormRuntime = new SignupPublicFormRuntime(
        $app['input'],
        $app['csrf'],
        $formsRepository,
        $submissionsRepository
    );

    // TODO: Remove these legacy top-level service keys once all consumers use `extension_services`.
    $app['signup_forms'] = $formsRepository;
    $app['signup_submissions'] = $submissionsRepository;

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $app['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawSignupServices */
    $rawSignupServices = $rawExtensionServices['signups'] ?? [];
    if (!is_array($rawSignupServices)) {
        $rawSignupServices = [];
    }

    $rawSignupServices['forms'] = $formsRepository;
    $rawSignupServices['submissions'] = $submissionsRepository;
    /** @var mixed $rawEmbeddedRuntimes */
    $rawEmbeddedRuntimes = $rawSignupServices['embedded_form_runtimes'] ?? [];
    if (!is_array($rawEmbeddedRuntimes)) {
        $rawEmbeddedRuntimes = [];
    }
    $rawEmbeddedRuntimes[] = $publicFormRuntime;
    $rawSignupServices['embedded_form_runtimes'] = $rawEmbeddedRuntimes;
    $rawExtensionServices['signups'] = $rawSignupServices;
    $app['extension_services'] = $rawExtensionServices;
};
