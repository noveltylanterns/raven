<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/user/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $userRow */
/** @var string|null $loginIdentifierMode */
/** @var string $profileRoutePrefix */
/** @var bool $profileRoutesEnabled */
/** @var string $profileRouteSegment */
/** @var array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> $groupOptions */
/** @var int $primaryGroupId */
/** @var array<int> $secondaryGroupIds */
/** @var bool $canAssignSuperAdmin */
/** @var bool $canAssignConfigurationGroups */
/** @var array<string, array{label: string, prefix: string}> $profileContactOptions */
/** @var array<string, string> $twoFactorTypeOptions */
/** @var array<int, string> $themeOptions */
/** @var array{filename: string, url: string, thumb_url: string} $avatarTemplateData */
/** @var string $avatarUploadLimitsNote */
/** @var string $coverImageUrl */
/** @var int $bioMaxLength */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Lib\Support\e;
use Raven\Lib\Auth\PanelAccess;

$panelBase = '/' . trim($site['panel_path'], '/');
$loginIdentifierMode = strtolower(trim((string) ($loginIdentifierMode ?? 'email')));
if (!in_array($loginIdentifierMode, ['email', 'username'], true)) {
    $loginIdentifierMode = 'email';
}
$usernameRequiredForAuth = $loginIdentifierMode === 'username';
// Shared create/edit derivations keep template branching shallow.
$userName = trim((string) ($userRow['username'] ?? ''));
$userDisplayName = trim((string) ($userRow['name'] ?? ''));
$userId = (int) ($userRow['id'] ?? 0);
$hasPersistedUser = $userId > 0;
$deleteFormId = 'delete-user-form';
$profileRoutePrefix = trim((string) ($profileRoutePrefix ?? ''), '/');
$profileRoutesEnabled = (bool) ($profileRoutesEnabled ?? false);
$profileRouteSegment = trim((string) ($profileRouteSegment ?? ''));
$primaryGroupId = (int) ($primaryGroupId ?? ($userRow['primary_group_id'] ?? 0));
$secondaryGroupIds = array_map('intval', (array) ($secondaryGroupIds ?? ($userRow['secondary_group_ids'] ?? [])));
$avatarPath = isset($userRow['avatar']) && is_string($userRow['avatar'])
    ? $userRow['avatar']
    : null;
$coverImage = trim((string) ($userRow['cover_image'] ?? ''));
$avatarTemplateData = is_array($avatarTemplateData ?? null) ? $avatarTemplateData : ['filename' => '', 'url' => '', 'thumb_url' => ''];
$avatarFilename = (string) ($avatarTemplateData['filename'] ?? '');
$avatarUrl = (string) ($avatarTemplateData['url'] ?? '');
$avatarThumbUrl = (string) ($avatarTemplateData['thumb_url'] ?? '');
$coverImageUrl = trim((string) ($coverImageUrl ?? ''));
$profileContactOptions = is_array($profileContactOptions ?? null) ? $profileContactOptions : [];
$twoFactorTypeOptions = is_array($twoFactorTypeOptions ?? null) ? $twoFactorTypeOptions : [];
$contactProfilesRaw = is_array($userRow['contact'] ?? null) ? $userRow['contact'] : [];
$contactProfiles = [];
foreach ($contactProfilesRaw as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $type = strtolower(trim((string) ($entry['type'] ?? '')));
    $value = trim((string) ($entry['value'] ?? ''));
    if ($type === '' || $value === '') {
        continue;
    }

    if (!array_key_exists($type, $profileContactOptions)) {
        continue;
    }

    $contactProfiles[] = [
        'type' => $type,
        'value' => $value,
    ];
}
$twoFactorMethodsRaw = is_array($userRow['two_factor'] ?? null) ? $userRow['two_factor'] : [];
$maskMiddle = static function (string $value, int $edgeChars = 10): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (mb_strlen($value) <= ($edgeChars * 2) + 3) {
        return $value;
    }

    return mb_substr($value, 0, $edgeChars) . '...' . mb_substr($value, -$edgeChars);
};
$twoFactorMethods = [];
foreach ($twoFactorMethodsRaw as $methodIndex => $methodRow) {
    if (!is_array($methodRow)) {
        continue;
    }

    $methodType = strtolower(trim((string) ($methodRow['type'] ?? '')));
    if (!in_array($methodType, ['totp', 'recovery', 'webauthn', 'email'], true)) {
        continue;
    }

    $methodLabel = trim((string) ($methodRow['label'] ?? ''));
    if ($methodLabel === '') {
        $methodLabel = match ($methodType) {
            'totp' => 'Authenticator App',
            'recovery' => 'Recovery Phrase',
            'webauthn' => 'Security Key',
            default => 'Email Code',
        };
    }

    $methodStatus = strtolower(trim((string) ($methodRow['status'] ?? '')));
    if (!in_array($methodStatus, ['pending', 'confirmed', 'stub'], true)) {
        $methodStatus = $methodType === 'totp' ? 'pending' : 'stub';
    }

    $methodDetail = '';
    if ($methodType === 'webauthn') {
        $credentialId = trim((string) ($methodRow['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $methodDetail = 'Credential: ' . $maskMiddle($credentialId);
        }

        if ((bool) ($methodRow['require_uv'] ?? false)) {
            $methodDetail .= ($methodDetail !== '' ? ' ' : '') . '(PIN/Bio)';
        }
    } elseif ($methodType === 'recovery') {
        $recoveryHash = trim((string) ($methodRow['recovery_hash'] ?? ''));
        if ($recoveryHash !== '') {
            $methodDetail = 'Stored securely (one-way hash).';
        }
        $methodDetail .= ($methodDetail !== '' ? ' ' : '') . ((bool) ($methodRow['reusable'] ?? false) ? '(Reusable)' : '(One-time)');
    } elseif ($methodType === 'email') {
        $targetEmail = trim((string) ($methodRow['email'] ?? $methodRow['target_email'] ?? ''));
        if ($targetEmail !== '') {
            $methodDetail = $targetEmail;
        }
    }

    $twoFactorMethods[] = [
        'existing_index' => (int) $methodIndex,
        'type' => $methodType,
        'type_label' => (string) ($twoFactorTypeOptions[$methodType] ?? strtoupper($methodType)),
        'label' => $methodLabel,
        'status' => ucfirst($methodStatus),
        'detail' => $methodDetail,
    ];
}
usort($twoFactorMethods, static function (array $left, array $right): int {
    $leftLabel = strtolower(trim((string) ($left['label'] ?? '')));
    if ($leftLabel === '') {
        $leftLabel = strtolower(trim((string) ($left['type_label'] ?? '')));
    }

    $rightLabel = strtolower(trim((string) ($right['label'] ?? '')));
    if ($rightLabel === '') {
        $rightLabel = strtolower(trim((string) ($right['type_label'] ?? '')));
    }

    if ($leftLabel !== $rightLabel) {
        return $leftLabel <=> $rightLabel;
    }

    return ((int) ($left['existing_index'] ?? 0)) <=> ((int) ($right['existing_index'] ?? 0));
});
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$userPublicUrl = null;
if ($userRow !== null && $publicBase !== '' && $profileRoutesEnabled && $profileRoutePrefix !== '' && $profileRouteSegment !== '') {
    $userPublicUrl = $publicBase . '/' . rawurlencode($profileRoutePrefix) . '/' . rawurlencode($profileRouteSegment);
}
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['account', 'permissions', 'profile', 'security'], true) ? $requestedTab : 'account';
$selectedTheme = strtolower(trim((string) ($userRow['theme'] ?? 'default')));
if ($selectedTheme === 'light') {
    $selectedTheme = 'corp';
} elseif ($selectedTheme === 'dark') {
    $selectedTheme = 'midnight';
}
if (!in_array($selectedTheme, ['default', 'corp', 'ice', 'midnight'], true)) {
    $selectedTheme = 'default';
}
$themeLabels = [
    'default' => '<Default>',
    'corp' => 'Corporate',
    'ice' => 'Ice',
    'midnight' => 'Midnight',
];
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $userRow === null ? 'New User' : 'Edit User: <span class="text-primary">\'' . e($userDisplayName !== '' ? $userDisplayName : ($userName !== '' ? $userName : 'Untitled')) . '\'</span>' ?>
        </h1>
        <?php if ($userRow === null): ?>
            <p class="text-muted mb-0">Create or update user accounts, group membership, theme, and avatar settings.</p>
        <?php elseif ($userPublicUrl !== null): ?>
            <p class="mb-0 small">
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
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedUser): ?>
<!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/user/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $userId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/user/save" enctype="multipart/form-data">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $userId ?>">
    <input type="hidden" name="tab" id="user-active-tab" value="<?= e($activeTab) ?>">
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save User</button>
        <a href="<?= e($panelBase) ?>/user" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Users</a>
        <?php if ($hasPersistedUser): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this user?');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete User</button>
        <?php endif; ?>
    </nav>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'account' ? ' active' : '' ?>"
                id="user-account-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-account"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-account"
                aria-selected="<?= $activeTab === 'account' ? 'true' : 'false' ?>"
            >Account</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'permissions' ? ' active' : '' ?>"
                id="user-permissions-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-permissions"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-permissions"
                aria-selected="<?= $activeTab === 'permissions' ? 'true' : 'false' ?>"
            >Permissions</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'profile' ? ' active' : '' ?>"
                id="user-profile-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-profile"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-profile"
                aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>"
            >Profile</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'security' ? ' active' : '' ?>"
                id="user-security-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-security"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-security"
                aria-selected="<?= $activeTab === 'security' ? 'true' : 'false' ?>"
            >Security</button>
        </li>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
        <div
            class="tab-pane fade<?= $activeTab === 'account' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-account"
            role="tabpanel"
            aria-labelledby="user-account-tab"
            tabindex="0"
        >
            <?php if ($usernameRequiredForAuth): ?>
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input id="username" name="username" class="form-control" required value="<?= e((string) ($userRow['username'] ?? '')) ?>">
                <div class="form-text">Required because panel login is set to Username mode.</div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="display_name" class="form-label">Display Name</label>
                <input id="display_name" name="display_name" class="form-control" value="<?= e((string) ($userRow['name'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input id="email" name="email" type="email" class="form-control" required value="<?= e((string) ($userRow['email'] ?? '')) ?>">
            </div>

            <div class="form-group mb-0">
                <label for="theme" class="form-label">Panel Theme</label>
                <!-- Theme value is persisted per user and drives panel layout theme classes. -->
                <select id="theme" name="theme" class="form-select" required>
                    <?php foreach ($themeOptions as $option): ?>
                        <?php $optionLabel = (string) ($themeLabels[$option] ?? $option); ?>
                        <option value="<?= e($option) ?>"<?= $selectedTheme === $option ? ' selected' : '' ?>>
                            <?= e($optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><code>&lt;Default&gt;</code> follows the system's configured default admin theme.</div>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'permissions' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-permissions"
            role="tabpanel"
            aria-labelledby="user-permissions-tab"
            tabindex="0"
        >
            <?php $systemPanelBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits()); ?>
            <div class="form-group">
                <label class="form-label h5 mb-1" for="primary_group_id">Primary Group</label>
                <select id="primary_group_id" name="primary_group_id" class="form-select" required>
                    <?php foreach ($groupOptions as $group): ?>
                        <?php
                        $groupId = (int) $group['id'];
                        $groupSlug = strtolower(trim((string) ($group['slug'] ?? '')));
                        $isAdminGroup = $groupSlug === 'admin';
                        $isConfigurationGroup = (((int) ($group['permissions'] ?? 0)) & $systemPanelBitsMask) !== 0;
                        $lockAdminAssignment = $isAdminGroup && !$canAssignSuperAdmin;
                        $lockConfigurationPromotion = !$canAssignConfigurationGroups && $isConfigurationGroup && !$isAdminGroup;
                        $optionDisabled = $lockAdminAssignment || $lockConfigurationPromotion;
                        // Default to Guest on the new-user form (primaryGroupId = 0).
                        $optionSelected = $primaryGroupId === $groupId
                            || ($primaryGroupId === 0 && $groupSlug === 'guest');
                        ?>
                        <option
                            value="<?= $groupId ?>"
                            <?= $optionSelected ? 'selected' : '' ?>
                            <?= $optionDisabled ? 'disabled' : '' ?>
                        ><?= e($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Defaults to <code>Guest</code> if not selected.</div>
                <?php if (!$canAssignSuperAdmin): ?>
                    <div class="form-text text-muted">Only Admin users can assign the <code>Admin</code> group.</div>
                <?php endif; ?>
            </div>

            <fieldset class="mb-0 mt-3">
                <legend class="h5">Secondary Groups</legend>
                <?php foreach ($groupOptions as $group): ?>
                    <?php
                    $groupId = (int) $group['id'];
                    $isAdminGroup = strtolower(trim((string) ($group['slug'] ?? ''))) === 'admin';
                    $isConfigurationGroup = (((int) ($group['permissions'] ?? 0)) & $systemPanelBitsMask) !== 0;
                    $isSelected = in_array($groupId, $secondaryGroupIds, true);
                    $lockAdminAssignment = $isAdminGroup && !$canAssignSuperAdmin;
                    $lockConfigurationPromotion = !$canAssignConfigurationGroups && $isConfigurationGroup && !$isSelected && !$isAdminGroup;
                    $checkboxDisabled = $lockAdminAssignment || $lockConfigurationPromotion;
                    ?>
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="secondary_group_ids[]"
                            id="secondary_group_<?= $groupId ?>"
                            value="<?= $groupId ?>"
                            <?= $isSelected ? 'checked' : '' ?>
                            <?= $checkboxDisabled ? 'disabled' : '' ?>
                        >
                        <?php if ($lockAdminAssignment && $isSelected): ?>
                            <input type="hidden" name="secondary_group_ids[]" value="<?= $groupId ?>">
                        <?php endif; ?>
                        <label class="form-check-label" for="secondary_group_<?= $groupId ?>">
                            <?= e($group['name']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text">Optional additional groups layered on top of the primary group.</div>
                <?php if (!$canAssignConfigurationGroups): ?>
                    <div class="form-text text-muted">Only Admin users can assign groups with system administration access.</div>
                <?php endif; ?>
            </fieldset>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'profile' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-profile"
            role="tabpanel"
            aria-labelledby="user-profile-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label class="form-label h3" for="bio">Bio</label>
                <textarea
                    id="bio"
                    name="bio"
                    class="form-control"
                    rows="5"
                    maxlength="<?= (int) ($bioMaxLength ?? 500) ?>"
                ><?= e((string) ($userRow['bio'] ?? '')) ?></textarea>
                <div class="form-text">Plaintext profile bio. Max <?= (int) ($bioMaxLength ?? 500) ?> characters.</div>
            </div>

            <div class="form-group">
                <label class="form-label h3" for="avatar">Avatar</label>
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

            <div class="form-group">
                <label class="form-label h3" for="cover_image">Cover Image</label>
                <?php if ($coverImageUrl !== ''): ?>
                    <div class="mb-2">
                        <img
                            src="<?= e($coverImageUrl) ?>"
                            alt="Current cover image"
                            style="max-width: 100%; max-height: 180px; border-radius: 8px;"
                        >
                    </div>
                <?php endif; ?>
                <input id="cover_image" name="cover_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png,image/gif,image/jpeg,image/png">
                <div class="form-text">Stored locally at <code>/uploads/user/cover/</code> using the user string as the filename base.</div>
                <?php if ($coverImage !== ''): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_cover_image" name="remove_cover_image">
                        <label class="form-check-label" for="remove_cover_image">Remove current cover image</label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label class="form-label d-block h3">Contact Information</label>
                <div id="user-contact-profiles-list">
                    <?php foreach ($contactProfiles as $index => $contactProfile): ?>
                        <?php
                        $contactType = (string) ($contactProfile['type'] ?? '');
                        $contactValue = (string) ($contactProfile['value'] ?? '');
                        ?>
                        <div class="border rounded p-2 mb-2" data-user-contact-row="1">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Type</label>
                                    <select
                                        class="form-select"
                                        data-user-contact-key="type"
                                        name="contact_profiles[<?= (int) $index ?>][type]"
                                    >
                                        <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                                            <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                                            <?php $optionPrefix = (string) ($optionData['prefix'] ?? ''); ?>
                                            <option
                                                value="<?= e((string) $optionSlug) ?>"
                                                data-url-prefix="<?= e($optionPrefix) ?>"
                                                <?= $contactType === (string) $optionSlug ? ' selected' : '' ?>
                                            ><?= e($optionLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md pe-md-0">
                                    <label class="form-label">Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text d-none" data-user-contact-prefix-addon="1"></span>
                                        <input
                                            type="text"
                                            class="form-control"
                                            data-user-contact-key="value"
                                            name="contact_profiles[<?= (int) $index ?>][value]"
                                            value="<?= e($contactValue) ?>"
                                            placeholder="username/path or value"
                                        >
                                    </div>
                                </div>
                                <div class="col-auto ps-md-0 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger ms-2" data-user-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileContactOptions !== []): ?>
                    <button type="button" class="btn btn-primary" id="user-contact-profiles-add">Add More Contact Information</button>
                <?php else: ?>
                    <div class="form-text text-muted">No contact types are configured in <code>user.contact</code>.</div>
                <?php endif; ?>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'security' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-security"
            role="tabpanel"
            aria-labelledby="user-security-tab"
            tabindex="0"
        >
            <?php if ($userRow === null): ?>
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input id="password" name="password" type="password" class="form-control" required>
                    <div class="form-text">Minimum 8 characters.</div>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">Confirm Password</label>
                    <input
                        id="password_confirm"
                        name="password_confirm"
                        type="password"
                        class="form-control"
                        required
                    >
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label h3 mb-0" for="password">New Password</label>
                    <div class="form-text mb-2">Use this only when you want to rotate this user's panel password.</div>
                    <button type="button" class="btn btn-danger btn-sm" id="user-password-toggle">Change Password</button>
                    <div class="mt-2 d-none" id="user-password-fields"></div>
                </div>
            <?php endif; ?>

            <div class="form-group mb-0">
                <label class="form-label h3 d-block">Two-Factor Methods</label>
                <p class="text-muted mb-2">Admins can remove methods here to recover locked-out users.</p>
                <?php if ($hasPersistedUser): ?>
                    <input type="hidden" name="two_factor_methods_present" value="1">
                <?php endif; ?>
                <?php if (!$hasPersistedUser): ?>
                    <div class="form-text text-muted">Save this user first to manage 2FA methods.</div>
                <?php elseif ($twoFactorMethods === []): ?>
                    <div class="form-text text-muted">No 2FA methods are currently configured.</div>
                <?php else: ?>
                    <div id="user-two-factor-methods-list">
                        <?php foreach ($twoFactorMethods as $index => $method): ?>
                            <?php
                            $methodTypeLabel = trim((string) ($method['type_label'] ?? ''));
                            $methodLabel = trim((string) ($method['label'] ?? ''));
                            $methodStatus = trim((string) ($method['status'] ?? ''));
                            $methodDetail = trim((string) ($method['detail'] ?? ''));
                            $methodDescription = trim($methodStatus . ($methodDetail !== '' ? ' | ' . $methodDetail : ''));
                            ?>
                            <div class="border rounded p-2 mb-2" data-preferences-two-factor-row="1" data-user-two-factor-row="1">
                                <input
                                    type="hidden"
                                    data-user-two-factor-key="existing_index"
                                    name="two_factor_methods[<?= (int) $index ?>][existing_index]"
                                    value="<?= (int) ($method['existing_index'] ?? 0) ?>"
                                >
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label">Type</label>
                                        <input type="text" class="form-control" value="<?= e($methodTypeLabel) ?>" disabled>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Label</label>
                                        <input type="text" class="form-control" value="<?= e($methodLabel) ?>" disabled>
                                    </div>
                                    <div class="col-md">
                                        <label class="form-label">Details</label>
                                        <input type="text" class="form-control" value="<?= e($methodDescription) ?>" placeholder="Configured method" disabled>
                                    </div>
                                    <div class="col-auto ps-md-0 d-flex align-items-end">
                                        <button type="button" class="btn btn-danger" data-user-two-factor-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save User</button>
        <a href="<?= e($panelBase) ?>/user" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Users</a>
        <?php if ($hasPersistedUser): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this user?');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete User</button>
        <?php endif; ?>
    </nav>
</form>

<?php if ($userRow !== null): ?>
<template id="user-password-fields-template">
    <div class="form-text mb-1">Enter new password (minimum 8 chars):</div>
    <input class="form-control"
        id="password"
        name="password"
        type="text"
        autocomplete="new-password"
        data-user-password-kind="new"
        data-user-password-field="1"
    >
    <label class="form-text mt-2 mb-0" for="password_confirm">Enter new password again to confirm:</label>
    <input class="form-control"
        id="password_confirm"
        name="password_confirm"
        type="text"
        autocomplete="new-password"
        data-user-password-kind="confirm"
        data-user-password-field="1"
    >
</template>

<script>
  (function () {
    var toggleButton = document.getElementById('user-password-toggle');
    var fieldsContainer = document.getElementById('user-password-fields');
    var fieldsTemplate = document.getElementById('user-password-fields-template');
    if (
      !(toggleButton instanceof HTMLButtonElement)
      || !(fieldsContainer instanceof HTMLElement)
      || !(fieldsTemplate instanceof HTMLTemplateElement)
    ) {
      return;
    }

    var enabled = false;

    function setEnabled(nextEnabled) {
      enabled = !!nextEnabled;
      if (enabled && !fieldsContainer.hasChildNodes()) {
        fieldsContainer.appendChild(fieldsTemplate.content.cloneNode(true));
        var passwordFields = fieldsContainer.querySelectorAll('[data-user-password-field="1"]');
        passwordFields.forEach(function (field) {
          if (field instanceof HTMLInputElement) {
            field.type = 'password';
          }
        });
      } else if (!enabled) {
        fieldsContainer.innerHTML = '';
      }

      fieldsContainer.classList.toggle('d-none', !enabled);
      toggleButton.textContent = enabled ? 'Cancel Password Change' : 'Change Password';
    }

    toggleButton.addEventListener('click', function () {
      setEnabled(!enabled);
      if (!enabled) {
        return;
      }

      var firstField = fieldsContainer.querySelector('#password');
      if (firstField instanceof HTMLInputElement) {
        firstField.focus();
      }
    });
  })();
</script>
<?php endif; ?>

<?php if ($profileContactOptions !== []): ?>
<template id="user-contact-profile-template">
    <div class="border rounded p-2 mb-2" data-user-contact-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select class="form-select" data-user-contact-key="type">
                    <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                        <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                        <?php $optionPrefix = (string) ($optionData['prefix'] ?? ''); ?>
                        <option value="<?= e((string) $optionSlug) ?>" data-url-prefix="<?= e($optionPrefix) ?>"><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md pe-md-0">
                <label class="form-label">Value</label>
                <div class="input-group">
                    <span class="input-group-text d-none" data-user-contact-prefix-addon="1"></span>
                    <input
                        type="text"
                        class="form-control"
                        data-user-contact-key="value"
                        placeholder="username/path or value"
                    >
                </div>
            </div>
            <div class="col-auto ps-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-user-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</template>
<script>
  (function () {
    var list = document.getElementById('user-contact-profiles-list');
    var addButton = document.getElementById('user-contact-profiles-add');
    var template = document.getElementById('user-contact-profile-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-user-contact-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var typeField = row.querySelector('[data-user-contact-key="type"]');
        var valueField = row.querySelector('[data-user-contact-key="value"]');
        if (typeField instanceof HTMLSelectElement) {
          typeField.name = 'contact_profiles[' + index + '][type]';
        }
        if (valueField instanceof HTMLInputElement) {
          valueField.name = 'contact_profiles[' + index + '][value]';
        }
        syncPrefixAddon(row);
      });
    }

    function syncPrefixAddon(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }
      var typeField = row.querySelector('[data-user-contact-key="type"]');
      var prefixAddon = row.querySelector('[data-user-contact-prefix-addon="1"]');
      if (!(typeField instanceof HTMLSelectElement) || !(prefixAddon instanceof HTMLElement)) {
        return;
      }
      var option = typeField.options[typeField.selectedIndex];
      var prefix = option instanceof HTMLOptionElement ? String(option.getAttribute('data-url-prefix') || '').trim() : '';
      if (prefix === '') {
        prefixAddon.textContent = '';
        prefixAddon.classList.add('d-none');
        return;
      }
      prefixAddon.textContent = prefix;
      prefixAddon.classList.remove('d-none');
    }

    function appendRow() {
      var fragment = template.content.cloneNode(true);
      list.appendChild(fragment);
      reindexRows();
    }

    addButton.addEventListener('click', function () {
      appendRow();
    });

    list.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLSelectElement) || target.getAttribute('data-user-contact-key') !== 'type') {
        return;
      }
      var row = target.closest('[data-user-contact-row="1"]');
      syncPrefixAddon(row);
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var removeButton = target.closest('[data-user-contact-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }
      var row = removeButton.closest('[data-user-contact-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }
      row.remove();
      reindexRows();
    });

    reindexRows();
  })();
</script>
<?php endif; ?>

<script>
  (function () {
    var list = document.getElementById('user-two-factor-methods-list');
    if (!(list instanceof HTMLElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-user-two-factor-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var indexField = row.querySelector('[data-user-two-factor-key="existing_index"]');
        if (indexField instanceof HTMLInputElement) {
          indexField.name = 'two_factor_methods[' + index + '][existing_index]';
        }
      });
    }

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var removeButton = target.closest('[data-user-two-factor-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }

      var row = removeButton.closest('[data-user-two-factor-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.remove();
      reindexRows();
    });

    reindexRows();
  })();
</script>

<script>
  (function () {
    var activeTabInput = document.getElementById('user-active-tab');
    if (!(activeTabInput instanceof HTMLInputElement)) {
      return;
    }

    function tabKeyFromButton(button) {
      if (!(button instanceof HTMLElement)) {
        return 'account';
      }

      var controls = String(button.getAttribute('aria-controls') || '');
      var match = controls.match(/^rvnp-editor-pane-(account|permissions|profile|security)$/);
      return match ? String(match[1] || 'account') : 'account';
    }

    function syncActiveTabFromDom() {
      var activeButton = document.querySelector('#rvnp-editor-tabs button.nav-link.active[data-bs-toggle="tab"]');
      activeTabInput.value = tabKeyFromButton(activeButton instanceof HTMLElement ? activeButton : null);
    }

    syncActiveTabFromDom();

    document.addEventListener('shown.bs.tab', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target.getAttribute('data-bs-toggle') !== 'tab') {
        return;
      }

      activeTabInput.value = tabKeyFromButton(target);
    });
  })();
</script>
