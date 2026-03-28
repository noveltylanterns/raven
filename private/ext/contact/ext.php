<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/ext.php
 * Contact extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\ContactFormRepository;
use Raven\Repository\ContactSubmissionRepository;
use Raven\ContactPublicFormRuntime;

/**
 * Registers Contact extension services into the shared app container.
 */
return [
    'storage' => [
        'local' => true,
        'table' => true,
    ],
    'boot' => static function (array &$app): void {
    if (
        !isset($app['db'], $app['driver'], $app['prefix'], $app['extension_storage'])
        || !$app['db'] instanceof PDO
    ) {
        return;
    }

    $driver = (string) $app['driver'];
    $prefix = (string) $app['prefix'];
    $storageMap = is_array($app['extension_storage']) ? $app['extension_storage'] : [];
    $storage = is_array($storageMap['contact'] ?? null) ? $storageMap['contact'] : [];
    $formsPath = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($formsPath === '') {
        return;
    }

    $formsRepository = new ContactFormRepository($app['db'], $driver, $prefix, $formsPath . '/forms.php');
    $submissionsRepository = new ContactSubmissionRepository($app['db'], $driver, $prefix);
    $publicFormRuntime = new ContactPublicFormRuntime(
        $app['input'],
        $app['csrf'],
        $app['config'],
        $formsRepository,
        $submissionsRepository
    );

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
    },
];
