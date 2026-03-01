<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/groups/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $group */
/** @var string $groupRoutePrefix */
/** @var bool $groupRoutingEnabledSystemWide */
/** @var array<int, array{bit: int, label: string}> $permissionDefinitions */
/** @var bool $canEditConfigurationBit */
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
$permissionMask = (int) ($group['permission_mask'] ?? 0);
$viewPublicSiteBit = PanelAccess::VIEW_PUBLIC_SITE;
$viewPrivateSiteBit = PanelAccess::VIEW_PRIVATE_SITE;
$groupSlug = trim((string) ($group['slug'] ?? ''));
if ($groupSlug === '' && $groupName !== '') {
    if (strtolower($groupName) === 'super admin') {
        $groupSlug = 'super';
    } else {
        $groupSlug = strtolower($groupName);
        $groupSlug = preg_replace('/[^a-z0-9]+/', '-', $groupSlug) ?? '';
        $groupSlug = trim($groupSlug, '-');
        $groupSlug = preg_replace('/-+/', '-', $groupSlug) ?? '';
    }
}
$routeEnabledChecked = (int) ($group['route_enabled'] ?? 0) === 1;
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
$isEditorGroup = $groupRoleSlug === 'editor';
$isAdminGroup = $groupRoleSlug === 'admin';
$isSuperAdminGroup = $groupRoleSlug === 'super';
$routeEnabledChecked = ($isGuestLikeGroup || $isBannedGroup) ? false : $routeEnabledChecked;
if ($isBannedGroup) {
    $permissionMask = 0;
} elseif ($isGuestLikeGroup) {
    $permissionMask &= $viewPublicSiteBit;
} elseif ($isUserGroup) {
    $permissionMask &= ($viewPublicSiteBit | $viewPrivateSiteBit);
} elseif ($isEditorGroup) {
    $permissionMask &= ($viewPublicSiteBit | $viewPrivateSiteBit | PanelAccess::PANEL_LOGIN | PanelAccess::MANAGE_CONTENT);
} elseif ($isAdminGroup) {
    $permissionMask = ($permissionMask & (
        $viewPublicSiteBit
        | $viewPrivateSiteBit
        | PanelAccess::PANEL_LOGIN
        | PanelAccess::MANAGE_CONTENT
        | PanelAccess::MANAGE_TAXONOMY
        | PanelAccess::MANAGE_USERS
    )) | $viewPrivateSiteBit;
} elseif ($isSuperAdminGroup) {
    $permissionMask = (
        PanelAccess::VIEW_PUBLIC_SITE
        | PanelAccess::VIEW_PRIVATE_SITE
        | PanelAccess::PANEL_LOGIN
        | PanelAccess::MANAGE_CONTENT
        | PanelAccess::MANAGE_TAXONOMY
        | PanelAccess::MANAGE_USERS
        | PanelAccess::MANAGE_GROUPS
        | PanelAccess::MANAGE_CONFIGURATION
    );
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
foreach ($permissionDefinitions as $permission) {
    $bit = (int) ($permission['bit'] ?? 0);
    if (in_array($bit, [$viewPublicSiteBit, $viewPrivateSiteBit], true)) {
        $publicPermissionDefinitions[] = $permission;
    } else {
        $panelPermissionDefinitions[] = $permission;
    }
}
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $group === null ? 'New Group' : 'Edit Group: \'' . e($groupName !== '' ? $groupName : 'Untitled') . '\'' ?>
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
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/groups/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $groupId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/groups/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $groupId ?>">

    <!-- Match page-editor ergonomics with right-aligned top actions. -->
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Group</button>
        <a href="<?= e($panelBase) ?>/groups" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Groups</a>
        <?php if ($canDeleteGroup): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this group? Users left without groups will be reassigned to User.');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Group
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label for="name" class="form-label h5">Name</label>
                <input
                    id="name"
                    name="name"
                    class="form-control"
                    required
                    value="<?= e((string) ($group['name'] ?? '')) ?>"
                >
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label h5">Slug</label>
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

            <fieldset class="mb-0">
                <legend class="h5">Permissions &amp; Routing</legend>
                <div class="form-text mb-2" style="margin-bottom: calc(0.5rem + 3px) !important;">Select the capabilities this group should have:</div>
                <?php if ($isBannedGroup): ?>
                    <div class="form-text mb-2">Banned group permissions and URI routing are permanently disabled.</div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <h6 class="mb-2">Public Permissions</h6>
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
                            $allowedForEditor = $isEditorGroup
                                && in_array($bit, [$viewPublicSiteBit, $viewPrivateSiteBit], true);
                            $allowedForAdmin = $isAdminGroup
                                && in_array($bit, [$viewPublicSiteBit, $viewPrivateSiteBit], true);
                            $lockedPermission = ($isBannedGroup || $isGuestLikeGroup || $isUserGroup || $isEditorGroup || $isAdminGroup || $isSuperAdminGroup)
                                && !($allowedForGuestLike || $allowedForUser || $allowedForEditor || $allowedForAdmin);
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
                                >
                                <label class="form-check-label<?= $lockedPermission ? ' text-muted' : '' ?>" for="perm_<?= $bit ?>">
                                    <?= e($permission['label']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-12 col-lg-6">
                        <h6 class="mb-2">Panel Permissions</h6>
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
                            <div class="form-text mb-2">Only Super Admin users can change Manage System Configuration.</div>
                        <?php endif; ?>

                        <?php foreach ($panelPermissionDefinitions as $permission): ?>
                            <?php
                            $bit = (int) $permission['bit'];
                            $checked = ($permissionMask & $bit) === $bit;
                            $allowedForEditor = $isEditorGroup
                                && in_array($bit, [PanelAccess::PANEL_LOGIN, PanelAccess::MANAGE_CONTENT], true);
                            $allowedForAdmin = $isAdminGroup
                                && in_array($bit, [
                                    PanelAccess::PANEL_LOGIN,
                                    PanelAccess::MANAGE_CONTENT,
                                    PanelAccess::MANAGE_TAXONOMY,
                                    PanelAccess::MANAGE_USERS,
                                ], true);
                            $configurationPermissionLocked = !$canEditConfigurationBit
                                && $bit === PanelAccess::MANAGE_CONFIGURATION;
                            $lockedPermission = (($isBannedGroup || $isGuestLikeGroup || $isUserGroup || $isEditorGroup || $isAdminGroup || $isSuperAdminGroup)
                                && !($allowedForEditor || $allowedForAdmin));
                            $lockedPermission = $lockedPermission || $configurationPermissionLocked;
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
                                >
                                <label class="form-check-label<?= $lockedPermission ? ' text-muted' : '' ?>" for="perm_<?= $bit ?>">
                                    <?= e($permission['label']) ?>
                                </label>
                                <?php if ($configurationPermissionLocked && $checked): ?>
                                    <input type="hidden" name="permission_bits[]" value="<?= $bit ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr class="my-3">

                <div class="form-check">
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
            </fieldset>
        </div>
    </div>

    <!-- Duplicate actions at bottom so long forms do not require scrolling upward. -->
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Group</button>
        <a href="<?= e($panelBase) ?>/groups" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Groups</a>
        <?php if ($canDeleteGroup): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this group? Users left without groups will be reassigned to User.');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Group
            </button>
        <?php endif; ?>
    </div>
</form>
<script>
  (function () {
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
