<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/ext.php
 * Contact extension service provider for bootstrap container wiring.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Core\Postmaster;
use Raven\Ext\ContactFormRepository;
use Raven\Ext\ContactPublicFormRuntime;
use Raven\Ext\ContactSubmissionRepository;

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

    $rawContactServices['forms'] = static fn (): ContactFormRepository => new ContactFormRepository($formsPath . '/forms.php');
    $rawContactServices['submissions'] = static fn (): ContactSubmissionRepository => new ContactSubmissionRepository($rvn['db'], $driver, $prefix);
    /** @var mixed $rawEmbeddedRuntimes */
    $rawEmbeddedRuntimes = $rawContactServices['shortcode_runtimes'] ?? [];
    if (!is_array($rawEmbeddedRuntimes)) {
        $rawEmbeddedRuntimes = [];
    }
    $rawEmbeddedRuntimes[] = static function () use (&$rvn): ContactPublicFormRuntime {
        /** @var mixed $resolver */
        $resolver = $rvn['extension_services_for'] ?? null;
        $services = is_callable($resolver) ? $resolver('contact') : [];
        $formsRepository = $services['forms'] ?? null;
        $submissionsRepository = $services['submissions'] ?? null;
        $postmaster = $rvn['postmaster'] ?? null;
        if (
            !$formsRepository instanceof ContactFormRepository
            || !$submissionsRepository instanceof ContactSubmissionRepository
            || !$postmaster instanceof Postmaster
        ) {
            throw new RuntimeException('Contact extension services are unavailable.');
        }

        return new ContactPublicFormRuntime(
            $rvn['input'],
            $rvn['csrf'],
            $rvn['config'],
            $formsRepository,
            $submissionsRepository,
            $postmaster
        );
    };
    $rawContactServices['shortcode_runtimes'] = $rawEmbeddedRuntimes;
    $rawExtensionServices['contact'] = $rawContactServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
