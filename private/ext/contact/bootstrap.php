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
use Raven\ContactPublicFormRuntime;

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
    $formsRepository = new ContactFormRepository($app['db'], $driver, $prefix);
    $submissionsRepository = new ContactSubmissionRepository($app['db'], $driver, $prefix);
    $publicFormRuntime = new ContactPublicFormRuntime(
        $app['input'],
        $app['csrf'],
        $app['config'],
        $formsRepository,
        $submissionsRepository
    );

    // TODO: Remove these legacy top-level service keys once all consumers use `extension_services`.
    $app['contact_forms'] = $formsRepository;
    $app['contact_submissions'] = $submissionsRepository;

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $app['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawContactServices */
    $rawContactServices = $rawExtensionServices['contact'] ?? [];
    if (!is_array($rawContactServices)) {
        $rawContactServices = [];
    }

    $rawContactServices['forms'] = $formsRepository;
    $rawContactServices['submissions'] = $submissionsRepository;
    /** @var mixed $rawEmbeddedRuntimes */
    $rawEmbeddedRuntimes = $rawContactServices['embedded_form_runtimes'] ?? [];
    if (!is_array($rawEmbeddedRuntimes)) {
        $rawEmbeddedRuntimes = [];
    }
    $rawEmbeddedRuntimes[] = $publicFormRuntime;
    $rawContactServices['embedded_form_runtimes'] = $rawEmbeddedRuntimes;
    $rawExtensionServices['contact'] = $rawContactServices;
    $app['extension_services'] = $rawExtensionServices;
};
