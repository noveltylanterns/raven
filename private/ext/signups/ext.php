<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/ext.php
 * Signup Sheets extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\SignupFormRepository;
use Raven\Repository\SignupSubmissionRepository;
use Raven\SignupPublicFormRuntime;

/**
 * Registers Signup Sheets extension services into the shared app container.
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
    $storage = is_array($storageMap['signups'] ?? null) ? $storageMap['signups'] : [];
    $formsPath = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($formsPath === '') {
        return;
    }

    $formsRepository = new SignupFormRepository($app['db'], $driver, $prefix, $formsPath . '/forms.php');
    $submissionsRepository = new SignupSubmissionRepository($app['db'], $driver, $prefix);
    $publicFormRuntime = new SignupPublicFormRuntime(
        $app['input'],
        $app['csrf'],
        $formsRepository,
        $submissionsRepository
    );

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
    },
];
