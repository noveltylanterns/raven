<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/preferences.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array<string, mixed> $preferences */
/** @var array<int, string> $themeOptions */
/** @var string $avatarUploadLimitsNote */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$avatarPath = isset($preferences['avatar_path']) && is_string($preferences['avatar_path'])
    ? $preferences['avatar_path']
    : null;
$avatarFilename = is_string($avatarPath) ? basename($avatarPath) : '';
$avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
$avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;
$avatarUrl = '/uploads/avatars/' . rawurlencode($avatarFilename);
$avatarThumbUrl = '/uploads/avatars/' . rawurlencode($avatarThumbFilename);
?>
<header class="card">
    <div class="card-body">
        <h1>Preferences</h1>
        <p class="text-muted mb-0">Manage your account details, panel theme, and avatar.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/preferences/save" enctype="multipart/form-data">
    <?= $csrfField ?>

    <nav>
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </nav>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label h5" for="username">Username</label>
                <input
                    id="username"
                    name="username"
                    class="form-control"
                    required
                    value="<?= e((string) ($preferences['username'] ?? '')) ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="display_name">Display Name</label>
                <input
                    id="display_name"
                    name="display_name"
                    class="form-control"
                    value="<?= e((string) ($preferences['display_name'] ?? '')) ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    class="form-control"
                    required
                    value="<?= e((string) ($preferences['email'] ?? '')) ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" class="form-control">
                <div class="form-text">Leave blank to keep current password (minimum 8 chars if changing).</div>
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="theme">Panel Theme</label>
                <select id="theme" name="theme" class="form-select" required>
                    <?php foreach ($themeOptions as $option): ?>
                        <?php $optionLabel = $option === 'default' ? '<Default>' : ucfirst($option); ?>
                        <option value="<?= e($option) ?>"<?= (string) ($preferences['theme'] ?? 'default') === $option ? ' selected' : '' ?>>
                            <?= e($optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><code>&lt;Default&gt;</code> follows the system's configured default admin theme.</div>
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="avatar">Avatar</label>
                <?php if ($avatarFilename !== ''): ?>
                    <div class="mb-2">
                        <!-- Avatar image is served from required public content path. -->
                        <img
                            src="<?= e($avatarThumbUrl) ?>"
                            onerror="this.onerror=null;this.src='<?= e($avatarUrl) ?>';"
                            alt="Current avatar"
                            style="max-width: 96px; max-height: 96px; border-radius: 8px;"
                        >
                    </div>
                <?php endif; ?>
                <input id="avatar" name="avatar" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png,image/gif,image/jpeg,image/png">
                <div class="form-text"><?= e($avatarUploadLimitsNote) ?></div>

                <?php if ($avatarFilename !== ''): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_avatar" name="remove_avatar">
                        <label class="form-check-label" for="remove_avatar">Remove current avatar</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </div>
</form>
