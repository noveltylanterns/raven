<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/panel_index.php
 * Repositories extension panel list view.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

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
$repoTableBodyId = 'repo-index-table-body';
$focusSlug = trim((string) ($focusSlug ?? ''));
$repoHeaderActions = [];
if ($extensionDocsUrl !== '') {
    $repoHeaderActions[] = '<a href="' . e($extensionDocsUrl) . '" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">'
        . '<i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation'
        . '</a>';
}
$repoIndexToolbarItems = [
    '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#repoImportModal"><i class="bi bi-box-arrow-in-down me-2" aria-hidden="true"></i>Import Repo</button>',
    '<a href="' . e($settingsPath) . '" class="btn btn-primary"><i class="bi bi-gear me-2" aria-hidden="true"></i>Settings</a>',
    '<a href="' . e($logsPath) . '" class="btn btn-primary"><i class="bi bi-clipboard2-pulse me-2" aria-hidden="true"></i>View Logs</a>',
];
?>
<?= Header::render([
    'title' => $extensionName !== '' ? $extensionName : 'Repositories',
    'title_class' => 'mb-0',
    'subheading_html' => 'by ' . e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown'),
    'summary' => $extensionDescription !== '' ? $extensionDescription : 'Mirror Git repositories into Raven with optional public browsing.',
    'actions' => $repoHeaderActions,
    'card_class' => 'card mb-3',
]) ?>

<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if (!empty($recentErrors)): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">Recent update errors</div>
        <ul class="mb-0">
            <?php foreach ($recentErrors as $row): ?>
                <li>
                    <?= e((string) ($row['message'] ?? 'Unknown repo error.')) ?>
                    <?php if (!empty($row['slug'])): ?>
                        <a href="<?= e($editBasePath . '/' . rawurlencode((string) $row['slug'])) ?>" class="alert-link">View repo</a>
                    <?php endif; ?>
                </li>
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

<?= Toolbar::render([
    'items' => $repoIndexToolbarItems,
    'tag' => 'div',
    'class' => 'd-flex flex-wrap justify-content-end gap-2 mb-3',
]) ?>

<div class="card mb-3">
    <div class="card-body">
        <h3>Configured Repositories</h3>
        <?php if ($repos === []): ?>
            <div class="border rounded p-4 text-center text-muted">
                No repositories are configured yet. Import a repo to start building the registry.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th style="width: 1%;"></th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="<?= e($repoTableBodyId) ?>">
                    <?php foreach ($repos as $repo): ?>
                        <?php
                        $repoSlug = (string) ($repo['slug'] ?? '');
                        $detailsId = 'repo-details-' . ($repoSlug !== '' ? $repoSlug : 'item');
                        $repoLabel = (string) ($repo['label'] ?? $repoSlug ?: 'Repository');
                        $repoDescription = trim((string) ($repo['description'] ?? ''));
                        $repoNotes = trim((string) ($repo['notes'] ?? ''));
                        $lastAttempted = is_string($repo['last_attempted_sync_at'] ?? null) ? $repo['last_attempted_sync_at'] : null;
                        $lastSuccessful = is_string($repo['last_successful_sync_at'] ?? null) ? $repo['last_successful_sync_at'] : null;
                        $lastError = trim((string) ($repo['last_error'] ?? ''));
                        $lastSummary = trim((string) ($repo['last_sync_summary'] ?? ''));
                        $publicRepoUrl = trim((string) ($repo['public_repo_url'] ?? ''));
                        $isFocusedRepo = $focusSlug !== '' && $focusSlug === $repoSlug;
                        ?>
                        <tr
                            id="<?= e('repo-row-' . ($repoSlug !== '' ? $repoSlug : 'item')) ?>"
                            data-summary-row="1"
                            data-details-id="<?= e($detailsId) ?>"
                            data-import-focus="<?= $isFocusedRepo ? '1' : '0' ?>"
                            tabindex="0"
                            role="button"
                            aria-expanded="<?= $isFocusedRepo ? 'true' : 'false' ?>"
                            aria-controls="<?= e($detailsId) ?>"
                            class="<?= e($isFocusedRepo ? 'table-success' : '') ?>"
                            style="cursor: pointer;"
                        >
                            <td class="text-center">
                                <i class="bi bi-chevron-down js-row-state-icon" aria-hidden="true"></i>
                            </td>
                            <td>
                                <div class="fw-semibold">
                                    <a href="<?= e($editBasePath . '/' . rawurlencode($repoSlug)) ?>">
                                        <?= e($repoLabel) ?>
                                    </a>
                                </div>
                            </td>
                            <td><code><?= e($repoSlug) ?></code></td>
                            <td class="text-center">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <a href="<?= e($editBasePath . '/' . rawurlencode($repoSlug)) ?>" class="btn btn-primary btn-sm" title="Settings" aria-label="Settings">
                                        <i class="bi bi-gear-fill" aria-hidden="true"></i>
                                        <span class="visually-hidden">Settings</span>
                                    </a>
                                    <a href="<?= e($logsPath . '?repo=' . rawurlencode($repoSlug)) ?>" class="btn btn-secondary btn-sm" title="View Log Items" aria-label="View Log Items">
                                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                                        <span class="visually-hidden">View Log Items</span>
                                    </a>
                                    <form method="post" action="<?= e($syncBasePath . '/' . rawurlencode($repoSlug)) ?>" class="d-inline m-0">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="return_to" value="index">
                                        <button type="submit" class="btn btn-success btn-sm" title="Manual Update" aria-label="Manual Update">
                                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                            <span class="visually-hidden">Manual Update</span>
                                        </button>
                                    </form>
                                    <form method="post" action="<?= e($deleteBasePath . '/' . rawurlencode($repoSlug)) ?>" class="d-inline m-0" onsubmit="return confirm('Delete this repository mirror and its stored data?');">
                                        <?= $csrfField ?>
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            <span class="visually-hidden">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr data-details-row-for="<?= e($detailsId) ?>">
                            <td colspan="4" class="p-0 border-0">
                                <div
                                    id="<?= e($detailsId) ?>"
                                    class="collapse js-repo-details<?= $isFocusedRepo ? ' show' : '' ?>"
                                    data-bs-parent="#<?= e($repoTableBodyId) ?>"
                                >
                                    <div class="p-3 mb-0 border-bottom">
                                        <div class="row g-3">
                                            <div class="col-12 col-xl-6">
                                                <h5>Repository Details</h5>
                                                <div class="small mb-1"><strong>Description:</strong> <?= $repoDescription !== '' ? e($repoDescription) : '<span class="text-muted">No description provided.</span>' ?></div>
                                                <?php if ($repoNotes !== ''): ?>
                                                    <div class="small mb-3" style="white-space: pre-wrap;"><strong>Notes:</strong> <?= e($repoNotes) ?></div>
                                                <?php endif; ?>
                                                <div class="small mb-1"><strong>Visibility:</strong> <?= e((string) ($repo['visibility_label'] ?? '-')) ?></div>
                                                <div class="small mb-1"><strong>Auto-update:</strong> <?= e((string) ($repo['auto_update_label'] ?? '-')) ?></div>
                                                <div class="small mb-1"><strong>Update Frequency:</strong> <?= e((string) ($repo['update_frequency_label'] ?? '-')) ?></div>
                                                <div class="small mb-1"><strong>Upstream Sources:</strong> <?= (int) ($repo['source_count'] ?? 0) ?></div>
                                                <div class="small mb-0"><strong>Default Branch:</strong> <?= e((string) ($repo['default_branch'] ?? 'Not detected yet')) ?></div>
                                            </div>
                                            <div class="col-12 col-xl-6">
                                                <h5>Sync Status</h5>
                                                <div class="small mb-1"><strong>Last Attempted Sync:</strong> <?= e($formatTimestamp($lastAttempted)) ?></div>
                                                <div class="small mb-1"><strong>Last Successful Sync:</strong> <?= e($formatTimestamp($lastSuccessful)) ?></div>
                                                <div class="small mb-1"><strong>Public Branch:</strong> <?= e((string) (($repo['public_branch'] ?? '') !== '' ? $repo['public_branch'] : (($repo['default_branch'] ?? '') !== '' ? $repo['default_branch'] : 'Not detected yet'))) ?></div>
                                                <div class="small mb-1"><strong>Last Sync Summary:</strong> <?= $lastSummary !== '' ? e($lastSummary) : '<span class="text-muted">No sync summary recorded yet.</span>' ?></div>
                                                <?php if ($lastError !== ''): ?>
                                                    <div class="small mb-1 text-danger"><strong>Last Error:</strong> <?= e($lastError) ?></div>
                                                <?php endif; ?>
                                                <?php if ($publicRepoUrl !== ''): ?>
                                                    <div class="small mb-0"><strong>Public Clone URL:</strong> <a href="<?= e($publicRepoUrl) ?>"><?= e($publicRepoUrl) ?></a></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= Toolbar::render([
    'items' => $repoIndexToolbarItems,
    'tag' => 'div',
    'class' => 'd-flex flex-wrap justify-content-end gap-2 mb-3',
]) ?>

<div class="modal fade" id="repoImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= e($savePath) ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Import Repository</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= $csrfField ?>
                    <input type="hidden" name="auto_update" value="system">
                    <input type="hidden" name="update_frequency" value="system">
                    <input type="hidden" name="public_branch" value="">
                    <input type="hidden" name="return_to" value="index">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="repo_import_label" class="form-label">Name</label>
                            <input type="text" class="form-control" id="repo_import_label" name="label" maxlength="160" placeholder="Auto-filled from the source URL if left blank.">
                            <div class="form-text">Raven derives this from the source URL when left blank.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="repo_import_slug" class="form-label">Slug</label>
                            <input type="text" class="form-control" id="repo_import_slug" name="slug" maxlength="120" pattern="[a-z0-9][a-z0-9_-]{0,119}" placeholder="Auto-filled from the source URL if left blank.">
                            <div class="form-text">Lowercase letters, numbers, hyphens & underscores only.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="repo_import_visibility" class="form-label">Visibility</label>
                            <select class="form-select" id="repo_import_visibility" name="visibility">
                                <?php foreach ($visibilityOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $value === 'private' ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label for="repo_import_source_url" class="form-label">Upstream Source</label>
                            <input type="hidden" name="sources[0][label]" value="Origin">
                            <input type="hidden" name="sources[0][branch]" value="">
                            <input type="text" class="form-control" id="repo_import_source_url" name="sources[0][url]" maxlength="2048" required>
                            <div class="form-text">HTTPS, SSH, and other Git remotes are all acceptable as long as the system Git binary can reach them.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="repo_import_sync_now" name="sync_now" checked>
                                <label class="form-check-label" for="repo_import_sync_now">Run an initial sync after saving</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Repo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php ob_start(); ?>
  (function () {
    var summaryRows = Array.from(document.querySelectorAll('[data-summary-row="1"]'));
    if (summaryRows.length === 0) {
      return;
    }

    function hasBootstrapCollapse() {
      return typeof window.bootstrap !== 'undefined' && typeof window.bootstrap.Collapse !== 'undefined';
    }

    function setRowState(row, expanded) {
      row.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      row.classList.toggle('table-active', expanded);

      var icon = row.querySelector('.js-row-state-icon');
      if (!(icon instanceof HTMLElement)) {
        return;
      }

      icon.classList.toggle('bi-chevron-down', !expanded);
      icon.classList.toggle('bi-chevron-up', expanded);
    }

    function bindBootstrapCollapseStateSync() {
      if (!hasBootstrapCollapse()) {
        return;
      }

      summaryRows.forEach(function (row) {
        var detailsId = String(row.getAttribute('data-details-id') || '');
        if (detailsId === '') {
          return;
        }

        var detailsPanel = document.getElementById(detailsId);
        if (!(detailsPanel instanceof HTMLElement)) {
          return;
        }

        if (detailsPanel.getAttribute('data-rvn-collapse-sync') === '1') {
          return;
        }

        detailsPanel.setAttribute('data-rvn-collapse-sync', '1');
        detailsPanel.addEventListener('show.bs.collapse', function () {
          setRowState(row, true);
        });
        detailsPanel.addEventListener('hide.bs.collapse', function () {
          setRowState(row, false);
        });
      });
    }

    summaryRows.forEach(function (row) {
      var detailsId = String(row.getAttribute('data-details-id') || '');
      if (detailsId === '') {
        return;
      }

      var detailsPanel = document.getElementById(detailsId);
      if (!(detailsPanel instanceof HTMLElement)) {
        return;
      }

      var togglePanel = function () {
        if (hasBootstrapCollapse()) {
          bindBootstrapCollapseStateSync();
          window.bootstrap.Collapse.getOrCreateInstance(detailsPanel, { toggle: false }).toggle();
          return;
        }

        detailsPanel.classList.toggle('show');
        setRowState(row, detailsPanel.classList.contains('show'));
      };

      row.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('a, button, input, select, textarea, label, form')) {
          return;
        }
        togglePanel();
      });

      row.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        togglePanel();
      });

      setRowState(row, detailsPanel.classList.contains('show'));
    });

    bindBootstrapCollapseStateSync();
    window.addEventListener('load', bindBootstrapCollapseStateSync);
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>

<?php ob_start(); ?>
  (function () {
    var sourceInput = document.getElementById('repo_import_source_url');
    var nameInput = document.getElementById('repo_import_label');
    var slugInput = document.getElementById('repo_import_slug');
    if (!(sourceInput instanceof HTMLInputElement) || !(nameInput instanceof HTMLInputElement) || !(slugInput instanceof HTMLInputElement)) {
      return;
    }

    function titleize(value) {
      return value.replace(/\b\w/g, function (character) {
        return character.toUpperCase();
      });
    }

    function deriveRepoIdentity(url) {
      var candidate = String(url || '').trim();
      if (candidate === '') {
        return { slug: '', label: '' };
      }

      candidate = candidate.replace(/[?#].*$/, '').replace(/\\/g, '/').replace(/\/+$/, '');
      var segments = candidate.split('/').filter(Boolean);
      var tail = segments.length > 0 ? segments[segments.length - 1] : '';
      tail = tail.replace(/\.git$/i, '').trim();
      if (tail === '') {
        return { slug: '', label: '' };
      }

      var slug = tail.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[-_]+|[-_]+$/g, '');
      if (!/^[a-z0-9][a-z0-9_-]{0,119}$/.test(slug)) {
        slug = '';
      }

      var label = titleize(tail.replace(/[_-]+/g, ' ').trim()).slice(0, 160);
      if (label === '' && slug !== '') {
        label = titleize(slug.replace(/[_-]+/g, ' ')).slice(0, 160);
      }

      return { slug: slug, label: label };
    }

    function syncFromUrl() {
      var derived = deriveRepoIdentity(sourceInput.value);
      if (nameInput.value.trim() === '' && derived.label !== '') {
        nameInput.value = derived.label;
      }
      if (slugInput.value.trim() === '' && derived.slug !== '') {
        slugInput.value = derived.slug;
      }
    }

    sourceInput.addEventListener('input', syncFromUrl);
    sourceInput.addEventListener('change', syncFromUrl);
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>

<?php ob_start(); ?>
  (function () {
    var focusRow = document.querySelector('[data-import-focus="1"]');
    if (!(focusRow instanceof HTMLElement)) {
      return;
    }

    window.addEventListener('load', function () {
      focusRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, { once: true });
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>
