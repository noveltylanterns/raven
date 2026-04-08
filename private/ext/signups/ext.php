<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/ext.php
 * Signup Sheets extension service provider for bootstrap container wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Ext\SignupFormRepository;
use Raven\Ext\SignupSubmissionRepository;
use Raven\Ext\SignupPublicFormRuntime;

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

    $rawSignupServices['forms'] = static fn (): SignupFormRepository => new SignupFormRepository($formsPath . '/forms.php');
    $rawSignupServices['submissions'] = static fn (): SignupSubmissionRepository => new SignupSubmissionRepository($rvn['db'], $driver, $prefix);
    /** @var mixed $rawEmbeddedRuntimes */
    $rawEmbeddedRuntimes = $rawSignupServices['shortcode_runtimes'] ?? [];
    if (!is_array($rawEmbeddedRuntimes)) {
        $rawEmbeddedRuntimes = [];
    }
    $rawEmbeddedRuntimes[] = static function () use (&$rvn): SignupPublicFormRuntime {
        /** @var mixed $resolver */
        $resolver = $rvn['extension_services_for'] ?? null;
        $services = is_callable($resolver) ? $resolver('signups') : [];
        $formsRepository = $services['forms'] ?? null;
        $submissionsRepository = $services['submissions'] ?? null;
        if (
            !$formsRepository instanceof SignupFormRepository
            || !$submissionsRepository instanceof SignupSubmissionRepository
        ) {
            throw new RuntimeException('Signup Sheets extension services are unavailable.');
        }

        return new SignupPublicFormRuntime(
            $rvn['input'],
            $rvn['csrf'],
            $formsRepository,
            $submissionsRepository
        );
    };
    $rawSignupServices['shortcode_runtimes'] = $rawEmbeddedRuntimes;
    $rawExtensionServices['signups'] = $rawSignupServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
