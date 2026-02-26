<?php

/**
 * RAVEN CMS
 * ~/private/views/profiles/limited.php
 * Limited public profile template fallback.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var array<string, mixed> $profile */

use function Raven\Core\Support\e;

$displayName = trim((string) ($profile['display_name'] ?? ''));
$username = trim((string) ($profile['username'] ?? ''));
$name = $displayName !== '' ? $displayName : $username;
?>
<section>
    <h2 class="h4 mb-2"><?= e($name !== '' ? $name : 'Profile') ?></h2>
    <p class="text-muted mb-0">Limited profile view.</p>
</section>
