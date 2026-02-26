<?php

/**
 * RAVEN CMS
 * ~/panel/ext/database/adminer/index.php
 * Web entrypoint for Database Manager Adminer runtime under panel web root.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This file is intentionally web-accessible and delegates runtime to private extension code.

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$app = require $root . '/private/bootstrap.php';

/**
 * Renders active public-theme 404 response from panel context.
 */
$renderPublicNotFound = static function () use ($app): void {
    $requiredKeys = [
        'view',
        'config',
        'auth',
        'input',
        'csrf',
        'page_images',
        'page_image_manager',
        'categories',
        'channels',
        'groups',
        'pages',
        'redirects',
        'tags',
        'users',
    ];

    foreach ($requiredKeys as $requiredKey) {
        if (!array_key_exists($requiredKey, $app)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }
    }

    $panelController = new \Raven\Controller\PanelController(
        $app['view'],
        $app['config'],
        $app['auth'],
        $app['input'],
        $app['csrf'],
        $app['page_images'],
        $app['page_image_manager'],
        $app['categories'],
        $app['channels'],
        $app['groups'],
        $app['pages'],
        $app['redirects'],
        $app['tags'],
        $app['users']
    );

    $panelController->renderPublicNotFound();
};

/**
 * Returns true only when Database Manager extension is enabled and valid.
 */
$isDatabaseExtensionEnabled = static function () use ($root): bool {
    $extensionDir = $root . '/private/ext/database';
    if (!is_dir($extensionDir)) {
        return false;
    }

    $manifestPath = $extensionDir . '/extension.json';
    if (!is_file($manifestPath)) {
        return false;
    }

    $rawManifest = file_get_contents($manifestPath);
    if ($rawManifest === false || trim($rawManifest) === '') {
        return false;
    }

    /** @var mixed $manifest */
    $manifest = json_decode($rawManifest, true);
    if (!is_array($manifest) || trim((string) ($manifest['name'] ?? '')) === '') {
        return false;
    }

    $statePath = $root . '/private/ext/.state.php';
    if (!is_file($statePath)) {
        return false;
    }

    /** @var mixed $rawState */
    $rawState = require $statePath;
    if (!is_array($rawState)) {
        return false;
    }

    /** @var mixed $enabled */
    $enabled = $rawState['enabled'] ?? $rawState;
    if (!is_array($enabled)) {
        return false;
    }

    return !empty($enabled['database']);
};

// Direct web entrypoint must obey extension enabled-state just like panel route registration.
if (!$isDatabaseExtensionEnabled()) {
    $renderPublicNotFound();
    exit;
}

// Guard Adminer behind normal panel authentication.

if (!$app['auth']->isLoggedIn()) {
    $renderPublicNotFound();
    exit;
}

if (!$app['auth']->canAccessPanel()) {
    $app['auth']->logout();
    $renderPublicNotFound();
    exit;
}

if (!$app['auth']->canManageConfiguration()) {
    $renderPublicNotFound();
    exit;
}

// Hand app container to extension bootstrap file.
$ravenApp = $app;
require $root . '/private/ext/database/adminer.php';
