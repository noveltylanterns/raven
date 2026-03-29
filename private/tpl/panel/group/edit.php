<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/group/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $group */
/** @var string $groupRoutePrefix */
/** @var bool $groupRoutingEnabledSystemWide */
/** @var array<int, array{bit: int, label: string, section?: string, group?: string, action?: string, extension?: string}> $permissionDefinitions */
/** @var bool $canEditConfigurationBit */
/** @var string $imageAllowedExtensions */
/** @var int|null $imageMaxFilesizeKb */
/** @var array<string, array{width: int, height: int}> $imageVariantSpecs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use Raven\Core\Auth\PanelAccess;
use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$canEditConfigurationBit = (bool) ($canEditConfigurationBit ?? false);
// Shared create/edit derivations keep template branching shallow.
$groupName = trim((string) ($group['name'] ?? ''));
$groupId = (int) ($group['id'] ?? 0);
$hasPersistedGroup = $groupId > 0;
$deleteFormId = 'delete-group-form';
$permissionMask = (int) ($group['permissions'] ?? 0);
$viewPublicSiteBit = PanelAccess::VIEW_PUBLIC_SITE;
$viewPrivateSiteBit = PanelAccess::VIEW_PRIVATE_SITE;
$viewDisabledSiteBit = PanelAccess::VIEW_DISABLED_SITE;
$groupSlug = trim((string) ($group['slug'] ?? ''));
if ($groupSlug === '' && $groupName !== '') {
    $groupSlug = strtolower($groupName);
    $groupSlug = preg_replace('/[^a-z0-9]+/', '-', $groupSlug) ?? '';
    $groupSlug = trim($groupSlug, '-');
    $groupSlug = preg_replace('/-+/', '-', $groupSlug) ?? '';
}
$routeEnabledChecked = (int) ($group['route'] ?? 0) === 1;
if (!$groupRoutingEnabledSystemWide) {
    $routeEnabledChecked = false;
}
$groupRoutePrefixDisplay = trim($groupRoutePrefix, '/');
// Stock groups keep immutable slugs while names remain editable.
$isStock = (int) ($group['is_stock'] ?? 0) === 1;
$groupRoleSlug = strtolower(trim((string) ($group['slug'] ?? $groupSlug)));
$isBannedGroup = $groupRoleSlug === 'banned';
$isGuestGroup = $groupRoleSlug === 'guest';
$isValidatingGroup = $groupRoleSlug === 'validating';
$isGuestLikeGroup = $isGuestGroup || $isValidatingGroup;
$isUserGroup = $groupRoleSlug === 'user';
$isAdminGroup = $groupId === 1;
$allDefinedBitsMask = 0;
foreach ($permissionDefinitions as $permissionDefinitionRow) {
    $allDefinedBitsMask |= (int) ($permissionDefinitionRow['bit'] ?? 0);
}
$adminStockMask = PanelAccess::PANEL_LOGIN
    | $viewPublicSiteBit
    | $viewPrivateSiteBit
    | $viewDisabledSiteBit
    | PanelAccess::maskFromBits(array_merge(
        PanelAccess::contentPanelBits(),
        PanelAccess::taxonomyPanelBits(),
        PanelAccess::usersPanelBits()
    ));
$systemPanelBits = PanelAccess::systemPanelBits();
$systemPanelBitsMask = PanelAccess::maskFromBits($systemPanelBits);
$routeEnabledChecked = ($isGuestLikeGroup || $isBannedGroup) ? false : $routeEnabledChecked;
if ($isBannedGroup) {
    $permissionMask = 0;
} elseif ($isGuestLikeGroup) {
    $permissionMask &= $viewPublicSiteBit;
} elseif ($isUserGroup) {
    $permissionMask &= ($viewPublicSiteBit | $viewPrivateSiteBit);
} elseif ($isAdminGroup) {
    $permissionMask = $allDefinedBitsMask;
}
if (($permissionMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
    $permissionMask &= ~PanelAccess::allStockPanelBitsMask();
    $permissionMask &= ~PanelAccess::VIEW_DISABLED_SITE;
}
$canDeleteGroup = $hasPersistedGroup && !$isStock;

$siteDomainRaw = trim((string) ($site['domain'] ?? 'localhost'));
if (str_contains($siteDomainRaw, '://')) {
    $parsedHost = trim((string) parse_url($siteDomainRaw, PHP_URL_HOST));
    $parsedPort = parse_url($siteDomainRaw, PHP_URL_PORT);
    if ($parsedHost !== '') {
        $siteDomainRaw = $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
    }
}
$siteDomainRaw = preg_replace('/[\/?#].*$/', '', $siteDomainRaw) ?? $siteDomainRaw;
$siteDomainRaw = trim($siteDomainRaw);
if ($siteDomainRaw === '') {
    $siteDomainRaw = 'localhost';
}
$publicIndexUrl = 'https://' . $siteDomainRaw . '/';
$panelIndexUrl = rtrim($publicIndexUrl, '/') . $panelBase . '/';
$groupPublicUrl = null;
if ($group !== null && $groupRoutingEnabledSystemWide && $routeEnabledChecked && $groupRoutePrefixDisplay !== '' && $groupSlug !== '') {
    $groupPublicUrl = rtrim($publicIndexUrl, '/') . '/' . rawurlencode($groupRoutePrefixDisplay) . '/' . rawurlencode($groupSlug);
}

$publicPermissionDefinitions = [];
$panelPermissionDefinitions = [];
$extensionPermissionDefinitions = [];
foreach ($permissionDefinitions as $permission) {
    $section = strtolower(trim((string) ($permission['section'] ?? 'panel')));
    if ($section === 'public') {
        $publicPermissionDefinitions[] = $permission;
    } elseif ($section === 'extension') {
        $extensionPermissionDefinitions[] = $permission;
    } else {
        $panelPermissionDefinitions[] = $permission;
    }
}
$panelActionColumns = [
    'view' => 'View',
    'create' => 'Create',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'uninstall' => 'Uninstall',
];
$panelLoginPermission = null;
$panelPermissionMatrix = [];
foreach ($panelPermissionDefinitions as $permission) {
    $bit = (int) ($permission['bit'] ?? 0);
    if ($bit <= 0) {
        continue;
    }

    if ($bit === PanelAccess::PANEL_LOGIN) {
        $panelLoginPermission = $permission;
        continue;
    }

    $groupLabel = trim((string) ($permission['group'] ?? 'Panel'));
    if ($groupLabel === '') {
        $groupLabel = 'Panel';
    }

    $actionKey = strtolower(trim((string) ($permission['action'] ?? '')));
    if (!isset($panelActionColumns[$actionKey])) {
        continue;
    }

    if (!isset($panelPermissionMatrix[$groupLabel])) {
        $panelPermissionMatrix[$groupLabel] = [
            'label' => $groupLabel,
            'actions' => [],
        ];
    }

    $panelPermissionMatrix[$groupLabel]['actions'][$actionKey] = $permission;
}
$adminAllowedBits = []; // Admin group gets all bits; no restriction needed here.
$panelPermissionState = static function (int $bit) use (
    $permissionMask,
    $isBannedGroup,
    $isGuestLikeGroup,
    $isUserGroup,
    $isAdminGroup,
    $canEditConfigurationBit,
    $systemPanelBits
): array {
    $checked = ($permissionMask & $bit) === $bit;
    $configurationPermissionLocked = !$canEditConfigurationBit && in_array($bit, $systemPanelBits, true);
    $lockedPermission = (($isBannedGroup || $isGuestLikeGroup || $isUserGroup) && !$isAdminGroup);
    $requiresPanelAccess = $bit !== PanelAccess::PANEL_LOGIN;
    $panelAccessEnabled = ($permissionMask & PanelAccess::PANEL_LOGIN) === PanelAccess::PANEL_LOGIN;
    if (!$lockedPermission && $requiresPanelAccess && !$panelAccessEnabled) {
        $lockedPermission = true;
        $checked = false;
    }
    $lockedPermission = $lockedPermission || $configurationPermissionLocked;

    return [
        'checked' => $checked,
        'locked' => $lockedPermission,
        'configuration_locked' => $configurationPermissionLocked,
        'requires_panel_access' => $requiresPanelAccess,
    ];
};
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['basic', 'media', 'permissions'], true) ? $requestedTab : 'basic';
// Resolve stored image filenames into public paths for display in the Media tab.
$coverFilename = trim((string) ($group['cover_image'] ?? ''));
$iconFilename = trim((string) ($group['icon_image'] ?? ''));
$coverPath = $groupId > 0 && $coverFilename !== '' ? 'uploads/groups/' . $groupId . '/' . $coverFilename : '';
$iconPath = $groupId > 0 && $iconFilename !== '' ? 'uploads/groups/' . $groupId . '/' . $iconFilename : '';
$coverUrl = $coverPath !== '' ? '/' . $coverPath : '';
$iconUrl = $iconPath !== '' ? '/' . $iconPath : '';
$maxFilesizeLabel = ($imageMaxFilesizeKb ?? null) === null
    ? 'No limit'
    : number_format((int) $imageMaxFilesizeKb) . ' KB';
$smallSpec = $imageVariantSpecs['sm'] ?? ['width' => 0, 'height' => 0];
$mediumSpec = $imageVariantSpecs['md'] ?? ['width' => 0, 'height' => 0];
$largeSpec = $imageVariantSpecs['lg'] ?? ['width' => 0, 'height' => 0];
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$coverCopyUrl = $coverUrl !== '' && $publicBase !== '' ? $publicBase . $coverUrl : $coverUrl;
$iconCopyUrl = $iconUrl !== '' && $publicBase !== '' ? $publicBase . $iconUrl : $iconUrl;
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $group === null ? 'New Group' : 'Edit Group: <span class="text-primary">\'' . e($groupName !== '' ? $groupName : 'Untitled') . '\'</span>' ?>
        </h1>
        <?php if ($group === null): ?>
            <p class="text-muted mb-0">Create or update group permissions and group-level route behavior.</p>
        <?php elseif ($groupPublicUrl !== null): ?>
            <p class="mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($groupPublicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($groupPublicUrl) ?>"
                    aria-label="Open group URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($groupPublicUrl) ?>
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

<?php if ($canDeleteGroup): ?>
<!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/group/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $groupId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/group/save" enctype="multipart/form-data">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $groupId ?>">
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Group</button>
        <a href="<?= e($panelBase) ?>/group" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Groups</a>
        <?php if ($canDeleteGroup): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this group? This cannot be undone.');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Group</button>
        <?php endif; ?>
    </nav>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'basic' ? ' active' : '' ?>"
                id="group-basic-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-basic"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-basic"
                aria-selected="<?= $activeTab === 'basic' ? 'true' : 'false' ?>"
            >Basic</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'media' ? ' active' : '' ?>"
                id="group-media-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-media"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-media"
                aria-selected="<?= $activeTab === 'media' ? 'true' : 'false' ?>"
            >Media</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'permissions' ? ' active' : '' ?>"
                id="group-permissions-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-permissions"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-permissions"
                aria-selected="<?= $activeTab === 'permissions' ? 'true' : 'false' ?>"
            >Permissions</button>
        </li>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
        <div
            class="tab-pane fade<?= $activeTab === 'basic' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-basic"
            role="tabpanel"
            aria-labelledby="group-basic-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label for="name" class="form-label">Name</label>
                <input
                    id="name"
                    name="name"
                    class="form-control"
                    required
                    value="<?= e((string) ($group['name'] ?? '')) ?>"
                >
            </div>

            <div class="form-group mb-0">
                <label for="slug" class="form-label">Slug</label>
                <input
                    id="slug"
                    name="slug"
                    class="form-control font-monospace"
                    value="<?= e($groupSlug) ?>"
                    <?= $isStock ? 'readonly disabled' : '' ?>
                >
                <?php if ($isStock): ?>
                    <div class="form-text">Stock group slugs are immutable to ensure stable routing.</div>
                <?php endif; ?>
            </div>

            <hr class="my-3">

            <div class="form-check mb-0">
                <input
                    class="form-check-input"
                    type="checkbox"
                    id="route_enabled"
                    <?= ($isGuestLikeGroup || $isBannedGroup) ? '' : 'name="route_enabled"' ?>
                    value="1"
                    <?= $routeEnabledChecked ? 'checked' : '' ?>
                    <?= ($groupRoutingEnabledSystemWide && !$isGuestLikeGroup && !$isBannedGroup) ? '' : 'disabled' ?>
                >
                <label class="form-check-label<?= ($isGuestLikeGroup || $isBannedGroup) ? ' text-muted' : '' ?>" for="route_enabled">Enable URI Routing for this group</label>
                <?php if ($isBannedGroup): ?>
                    <div class="form-text">Banned group URI routing is permanently disabled.</div>
                <?php elseif ($isValidatingGroup): ?>
                    <div class="form-text">Validating group URI routing is permanently disabled.</div>
                <?php elseif ($isGuestGroup): ?>
                    <div class="form-text">Guest group URI routing is permanently disabled.</div>
                <?php elseif (!$groupRoutingEnabledSystemWide): ?>
                    <div class="form-text">System-level group routing is disabled in Configuration.</div>
                <?php endif; ?>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'media' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-media"
            role="tabpanel"
            aria-labelledby="group-media-tab"
            tabindex="0"
        >
            <?php if (!$hasPersistedGroup): ?>
                <p class="text-muted">Save the group first to enable image uploads.</p>
            <?php else: ?>
                <div class="form-group small text-muted">
                    Allowed extensions: <code><?= e($imageAllowedExtensions) ?></code>.
                    Max filesize: <code><?= e($maxFilesizeLabel) ?></code>.
                    <br>
                    Variants use configured contain sizes: <code>sm <?= e((string) $smallSpec['width']) ?>x<?= e((string) $smallSpec['height']) ?></code>,
                    <code>md <?= e((string) $mediumSpec['width']) ?>x<?= e((string) $mediumSpec['height']) ?></code>,
                    <code>lg <?= e((string) $largeSpec['width']) ?>x<?= e((string) $largeSpec['height']) ?></code>.
                </div>

                <div class="form-group">
                    <label for="cover_image" class="form-label">Cover Image</label>
                    <input id="cover_image" name="cover_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                    <?php if ($coverPath !== ''): ?>
                        <div class="mt-2">
                            <img src="<?= e($coverUrl) ?>" alt="Current group cover image" class="img-thumbnail" style="max-width: 240px;">
                        </div>
                        <div class="small text-muted mt-1">
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                                data-rvn-copy-url="1"
                                data-copy-text="<?= e($coverCopyUrl) ?>"
                                data-copy-label="<?= e($coverPath) ?>"
                                title="Click to copy full URL"
                                aria-label="Copy full URL for cover image"
                            >
                                <code><?= e($coverPath) ?></code>
                            </button>
                        </div>
                        <div class="form-check mt-2">
                            <input id="remove_cover_image" name="remove_cover_image" value="1" type="checkbox" class="form-check-input">
                            <label for="remove_cover_image" class="form-check-label">Remove current cover image</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group mb-0">
                    <label for="icon_image" class="form-label">Icon Image</label>
                    <input id="icon_image" name="icon_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                    <?php if ($iconPath !== ''): ?>
                        <div class="mt-2">
                            <img src="<?= e($iconUrl) ?>" alt="Current group icon image" class="img-thumbnail" style="max-width: 240px;">
                        </div>
                        <div class="small text-muted mt-1">
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                                data-rvn-copy-url="1"
                                data-copy-text="<?= e($iconCopyUrl) ?>"
                                data-copy-label="<?= e($iconPath) ?>"
                                title="Click to copy full URL"
                                aria-label="Copy full URL for icon image"
                            >
                                <code><?= e($iconPath) ?></code>
                            </button>
                        </div>
                        <div class="form-check mt-2">
                            <input id="remove_icon_image" name="remove_icon_image" value="1" type="checkbox" class="form-check-input">
                            <label for="remove_icon_image" class="form-check-label">Remove current icon image</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'permissions' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-permissions"
            role="tabpanel"
            aria-labelledby="group-permissions-tab"
            tabindex="0"
        >
            <fieldset class="mb-0">
                <legend class="h2">Permissions &amp; Routing</legend>
                <div class="form-text mb-2" style="margin-bottom: calc(0.5rem + 3px) !important;">Select the capabilities this group should have:</div>
                <?php if ($isBannedGroup): ?>
                    <div class="form-text mb-2">Banned group permissions and URI routing are permanently disabled.</div>
                <?php endif; ?>

                <h3>Site Permissions</h3>
                <p class="mb-2">
                    <code
                        id="group_public_index_url"
                        role="button"
                        tabindex="0"
                        title="Click to copy URL"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                    ><?= e($publicIndexUrl) ?></code>
                </p>

                <?php foreach ($publicPermissionDefinitions as $permission): ?>
                    <?php
                    $bit = (int) $permission['bit'];
                    $checked = ($permissionMask & $bit) === $bit;
                    $allowedForGuestLike = $isGuestLikeGroup && $bit === $viewPublicSiteBit;
                    $allowedForUser = $isUserGroup && in_array($bit, [$viewPublicSiteBit, $viewPrivateSiteBit], true);
                    $requiresPanelAccess = $bit === $viewDisabledSiteBit;
                    $panelAccessEnabled = ($permissionMask & PanelAccess::PANEL_LOGIN) === PanelAccess::PANEL_LOGIN;
                    $lockedPermission = !$isAdminGroup
                        && ($isBannedGroup || $isGuestLikeGroup || $isUserGroup)
                        && !($allowedForGuestLike || $allowedForUser);
                    if (!$lockedPermission && $requiresPanelAccess && !$panelAccessEnabled) {
                        $lockedPermission = true;
                        $checked = false;
                    }
                    ?>
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="permission_bits[]"
                            id="perm_<?= $bit ?>"
                            value="<?= $bit ?>"
                            <?= $checked ? 'checked' : '' ?>
                            <?= $lockedPermission ? 'disabled' : '' ?>
                            <?= $lockedPermission ? 'data-rvn-group-hard-disabled="1"' : '' ?>
                            <?= $bit === $viewDisabledSiteBit ? 'data-rvn-group-view-disabled="1"' : '' ?>
                        >
                        <label class="form-check-label<?= $lockedPermission ? ' text-muted' : '' ?>" for="perm_<?= $bit ?>">
                            <?= e($permission['label']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>

                <h3 class="mt-4">Panel Permissions</h3>
                <p class="mb-2">
                    <code
                        id="group_panel_index_url"
                        role="button"
                        tabindex="0"
                        title="Click to copy URL"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                    ><?= e($panelIndexUrl) ?></code>
                </p>
                <?php if (!$canEditConfigurationBit): ?>
                    <div class="form-text mb-2">Only Admin users can change system administration permissions.</div>
                <?php endif; ?>
                <?php if (is_array($panelLoginPermission)): ?>
                    <?php
                    $panelLoginBit = (int) ($panelLoginPermission['bit'] ?? PanelAccess::PANEL_LOGIN);
                    $panelLoginState = $panelPermissionState($panelLoginBit);
                    ?>
                    <div class="form-check mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="permission_bits[]"
                            id="perm_<?= $panelLoginBit ?>"
                            value="<?= $panelLoginBit ?>"
                            <?= !empty($panelLoginState['checked']) ? 'checked' : '' ?>
                            <?= !empty($panelLoginState['locked']) ? 'disabled' : '' ?>
                            <?= !empty($panelLoginState['locked']) ? 'data-rvn-group-hard-disabled="1"' : '' ?>
                            data-rvn-group-panel-login="1"
                        >
                        <label class="form-check-label<?= !empty($panelLoginState['locked']) ? ' text-muted' : '' ?>" for="perm_<?= $panelLoginBit ?>">
                            <?= e((string) ($panelLoginPermission['label'] ?? 'Access Dashboard')) ?>
                        </label>
                        <?php if (!empty($panelLoginState['configuration_locked']) && !empty($panelLoginState['checked'])): ?>
                            <input type="hidden" name="permission_bits[]" value="<?= $panelLoginBit ?>">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($panelPermissionMatrix !== []): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Panel Section</th>
                                    <?php foreach ($panelActionColumns as $actionLabel): ?>
                                        <th scope="col" class="text-center"><?= e($actionLabel) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($panelPermissionMatrix as $matrixRow): ?>
                                    <?php
                                    $rowLabel = (string) ($matrixRow['label'] ?? 'Panel');
                                    $rowActions = is_array($matrixRow['actions'] ?? null) ? $matrixRow['actions'] : [];
                                    ?>
                                    <tr>
                                        <th scope="row"><?= e($rowLabel) ?></th>
                                        <?php foreach ($panelActionColumns as $actionKey => $actionLabel): ?>
                                            <?php $permission = $rowActions[$actionKey] ?? null; ?>
                                            <td class="text-center">
                                                <?php if (!is_array($permission)): ?>
                                                    <span class="text-muted">-</span>
                                                <?php else: ?>
                                                    <?php
                                                    $bit = (int) ($permission['bit'] ?? 0);
                                                    $state = $panelPermissionState($bit);
                                                    ?>
                                                    <div class="form-check d-inline-flex align-items-center justify-content-center m-0">
                                                        <input
                                                            class="form-check-input"
                                                            type="checkbox"
                                                            name="permission_bits[]"
                                                            id="perm_<?= $bit ?>"
                                                            value="<?= $bit ?>"
                                                            <?= !empty($state['checked']) ? 'checked' : '' ?>
                                                            <?= !empty($state['locked']) ? 'disabled' : '' ?>
                                                            <?= !empty($state['locked']) ? 'data-rvn-group-hard-disabled="1"' : '' ?>
                                                            <?= !empty($state['requires_panel_access']) ? 'data-rvn-group-requires-panel-login="1"' : '' ?>
                                                        >
                                                        <label
                                                            class="visually-hidden<?= !empty($state['locked']) ? ' text-muted' : '' ?>"
                                                            for="perm_<?= $bit ?>"
                                                        >
                                                            <?= e($rowLabel . ': ' . (string) ($permission['label'] ?? $actionLabel)) ?>
                                                        </label>
                                                    </div>
                                                    <?php if (!empty($state['configuration_locked']) && !empty($state['checked'])): ?>
                                                        <input type="hidden" name="permission_bits[]" value="<?= $bit ?>">
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($extensionPermissionDefinitions !== []): ?>
                    <h3 class="mt-4">Extension Permissions</h3>
                    <div class="form-text mb-2">Assign extension access levels per group.</div>
                    <div class="row g-2">
                        <?php foreach ($extensionPermissionDefinitions as $permission): ?>
                            <?php
                            $bit = (int) ($permission['bit'] ?? 0);
                            if ($bit <= 0) {
                                continue;
                            }

                            $checked = ($permissionMask & $bit) === $bit;
                            $lockedPermission = $isBannedGroup || $isGuestLikeGroup || $isUserGroup;
                            $panelAccessEnabled = ($permissionMask & PanelAccess::PANEL_LOGIN) === PanelAccess::PANEL_LOGIN;
                            if (!$lockedPermission && !$panelAccessEnabled) {
                                $lockedPermission = true;
                                $checked = false;
                            }
                            ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="permission_bits[]"
                                        id="perm_<?= $bit ?>"
                                        value="<?= $bit ?>"
                                        <?= $checked ? 'checked' : '' ?>
                                        <?= $lockedPermission ? 'disabled' : '' ?>
                                        <?= $lockedPermission ? 'data-rvn-group-hard-disabled="1"' : '' ?>
                                        data-rvn-group-requires-panel-login="1"
                                    >
                                    <label class="form-check-label<?= $lockedPermission ? ' text-muted' : '' ?>" for="perm_<?= $bit ?>">
                                        <?= e((string) ($permission['label'] ?? 'Extension Access')) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
        </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Group</button>
        <a href="<?= e($panelBase) ?>/group" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Groups</a>
        <?php if ($canDeleteGroup): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this group? This cannot be undone.');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Group</button>
        <?php endif; ?>
    </nav>
</form>
<script>
  (function () {
    var panelAccessToggle = document.querySelector('input[type="checkbox"][data-rvn-group-panel-login="1"]');
    var viewDisabledToggle = document.querySelector('input[type="checkbox"][data-rvn-group-view-disabled="1"]');
    var panelDependentToggles = document.querySelectorAll('input[type="checkbox"][data-rvn-group-requires-panel-login="1"]');
    var syncDisabledSiteToggle = function () {
      if (!(viewDisabledToggle instanceof HTMLInputElement)) {
        return;
      }

      var allowViewDisabled = panelAccessToggle instanceof HTMLInputElement && panelAccessToggle.checked;
      viewDisabledToggle.disabled = !allowViewDisabled;
      if (!allowViewDisabled) {
        viewDisabledToggle.checked = false;
      }
      var viewDisabledLabel = document.querySelector('label[for="' + viewDisabledToggle.id + '"]');
      if (viewDisabledLabel instanceof HTMLElement) {
        viewDisabledLabel.classList.toggle('text-muted', !allowViewDisabled);
      }

      panelDependentToggles.forEach(function (toggle) {
        if (!(toggle instanceof HTMLInputElement)) {
          return;
        }
        if (toggle.dataset.rvnGroupHardDisabled === '1') {
          return;
        }
        if (toggle === viewDisabledToggle) {
          return;
        }

        toggle.disabled = !allowViewDisabled;
        if (!allowViewDisabled) {
          toggle.checked = false;
        }

        var toggleLabel = document.querySelector('label[for="' + toggle.id + '"]');
        if (toggleLabel instanceof HTMLElement) {
          toggleLabel.classList.toggle('text-muted', !allowViewDisabled);
        }
      });
    };
    if (panelAccessToggle instanceof HTMLInputElement) {
      panelAccessToggle.addEventListener('change', syncDisabledSiteToggle);
      syncDisabledSiteToggle();
    }

    var copyElements = [
      document.getElementById('group_public_index_url'),
      document.getElementById('group_panel_index_url')
    ];

    function fallbackCopy(text) {
      var temporaryInput = document.createElement('textarea');
      temporaryInput.value = text;
      temporaryInput.setAttribute('readonly', 'readonly');
      temporaryInput.style.position = 'absolute';
      temporaryInput.style.left = '-9999px';
      document.body.appendChild(temporaryInput);
      temporaryInput.select();

      var copied = false;
      try {
        copied = document.execCommand('copy');
      } catch (error) {
        copied = false;
      }

      document.body.removeChild(temporaryInput);
      return copied;
    }

    function tooltipFor(element) {
      if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
        return null;
      }

      return window.bootstrap.Tooltip.getOrCreateInstance(element, {
        trigger: 'manual',
        placement: 'top',
        title: 'Copied!'
      });
    }

    function showFeedback(element, text) {
      var tooltip = tooltipFor(element);
      if (tooltip === null) {
        return;
      }

      if (typeof tooltip.setContent === 'function') {
        tooltip.setContent({ '.tooltip-inner': text });
      }
      tooltip.show();
      window.setTimeout(function () {
        tooltip.hide();
      }, 900);
    }

    function copyElementText(element) {
      if (!(element instanceof HTMLElement)) {
        return;
      }

      var value = String(element.textContent || '').trim();
      if (value === '') {
        return;
      }

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(value).then(function () {
          showFeedback(element, 'Copied!');
        }).catch(function () {
          if (fallbackCopy(value)) {
            showFeedback(element, 'Copied!');
          } else {
            showFeedback(element, 'Copy failed');
          }
        });
        return;
      }

      showFeedback(element, fallbackCopy(value) ? 'Copied!' : 'Copy failed');
    }

    copyElements.forEach(function (element) {
      if (!(element instanceof HTMLElement)) {
        return;
      }

      element.addEventListener('click', function () {
        copyElementText(element);
      });
      element.addEventListener('keydown', function (event) {
        if (!(event instanceof KeyboardEvent)) {
          return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        event.preventDefault();
        copyElementText(element);
      });
    });
  })();
</script>

<script>
  // Copy-URL handlers for media tab image path buttons.
  (function () {
    function copyViaLegacyCommand(value) {
      var textArea = document.createElement('textarea');
      textArea.value = String(value || '');
      textArea.setAttribute('readonly', 'readonly');
      textArea.style.position = 'fixed';
      textArea.style.opacity = '0';
      textArea.style.pointerEvents = 'none';
      document.body.appendChild(textArea);
      textArea.select();
      textArea.setSelectionRange(0, textArea.value.length);
      var copied = false;
      try {
        copied = document.execCommand('copy');
      } catch (error) {
        copied = false;
      }
      document.body.removeChild(textArea);
      return copied;
    }

    function copyText(value, onDone) {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(function () {
          onDone(true);
        }).catch(function () {
          onDone(copyViaLegacyCommand(value));
        });
        return;
      }

      onDone(copyViaLegacyCommand(value));
    }

    function absoluteUrl(value) {
      var text = String(value || '').trim();
      if (text === '') {
        return '';
      }

      if (/^https?:\/\//i.test(text)) {
        return text;
      }

      if (text.charAt(0) === '/') {
        return window.location.origin + text;
      }

      return window.location.origin + '/' + text.replace(/^\/+/, '');
    }

    function showCopyFeedback(button, success) {
      if (!(button instanceof HTMLElement)) {
        return;
      }

      var originalTitle = String(button.getAttribute('data-copy-title') || button.getAttribute('title') || 'Click to copy full URL');
      button.setAttribute('data-copy-title', originalTitle);
      button.setAttribute('title', success ? 'Copied full URL' : 'Copy failed');
      button.classList.remove('text-muted', 'text-success', 'text-danger');
      button.classList.add(success ? 'text-success' : 'text-danger');
      window.setTimeout(function () {
        button.setAttribute('title', originalTitle);
        button.classList.remove('text-success', 'text-danger');
        button.classList.add('text-muted');
      }, 1200);
    }

    document.querySelectorAll('button[data-rvn-copy-url="1"][data-copy-text]').forEach(function (button) {
      button.addEventListener('click', function () {
        var value = absoluteUrl(button.getAttribute('data-copy-text'));
        if (value === '') {
          showCopyFeedback(button, false);
          return;
        }

        copyText(value, function (copied) {
          showCopyFeedback(button, copied);
        });
      });
    });
  })();
</script>
