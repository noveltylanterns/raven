<?php

/**
 * RAVEN CMS
 * ~/panel/ext/database/adminer/index.php
 * Legacy Database Manager entrypoint shim.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$panelPath = 'panel';
$configFile = $root . '/private/dat/config.php';
if (is_file($configFile)) {
    /** @var mixed $rawConfig */
    $rawConfig = require $configFile;
    if (is_array($rawConfig)) {
        $configuredPanelPath = trim((string) (($rawConfig['panel']['path'] ?? null)));
        if ($configuredPanelPath !== '') {
            $panelPath = trim($configuredPanelPath, '/');
        }
    }
}

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/' . ltrim($panelPath, '/') . '/database/adminer';
if (is_string($query) && $query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
