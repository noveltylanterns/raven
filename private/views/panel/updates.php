<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/updates.php
 * Admin panel update-system scaffold with status checks and guarded run action.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This screen is intentionally conservative until upstream release/update flow is production-ready.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var array<int, string>|null $flashSuccessList */
/** @var string|null $flashError */
/** @var array{
 *   source_key: string,
 *   custom_repo: string,
 *   source_repo: string,
 *   current_version: string,
 *   current_revision: string,
 *   latest_version: string,
 *   latest_revision: string,
 *   status: string,
 *   message: string,
 *   checked_at: string,
 *   local_branch: string
 * } $updateStatus */
/** @var array<int, array{key: string, label: string, repo: string}> $updateSources */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$status = strtolower((string) ($updateStatus['status'] ?? 'unknown'));
$currentVersion = (string) ($updateStatus['current_version'] ?? '');
$currentRevision = (string) ($updateStatus['current_revision'] ?? '');
$latestVersion = (string) ($updateStatus['latest_version'] ?? '');
$latestRevision = (string) ($updateStatus['latest_revision'] ?? '');
$statusMessage = (string) ($updateStatus['message'] ?? '');
$sourceKey = (string) ($updateStatus['source_key'] ?? '');
$customRepo = (string) ($updateStatus['custom_repo'] ?? '');
$localBranch = (string) ($updateStatus['local_branch'] ?? '');

$badgeClass = 'text-bg-secondary';
if ($status === 'current') {
    $badgeClass = 'text-bg-success';
} elseif ($status === 'outdated') {
    $badgeClass = 'text-bg-warning';
} elseif ($status === 'diverged') {
    $badgeClass = 'text-bg-warning';
} elseif ($status === 'error') {
    $badgeClass = 'text-bg-danger';
}

$currentSeries = '<unknown>';
if (preg_match('/^\s*v?(\d+)\.(\d+)/i', $currentVersion, $currentSeriesMatch) === 1) {
    $currentSeries = $currentSeriesMatch[1] . '.' . $currentSeriesMatch[2];
}

$latestSeries = '<unknown>';
if (preg_match('/^\s*v?(\d+)\.(\d+)/i', $latestVersion, $latestSeriesMatch) === 1) {
    $latestSeries = $latestSeriesMatch[1] . '.' . $latestSeriesMatch[2];
}

$requiresForceRun = $status !== 'outdated';
?>

<header class="card">
    <div class="card-body">
        <h1>Update System</h1>
        <p class="text-muted">Update your Raven installation from one of our stock mirrors, or enter a custom repo.</p>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <label for="update-source-key" class="form-label mb-0">Upstream Source</label>
            <select class="form-select form-select-sm w-auto" id="update-source-key" name="source_key" aria-label="Upstream Source">
                <?php foreach ($updateSources as $source):
                    $optionKey = (string) ($source['key'] ?? '');
                    $optionLabel = (string) ($source['label'] ?? $optionKey);
                    $optionRepo = (string) ($source['repo'] ?? '');
                    $optionText = $optionRepo === '' ? $optionLabel : ($optionLabel . ' (' . $optionRepo . ')');
                ?>
                <option value="<?= e($optionKey) ?>"<?= $optionKey === $sourceKey ? ' selected' : '' ?>><?= e($optionText) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mt-2 d-none" id="updater-custom-repo-wrap">
            <label for="updater-custom-repo" class="form-label mb-1">Custom Git Repo</label>
            <input
              id="updater-custom-repo"
              type="text"
              class="form-control form-control-sm"
              value="<?= e($customRepo) ?>"
              placeholder="https://git.example.com/owner/repo.git or git@example.com:owner/repo.git"
              >
        </div>
    </div>
</header>

<?php if ($flashSuccess !== null || $flashSuccessList !== null): ?>
<div class="alert alert-success" role="alert">
    <?php if ($flashSuccess !== null): ?><div><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashSuccessList !== null): ?>
    <ul class="mb-0 mt-2">
        <?php foreach ($flashSuccessList as $successItem): ?><li><?= e($successItem) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<nav>
    <form method="post" action="<?= e($panelBase) ?>/updates/check" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <button type="submit" class="btn btn-primary">Check for Updates</button>
    </form>
    <form method="post" action="<?= e($panelBase) ?>/updates/dry-run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <button type="submit" class="btn btn-secondary">Dry Run</button>
    </form>
    <form method="post" action="<?= e($panelBase) ?>/updates/run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <input type="hidden" name="force_run" value="0" data-updater-force-run="1">
        <button
            type="submit"
            class="btn btn-warning js-updater-run-button"
            title="Run updater"
        >Run Updater</button>
    </form>
</nav>

<section class="card">
    <div class="card-body">
        <h2 class="h4 mb-3">Current Status</h2>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($status)) ?></span>
            <span class="text-muted small">
                <?= e($statusMessage !== '' ? $statusMessage : 'No status message available.') ?>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Version</label>
                <div><code><?= e($currentSeries) ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Latest Upstream Version</label>
                <div><code><?= e($latestSeries) ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Patch Release</label>
                <div><code><?= e($currentRevision !== '' ? $currentRevision : '<unknown>') ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Latest Upstream Patch Release</label>
                <div><code><?= e($latestRevision !== '' ? $latestRevision : '<unknown>') ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Branch</label>
                <div><code><?= e($localBranch !== '' ? $localBranch : '<unknown>') ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Default Branch</label>
                <div><code>main</code></div>
            </div>
        </div>
    </div>
</section>

<nav>
    <form method="post" action="<?= e($panelBase) ?>/updates/check" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <button type="submit" class="btn btn-primary">Check for Updates</button>
    </form>
    <form method="post" action="<?= e($panelBase) ?>/updates/dry-run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <button type="submit" class="btn btn-secondary">Dry Run</button>
    </form>
    <form method="post" action="<?= e($panelBase) ?>/updates/run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <input type="hidden" name="custom_repo" value="<?= e($customRepo) ?>" data-updater-custom-repo="1">
        <input type="hidden" name="force_run" value="0" data-updater-force-run="1">
        <button
            type="submit"
            class="btn btn-warning js-updater-run-button"
            title="Run updater"
        >Run Updater</button>
    </form>
</nav>

<?php if ($requiresForceRun): ?>
<div class="modal fade" id="updaterForceRunModal" tabindex="-1" aria-labelledby="updaterForceRunModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="updaterForceRunModalLabel">Force Run Updater?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Current status is <strong><?= e(ucfirst($status)) ?></strong>.</p>
                <p class="mb-2"><?= e($statusMessage !== '' ? $statusMessage : 'Updater status is not marked as Outdated.') ?></p>
                <p class="mb-0 text-danger">Running anyway will fetch upstream, hard-reset tracked files, and clean untracked non-ignored files.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="updaterForceRunConfirm">Run Updater Anyway</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
  (function () {
    var sourceSelect = document.getElementById('update-source-key');
    if (!(sourceSelect instanceof HTMLSelectElement)) {
      return;
    }
    var customRepoWrap = document.getElementById('updater-custom-repo-wrap');
    var customRepoInput = document.getElementById('updater-custom-repo');
    var customRepoKey = '<?= e('custom-git-repo') ?>';

    var hiddenInputs = document.querySelectorAll('input[type="hidden"][data-updater-source-key="1"]');
    var hiddenCustomRepoInputs = document.querySelectorAll('input[type="hidden"][data-updater-custom-repo="1"]');
    var syncCustomRepoValue = function () {
      var value = customRepoInput instanceof HTMLInputElement ? customRepoInput.value : '';
      hiddenCustomRepoInputs.forEach(function (node) {
        if (node instanceof HTMLInputElement) {
          node.value = value;
        }
      });
    };

    var syncCustomRepoVisibility = function () {
      var isCustom = sourceSelect.value === customRepoKey;
      if (customRepoWrap instanceof HTMLElement) {
        customRepoWrap.classList.toggle('d-none', !isCustom);
      }
      if (customRepoInput instanceof HTMLInputElement) {
        customRepoInput.disabled = !isCustom;
      }
    };

    var syncSourceValue = function () {
      hiddenInputs.forEach(function (node) {
        if (node instanceof HTMLInputElement) {
          node.value = sourceSelect.value;
        }
      });
      syncCustomRepoVisibility();
    };

    var syncAllUpdaterFields = function () {
      syncSourceValue();
      syncCustomRepoValue();
    };

    sourceSelect.addEventListener('change', syncSourceValue);
    if (customRepoInput instanceof HTMLInputElement) {
      customRepoInput.addEventListener('input', syncCustomRepoValue);
      customRepoInput.addEventListener('change', syncCustomRepoValue);
    }
    syncAllUpdaterFields();

    var allUpdaterForms = document.querySelectorAll(
      'form[action$="/updates/check"], form[action$="/updates/dry-run"], form[action$="/updates/run"]'
    );
    allUpdaterForms.forEach(function (formNode) {
      if (!(formNode instanceof HTMLFormElement)) {
        return;
      }

      formNode.addEventListener('submit', function () {
        syncAllUpdaterFields();
      });
    });

    var runForms = document.querySelectorAll('form[action$="/updates/run"]');
    runForms.forEach(function (formNode) {
      if (!(formNode instanceof HTMLFormElement)) {
        return;
      }

      var forceField = formNode.querySelector('input[type="hidden"][data-updater-force-run="1"]');
      if (forceField instanceof HTMLInputElement) {
        forceField.value = '0';
      }
    });

    <?php if ($requiresForceRun): ?>
      var modalNode = document.getElementById('updaterForceRunModal');
      var confirmButton = document.getElementById('updaterForceRunConfirm');
      var modal = null;
      var canUseModal = false;
      if (
        modalNode instanceof HTMLElement &&
        confirmButton instanceof HTMLButtonElement &&
        window.bootstrap &&
        window.bootstrap.Modal &&
        typeof window.bootstrap.Modal.getOrCreateInstance === 'function'
      ) {
        modal = window.bootstrap.Modal.getOrCreateInstance(modalNode);
        canUseModal = true;
      }
      var forceRunWarning = <?= json_encode(
          'Current status is ' . ucfirst($status) . '. '
          . ($statusMessage !== '' ? $statusMessage . ' ' : '')
          . 'Running anyway will fetch upstream, hard-reset tracked files, and clean untracked non-ignored files. '
          . 'Do you want to run the updater anyway?'
      ) ?>;
      var pendingRunForm = null;
      runForms.forEach(function (formNode) {
        if (!(formNode instanceof HTMLFormElement)) {
          return;
        }

        formNode.addEventListener('submit', function (event) {
          var forceField = formNode.querySelector('input[type="hidden"][data-updater-force-run="1"]');
          if (forceField instanceof HTMLInputElement && forceField.value === '1') {
            return;
          }

          event.preventDefault();
          pendingRunForm = formNode;
          if (canUseModal && modal !== null) {
            modal.show();
            return;
          }

          if (window.confirm(forceRunWarning) !== true) {
            pendingRunForm = null;
            return;
          }

          if (!(pendingRunForm instanceof HTMLFormElement)) {
            return;
          }

          var fallbackForceField = pendingRunForm.querySelector('input[type="hidden"][data-updater-force-run="1"]');
          if (fallbackForceField instanceof HTMLInputElement) {
            fallbackForceField.value = '1';
          }
          pendingRunForm.submit();
        });
      });

      if (!(confirmButton instanceof HTMLButtonElement) || modal === null) {
        return;
      }

      confirmButton.addEventListener('click', function () {
        if (!(pendingRunForm instanceof HTMLFormElement)) {
          return;
        }

        var forceField = pendingRunForm.querySelector('input[type="hidden"][data-updater-force-run="1"]');
        if (forceField instanceof HTMLInputElement) {
          forceField.value = '1';
        }
        modal.hide();
        pendingRunForm.submit();
      });
    <?php endif; ?>
  })();
</script>
