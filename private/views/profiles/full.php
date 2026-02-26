<?php

/**
 * RAVEN CMS
 * ~/private/views/profiles/full.php
 * Full public profile template fallback.
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
$avatarPath = trim((string) ($profile['avatar_path'] ?? ''));
$avatarFilename = basename($avatarPath);
$avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
$avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;
$avatarUrl = '/uploads/avatars/' . rawurlencode($avatarFilename);
$avatarThumbUrl = '/uploads/avatars/' . rawurlencode($avatarThumbFilename);
$name = $displayName !== '' ? $displayName : $username;
?>
<section>
    <h2 class="h4 mb-2"><?= e($name !== '' ? $name : 'Profile') ?></h2>
    <?php if ($username !== ''): ?>
        <p class="text-muted mb-3">@<?= e($username) ?></p>
    <?php endif; ?>
    <?php if ($avatarFilename !== ''): ?>
        <p class="mb-0">
            <img
                src="<?= e($avatarThumbUrl) ?>"
                onerror="this.onerror=null;this.src='<?= e($avatarUrl) ?>';"
                alt="<?= e($name !== '' ? $name : $username) ?>"
                class="img-thumbnail"
                style="max-width: 160px; height: auto;"
            >
        </p>
    <?php endif; ?>
</section>
