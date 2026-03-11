<?php

/**
 * RAVEN CMS
 * ~/private/vis/panel/preferences.php
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
/** @var array<string, array{label: string, url_prefix: string}> $profileContactOptions */
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
$profileContactOptions = is_array($profileContactOptions ?? null) ? $profileContactOptions : [];
$contactProfilesRaw = is_array($preferences['contact_profiles'] ?? null) ? $preferences['contact_profiles'] : [];
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
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['account', 'profile'], true) ? $requestedTab : 'account';
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
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </nav>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'account' ? ' active' : '' ?>"
                id="preferences-account-tab"
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
                class="nav-link<?= $activeTab === 'profile' ? ' active' : '' ?>"
                id="preferences-profile-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-profile"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-profile"
                aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>"
            >Profile</button>
        </li>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
        <div
            class="tab-pane fade<?= $activeTab === 'account' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-account"
            role="tabpanel"
            aria-labelledby="preferences-account-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input class="form-control"
                    id="username"
                    name="username"
                    required
                    value="<?= e((string) ($preferences['username'] ?? '')) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="display_name">Display Name</label>
                <input class="form-control"
                    id="display_name"
                    name="display_name"
                    value="<?= e((string) ($preferences['display_name'] ?? '')) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input class="form-control"
                    id="email"
                    name="email"
                    type="email"
                    required
                    value="<?= e((string) ($preferences['email'] ?? '')) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <input class="form-control"
                    id="new_password"
                    name="new_password"
                    type="password"
                >
                <div class="form-text">Leave blank to keep current password (minimum 8 chars if changing).</div>
            </div>

            <div class="form-group mb-0">
                <label class="form-label" for="theme">Panel Theme</label>
                <select class="form-select" id="theme" name="theme" required>
                    <?php foreach ($themeOptions as $option): ?>
                        <?php $optionLabel = $option === 'default' ? '<Default>' : ucfirst($option); ?>
                        <option value="<?= e($option) ?>"<?= (string) ($preferences['theme'] ?? 'default') === $option ? ' selected' : '' ?>>
                            <?= e($optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><code>&lt;Default&gt;</code> follows the system's configured default admin theme.</div>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'profile' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-profile"
            role="tabpanel"
            aria-labelledby="preferences-profile-tab"
            tabindex="0"
        >
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

            <div class="form-group mb-0">
                <label class="form-label h3 d-block">Contact Information</label>
                <div id="preferences-contact-profiles-list">
                    <?php foreach ($contactProfiles as $index => $contactProfile): ?>
                        <?php
                        $contactType = (string) ($contactProfile['type'] ?? '');
                        $contactValue = (string) ($contactProfile['value'] ?? '');
                        ?>
                        <div class="border rounded p-2 mb-2" data-preferences-contact-row="1">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Type</label>
                                    <select
                                        class="form-select"
                                        data-preferences-contact-key="type"
                                        name="contact_profiles[<?= (int) $index ?>][type]"
                                    >
                                        <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                                            <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                                            <?php $optionPrefix = (string) ($optionData['url_prefix'] ?? ''); ?>
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
                                        <span class="input-group-text d-none" data-preferences-contact-prefix-addon="1"></span>
                                        <input
                                            type="text"
                                            class="form-control"
                                            data-preferences-contact-key="value"
                                            name="contact_profiles[<?= (int) $index ?>][value]"
                                            value="<?= e($contactValue) ?>"
                                            placeholder="username/path or value"
                                        >
                                    </div>
                                </div>
                                <div class="col-auto ps-md-0 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger ms-2" data-preferences-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileContactOptions !== []): ?>
                    <button type="button" class="btn btn-primary" id="preferences-contact-profiles-add">Add More Contact Information</button>
                <?php else: ?>
                    <div class="form-text text-muted">No contact types are configured in <code>user.contact</code>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </nav>
</form>

<?php if ($profileContactOptions !== []): ?>
<template id="preferences-contact-profile-template">
    <div class="border rounded p-2 mb-2" data-preferences-contact-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select class="form-select" data-preferences-contact-key="type">
                    <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                        <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                        <?php $optionPrefix = (string) ($optionData['url_prefix'] ?? ''); ?>
                        <option value="<?= e((string) $optionSlug) ?>" data-url-prefix="<?= e($optionPrefix) ?>"><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md pe-md-0">
                <label class="form-label">Value</label>
                <div class="input-group">
                    <span class="input-group-text d-none" data-preferences-contact-prefix-addon="1"></span>
                    <input
                        type="text"
                        class="form-control"
                        data-preferences-contact-key="value"
                        placeholder="username/path or value"
                    >
                </div>
            </div>
            <div class="col-auto pe-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-preferences-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</template>
<script>
  (function () {
    var list = document.getElementById('preferences-contact-profiles-list');
    var addButton = document.getElementById('preferences-contact-profiles-add');
    var template = document.getElementById('preferences-contact-profile-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-preferences-contact-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var typeField = row.querySelector('[data-preferences-contact-key="type"]');
        var valueField = row.querySelector('[data-preferences-contact-key="value"]');
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
      var typeField = row.querySelector('[data-preferences-contact-key="type"]');
      var prefixAddon = row.querySelector('[data-preferences-contact-prefix-addon="1"]');
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
      if (!(target instanceof HTMLSelectElement) || target.getAttribute('data-preferences-contact-key') !== 'type') {
        return;
      }
      var row = target.closest('[data-preferences-contact-row="1"]');
      syncPrefixAddon(row);
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var removeButton = target.closest('[data-preferences-contact-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }
      var row = removeButton.closest('[data-preferences-contact-row="1"]');
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
