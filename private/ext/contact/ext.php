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
    'boot' => static function (array &$rvn): void {
    if (
        !isset($rvn['db'], $rvn['driver'], $rvn['prefix'], $rvn['extension_storage'])
        || !$rvn['db'] instanceof PDO
    ) {
        return;
    }

    $driver = (string) $rvn['driver'];
    $prefix = (string) $rvn['prefix'];
    $storageMap = is_array($rvn['extension_storage']) ? $rvn['extension_storage'] : [];
    $storage = is_array($storageMap['contact'] ?? null) ? $storageMap['contact'] : [];
    $formsPath = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($formsPath === '') {
        return;
    }

    $formsRepository = new ContactFormRepository($rvn['db'], $driver, $prefix, $formsPath . '/forms.php');
    $submissionsRepository = new ContactSubmissionRepository($rvn['db'], $driver, $prefix);
    $publicFormRuntime = new ContactPublicFormRuntime(
        $rvn['input'],
        $rvn['csrf'],
        $rvn['config'],
        $formsRepository,
        $submissionsRepository
    );

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
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
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
