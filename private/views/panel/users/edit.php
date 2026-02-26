<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/users/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $userRow */
/** @var string $profileRoutePrefix */
/** @var bool $profileRoutesEnabled */
/** @var array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}> $groupOptions */
/** @var bool $canAssignSuperAdmin */
/** @var bool $canAssignConfigurationGroups */
/** @var array<int, string> $themeOptions */
/** @var string $avatarUploadLimitsNote */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use Raven\Core\Auth\PanelAccess;
use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$userName = trim((string) ($userRow['username'] ?? ''));
$userId = (int) ($userRow['id'] ?? 0);
$hasPersistedUser = $userId > 0;
$deleteFormId = 'delete-user-form';
$profileRoutePrefix = trim((string) ($profileRoutePrefix ?? ''), '/');
$profileRoutesEnabled = (bool) ($profileRoutesEnabled ?? false);
$usernameRouteSegment = trim((string) ($userRow['username'] ?? ''));
// Multi-select group inputs are compared against normalized integer ids.
$selectedGroupIds = array_map('intval', (array) ($userRow['group_ids'] ?? []));
$avatarPath = isset($userRow['avatar_path']) && is_string($userRow['avatar_path'])
    ? $userRow['avatar_path']
    : null;
$avatarFilename = is_string($avatarPath) ? basename($avatarPath) : '';
$avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
$avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;
$avatarUrl = '/uploads/avatars/' . rawurlencode($avatarFilename);
$avatarThumbUrl = '/uploads/avatars/' . rawurlencode($avatarThumbFilename);
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$userPublicUrl = null;
if ($userRow !== null && $publicBase !== '' && $profileRoutesEnabled && $profileRoutePrefix !== '' && $usernameRouteSegment !== '') {
    $userPublicUrl = $publicBase . '/' . rawurlencode($profileRoutePrefix) . '/' . rawurlencode($usernameRouteSegment);
}
?>
<div class="card mb-3">
    <div class="card-body">
        <h1 class="mb-0">
            <?= $userRow === null ? 'New User' : 'Edit User: \'' . e($userName !== '' ? $userName : 'Untitled') . '\'' ?>
        </h1>
        <?php if ($userRow === null): ?>
            <p class="text-muted mt-2 mb-0">Create or update user accounts, group membership, theme, and avatar settings.</p>
        <?php elseif ($userPublicUrl !== null): ?>
            <p class="mt-2 mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($userPublicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($userPublicUrl) ?>"
                    aria-label="Open user profile URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($userPublicUrl) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedUser): ?>
    <!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
    <form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/users/delete">
        <?= $csrfField ?>
        <input type="hidden" name="id" value="<?= $userId ?>">
    </form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/users/save" enctype="multipart/form-data">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $userId ?>">

    <!-- Match page-editor ergonomics with right-aligned top actions. -->
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save User</button>
        <a href="<?= e($panelBase) ?>/users" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Users</a>
        <?php if ($hasPersistedUser): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this user?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete User
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label for="username" class="form-label h5">Username</label>
                <input id="username" name="username" class="form-control" required value="<?= e((string) ($userRow['username'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="display_name" class="form-label h5">Display Name</label>
                <input id="display_name" name="display_name" class="form-control" value="<?= e((string) ($userRow['display_name'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label h5">Email</label>
                <input id="email" name="email" type="email" class="form-control" required value="<?= e((string) ($userRow['email'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="password" class="form-label h5">
                    <?= $userRow === null ? 'Password' : 'New Password (leave blank to keep current)' ?>
                </label>
                <input id="password" name="password" type="password" class="form-control"<?= $userRow === null ? ' required' : '' ?>>
                <div class="form-text">Minimum 8 characters.</div>
            </div>

            <div class="mb-3">
                <label for="theme" class="form-label h5">Panel Theme</label>
                <!-- Theme value is persisted per user and drives panel layout theme classes. -->
                <select id="theme" name="theme" class="form-select" required>
                    <?php foreach ($themeOptions as $option): ?>
                        <?php $optionLabel = $option === 'default' ? '<Default>' : ucfirst($option); ?>
                        <option value="<?= e($option) ?>"<?= (string) ($userRow['theme'] ?? 'default') === $option ? ' selected' : '' ?>>
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

            <fieldset class="mb-0">
                <legend class="h5">Group Memberships</legend>
                <?php foreach ($groupOptions as $group): ?>
                    <?php
                    $groupId = (int) $group['id'];
                    $isSuperAdminGroup = strtolower(trim((string) ($group['slug'] ?? ''))) === 'super';
                    $isConfigurationGroup = (((int) ($group['permission_mask'] ?? 0)) & PanelAccess::MANAGE_CONFIGURATION) === PanelAccess::MANAGE_CONFIGURATION;
                    $isSelected = in_array($groupId, $selectedGroupIds, true);
                    $lockSuperAdminAssignment = $isSuperAdminGroup && !$canAssignSuperAdmin;
                    $lockConfigurationPromotion = !$canAssignConfigurationGroups && $isConfigurationGroup && !$isSelected && !$isSuperAdminGroup;
                    $groupCheckboxDisabled = $lockSuperAdminAssignment || $lockConfigurationPromotion;
                    ?>
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="group_ids[]"
                            id="group_<?= $groupId ?>"
                            value="<?= $groupId ?>"
                            <?= $isSelected ? 'checked' : '' ?>
                            <?= $groupCheckboxDisabled ? 'disabled' : '' ?>
                        >
                        <?php if ($lockSuperAdminAssignment && $isSelected): ?>
                            <!-- Disabled checkboxes are not submitted, so preserve existing assignment with hidden input. -->
                            <input type="hidden" name="group_ids[]" value="<?= $groupId ?>">
                        <?php endif; ?>
                        <label class="form-check-label" for="group_<?= $groupId ?>">
                            <!-- Stock/custom display handling lives in controller; template shows clean names only. -->
                            <?= e($group['name']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text">If none selected, user is assigned to <code>User</code> automatically.</div>
                <?php if (!$canAssignSuperAdmin): ?>
                    <div class="form-text text-muted">Only Super Admin users can assign the <code>Super Admin</code> group.</div>
                <?php endif; ?>
                <?php if (!$canAssignConfigurationGroups): ?>
                    <div class="form-text text-muted">Only Super Admin users can assign groups with <code>Manage System Configuration</code>.</div>
                <?php endif; ?>
            </fieldset>
        </div>
    </div>

    <!-- Duplicate actions at bottom so long forms do not require scrolling upward. -->
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save User</button>
        <a href="<?= e($panelBase) ?>/users" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Users</a>
        <?php if ($hasPersistedUser): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this user?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete User
            </button>
        <?php endif; ?>
    </div>
</form>
