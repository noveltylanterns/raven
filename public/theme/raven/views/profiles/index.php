<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/profiles/index.php
 * Profile-unavailable placeholder template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var string|null $profileRouteError */
/** @var string|null $profileRouteMode */

$profileRouteError = strtolower(trim((string) ($profileRouteError ?? '')));
$profileRouteMode = strtolower(trim((string) ($profileRouteMode ?? 'disabled')));

if ($profileRouteError !== 'permission_denied' && $profileRouteMode !== 'private') {
    require __DIR__ . '/../messages/404.php';
    return;
}

require __DIR__ . '/../messages/denied.php';
return;
