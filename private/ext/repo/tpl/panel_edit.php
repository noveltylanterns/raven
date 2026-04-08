<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/panel_edit.php
 * Repositories extension per-repo settings editor.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use function Raven\Lib\Support\e;

$formatTimestamp = static function (?string $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Never';
    }

    $time = strtotime($value);
    return $time === false ? e($value) : gmdate('Y-m-d H:i', $time) . ' UTC';
};

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Repositories'));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
$selectedPublicBranch = trim((string) ($repo['public_branch'] ?? ''));
if ($selectedPublicBranch === '') {
    $selectedPublicBranch = trim((string) ($repo['default_branch'] ?? ''));
}
$displayDefaultBranch = $selectedPublicBranch !== '' ? $selectedPublicBranch : 'Not detected yet';
$knownBranches = is_array($repo['branch_cache'] ?? null) ? $repo['branch_cache'] : [];
if ($selectedPublicBranch !== '' && !in_array($selectedPublicBranch, $knownBranches, true)) {
    $knownBranches[] = $selectedPublicBranch;
}
$repoSourceListId = 'repo-source-rows-list';
$repoSourceAddButtonId = 'repo-source-rows-add';
$repoSourceTemplateId = 'repo-source-row-template';
$repoDeleteFormId = 'repo-delete-form';
?>
<header class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1 class="mb-0">
                <?= e($extensionName !== '' ? $extensionName : 'Repositories') ?>
            </h1>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
        <h5>by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h5>
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Mirror read-only Git repositories into Raven with panel controls and optional public browsing.') ?></p>
    </div>
</header>

<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if (!empty($recentErrors)): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">Recent repo-specific update errors</div>
        <ul class="mb-0">
            <?php foreach ($recentErrors as $row): ?>
                <li><?= e((string) ($row['message'] ?? 'Unknown repo error.')) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if (($schedulerMode ?? 'always') === 'off'): ?>
    <div class="alert alert-danger">
        Raven's fallback scheduler is disabled in <a href="<?= e($configurationPath) ?>" class="alert-link">System Configuration</a>. Enable <code>site.scheduler</code> there, or point server cron at <code>php private/bin/rvn-cron run</code> so repository auto-updates can execute.
    </div>
<?php elseif (!$schedulerAvailable): ?>
    <div class="alert alert-danger">
        Scheduler-backed auto-update execution is unavailable on this Raven build. Repository auto-updates will not run until core scheduler support is restored.
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <button type="submit" form="repo-settings-form" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Settings</button>
    <form method="post" action="<?= e($syncPath) ?>" class="d-inline">
        <?= $csrfField ?>
        <button type="submit" class="btn btn-success"><i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Resync</button>
    </form>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Repos</a>
    <button type="submit" form="<?= e($repoDeleteFormId) ?>" class="btn btn-danger" onclick="return confirm('Delete this repository mirror and its stored data?');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete</button>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2><?= e((string) ($repo['label'] ?? $repo['slug'] ?? 'Repository')) ?></h2>
        <p class="small text-muted">Per-repository overrides, source failover order, and visibility policy live here.</p>

        <div class="row g-3 small">
            <div class="col-md-3"><strong>Repo Slug</strong><br><code><?= e((string) ($repo['slug'] ?? '')) ?></code></div>
            <div class="col-md-3"><strong>Default Branch</strong><br><code><?= e($displayDefaultBranch) ?></code></div>
            <div class="col-md-3"><strong>Last Attempted Sync</strong><br><?= e($formatTimestamp(is_string($repo['last_attempted_sync_at'] ?? null) ? $repo['last_attempted_sync_at'] : null)) ?></div>
            <div class="col-md-3"><strong>Last Successful Sync</strong><br><?= e($formatTimestamp(is_string($repo['last_successful_sync_at'] ?? null) ? $repo['last_successful_sync_at'] : null)) ?></div>
        </div>
        <?php if (!empty($repo['last_sync_summary'])): ?>
            <div class="mt-3 small text-muted"><?= e((string) $repo['last_sync_summary']) ?></div>
        <?php endif; ?>
    </div>
</div>

<form id="repo-settings-form" method="post" action="<?= e($savePath) ?>">
    <?= $csrfField ?>
    <input type="hidden" name="slug" value="<?= e((string) ($repo['slug'] ?? '')) ?>">
    <div class="card mb-3">
        <div class="card-body">
            <h2>Repository Settings</h2>
            <p class="small text-muted">Adjust metadata, visibility, update behavior, and upstream sources for this mirror.</p>

            <div class="row g-4">
                <div class="col-lg-6">
                    <h3>Repository Details</h3>
                    <div class="mb-3">
                        <label for="repo_label" class="form-label">Label</label>
                        <input type="text" class="form-control" id="repo_label" name="label" maxlength="160" value="<?= e((string) ($repo['label'] ?? '')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="repo_description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="repo_description" name="description" maxlength="500" value="<?= e((string) ($repo['description'] ?? '')) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="repo_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="repo_notes" name="notes" rows="5" maxlength="4000"><?= e((string) ($repo['notes'] ?? '')) ?></textarea>
                    </div>
                </div>
                <div class="col-lg-6">
                    <h3>Visibility and Update Overrides</h3>
                    <div class="mb-3">
                        <label for="repo_visibility" class="form-label">Visibility</label>
                        <select class="form-select" id="repo_visibility" name="visibility">
                            <?php foreach ($visibilityOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string) ($repo['visibility'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="repo_auto_update" class="form-label">Auto-update</label>
                        <select class="form-select" id="repo_auto_update" name="auto_update">
                            <?php foreach ($autoUpdateOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string) ($repo['auto_update'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="repo_update_frequency" class="form-label">Update frequency</label>
                        <select class="form-select" id="repo_update_frequency" name="update_frequency">
                            <?php foreach ($frequencyOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string) ($repo['update_frequency'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="repo_default_branch" class="form-label">Default Branch</label>
                        <select class="form-select" id="repo_default_branch" name="public_branch">
                            <?php if ($knownBranches === []): ?>
                                <option value="" selected disabled>No branches detected yet</option>
                            <?php else: ?>
                                <?php foreach ($knownBranches as $branch): ?>
                                    <?php $branchName = (string) $branch; ?>
                                    <?php if ($branchName === '') { continue; } ?>
                                    <option value="<?= e($branchName) ?>"<?= $selectedPublicBranch === $branchName ? ' selected' : '' ?>><?= e($branchName) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">Choose the detected branch that should load by default in public browsing.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h3>Upstream Sources</h3>
            <p class="small text-muted">Sources are tried in the order listed below. The first one that syncs successfully becomes the current source of truth for that run.</p>
            <div id="<?= e($repoSourceListId) ?>">
                <?php foreach ($sourceRows as $index => $row): ?>
                    <div class="border rounded p-2 mb-2" data-repo-source-row="1">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Label</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-repo-source-key="label"
                                    name="sources[<?= e((string) $index) ?>][label]"
                                    maxlength="120"
                                    value="<?= e((string) ($row['label'] ?? '')) ?>"
                                >
                            </div>
                            <div class="col-md">
                                <label class="form-label">Remote URL</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-repo-source-key="url"
                                    name="sources[<?= e((string) $index) ?>][url]"
                                    maxlength="2048"
                                    value="<?= e((string) ($row['url'] ?? '')) ?>"
                                >
                            </div>
                            <div class="col-md-3 pe-md-0">
                                <label class="form-label">Branch</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-repo-source-key="branch"
                                    name="sources[<?= e((string) $index) ?>][branch]"
                                    maxlength="255"
                                    value="<?= e((string) ($row['branch'] ?? '')) ?>"
                                >
                            </div>
                            <div class="col-auto ps-md-0 d-flex align-items-end">
                                <button type="button" class="btn btn-danger ms-2" data-repo-source-remove="1" aria-label="Remove source row">
                                    <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="<?= e($repoSourceAddButtonId) ?>">Add Upstream Source</button>
        </div>
    </div>
</form>

<form id="<?= e($repoDeleteFormId) ?>" method="post" action="<?= e($deletePath) ?>" class="d-none">
    <?= $csrfField ?>
</form>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">    <button type="submit" form="repo-settings-form" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Settings</button>
    <form method="post" action="<?= e($syncPath) ?>" class="d-inline">
        <?= $csrfField ?>
        <button type="submit" class="btn btn-success"><i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Resync</button>
    </form>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Repos</a>
    <button type="submit" form="<?= e($repoDeleteFormId) ?>" class="btn btn-danger" onclick="return confirm('Delete this repository mirror and its stored data?');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete</button>
</div>

<template id="<?= e($repoSourceTemplateId) ?>">
    <div class="border rounded p-2 mb-2" data-repo-source-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Label</label>
                <input type="text" class="form-control" data-repo-source-key="label" maxlength="120" value="">
            </div>
            <div class="col-md">
                <label class="form-label">Remote URL</label>
                <input type="text" class="form-control" data-repo-source-key="url" maxlength="2048" value="">
            </div>
            <div class="col-md-3 pe-md-0">
                <label class="form-label">Branch</label>
                <input type="text" class="form-control" data-repo-source-key="branch" maxlength="255" value="">
            </div>
            <div class="col-auto ps-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-repo-source-remove="1" aria-label="Remove source row">
                    <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</template>
<script>
  (function () {
    var list = document.getElementById('<?= e($repoSourceListId) ?>');
    var addButton = document.getElementById('<?= e($repoSourceAddButtonId) ?>');
    var template = document.getElementById('<?= e($repoSourceTemplateId) ?>');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-repo-source-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var labelField = row.querySelector('[data-repo-source-key="label"]');
        var urlField = row.querySelector('[data-repo-source-key="url"]');
        var branchField = row.querySelector('[data-repo-source-key="branch"]');

        if (labelField instanceof HTMLInputElement) {
          labelField.name = 'sources[' + index + '][label]';
        }
        if (urlField instanceof HTMLInputElement) {
          urlField.name = 'sources[' + index + '][url]';
        }
        if (branchField instanceof HTMLInputElement) {
          branchField.name = 'sources[' + index + '][branch]';
        }
      });
    }

    function appendRow(defaultLabel) {
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('[data-repo-source-row="1"]');
      if (row instanceof HTMLElement) {
        var labelField = row.querySelector('[data-repo-source-key="label"]');
        if (labelField instanceof HTMLInputElement) {
          labelField.value = String(defaultLabel || '');
        }
      }
      list.appendChild(fragment);
      reindexRows();
    }

    addButton.addEventListener('click', function () {
      appendRow('');
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var removeButton = target.closest('[data-repo-source-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }

      var row = removeButton.closest('[data-repo-source-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.remove();

      // Keep one editable source row present because save validation still requires at least one upstream URL.
      if (list.querySelector('[data-repo-source-row="1"]') === null) {
        appendRow('Origin');
        return;
      }

      reindexRows();
    });

    reindexRows();
  })();
</script>
