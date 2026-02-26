<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/groups/index.php
 * Group-route unavailable placeholder template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var string|null $groupRouteError */
/** @var string|null $groupRouteMode */

$groupRouteError = strtolower(trim((string) ($groupRouteError ?? '')));
$groupRouteMode = strtolower(trim((string) ($groupRouteMode ?? 'disabled')));

if ($groupRouteError !== 'permission_denied' || $groupRouteMode !== 'private') {
    require __DIR__ . '/../messages/404.php';
    return;
}

require __DIR__ . '/../messages/denied.php';
return;
