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
    $storage = is_array($storageMap['signups'] ?? null) ? $storageMap['signups'] : [];
    $formsPath = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($formsPath === '') {
        return;
    }

    $formsRepository = new SignupFormRepository($formsPath . '/forms.php');
    $submissionsRepository = new SignupSubmissionRepository($rvn['db'], $driver, $prefix);
    $publicFormRuntime = new SignupPublicFormRuntime(
        $rvn['input'],
        $rvn['csrf'],
        $formsRepository,
        $submissionsRepository
    );

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
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
    $rawEmbeddedRuntimes = $rawSignupServices['shortcode_runtimes'] ?? [];
    if (!is_array($rawEmbeddedRuntimes)) {
        $rawEmbeddedRuntimes = [];
    }
    $rawEmbeddedRuntimes[] = $publicFormRuntime;
    $rawSignupServices['shortcode_runtimes'] = $rawEmbeddedRuntimes;
    $rawExtensionServices['signups'] = $rawSignupServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
