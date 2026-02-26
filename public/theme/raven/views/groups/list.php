<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/groups/list.php
 * Public group member list template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var array<string, mixed> $group */
/** @var array<int, array{id: int, username: string, display_name: string, avatar_path: string|null}> $members */

use function Raven\Core\Support\e;

$groupName = trim((string) ($group['name'] ?? ''));
$groupSlug = trim((string) ($group['slug'] ?? ''));
$memberCount = max(count($members), (int) ($group['member_count'] ?? 0));
?>
<section>
    <h2 class="h4 mb-2"><?= e($groupName !== '' ? ('Group: ' . $groupName) : 'Group') ?></h2>
    <?php if ($groupSlug !== ''): ?>
        <p class="text-muted mb-2">Slug: <?= e($groupSlug) ?></p>
    <?php endif; ?>
    <p class="text-muted mb-3"><?= e((string) $memberCount) ?> Users</p>

    <?php if ($members === []): ?>
        <p class="mb-0">No members are assigned to this group.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($members as $member): ?>
                <?php
                $username = trim((string) ($member['username'] ?? ''));
                $displayName = trim((string) ($member['display_name'] ?? ''));
                $avatarPath = trim((string) ($member['avatar_path'] ?? ''));
                $avatarFilename = basename($avatarPath);
                $avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
                $avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;
                $avatarUrl = '/uploads/avatars/' . rawurlencode($avatarFilename);
                $avatarThumbUrl = '/uploads/avatars/' . rawurlencode($avatarThumbFilename);
                $name = $displayName !== '' ? $displayName : $username;
                ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <?php if ($avatarFilename !== ''): ?>
                        <img
                            src="<?= e($avatarThumbUrl) ?>"
                            onerror="this.onerror=null;this.src='<?= e($avatarUrl) ?>';"
                            alt="<?= e($name !== '' ? $name : $username) ?>"
                            width="32"
                            height="32"
                            class="rounded-circle"
                        >
                    <?php endif; ?>
                    <div>
                        <div><?= e($name !== '' ? $name : 'User') ?></div>
                        <?php if ($username !== ''): ?>
                            <small class="text-muted">@<?= e($username) ?></small>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
