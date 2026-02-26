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
/** @var string|null $flashError */
/** @var array{
 *   source_key: string,
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

$canRunUpdater = $status === 'outdated';

$currentSeries = '<unknown>';
if (preg_match('/^\s*v?(\d+)\.(\d+)/i', $currentVersion, $currentSeriesMatch) === 1) {
    $currentSeries = $currentSeriesMatch[1] . '.' . $currentSeriesMatch[2];
}

$latestSeries = '<unknown>';
if (preg_match('/^\s*v?(\d+)\.(\d+)/i', $latestVersion, $latestSeriesMatch) === 1) {
    $latestSeries = $latestSeriesMatch[1] . '.' . $latestSeriesMatch[2];
}
?>
<div class="card mb-3">
    <div class="card-body">
        <h1 class="mb-3">Update System</h1>

        <?php if ($flashSuccess !== null): ?>
            <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
        <?php endif; ?>

        <?php if ($flashError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <label for="update-source-key" class="form-label mb-0">Upstream Source</label>
            <select class="form-select form-select-sm w-auto" id="update-source-key" name="source_key" aria-label="Upstream Source">
                <?php foreach ($updateSources as $source): ?>
                    <?php
                    $optionKey = (string) ($source['key'] ?? '');
                    $optionLabel = (string) ($source['label'] ?? $optionKey);
                    $optionRepo = (string) ($source['repo'] ?? '');
                    $optionText = $optionRepo === '' ? $optionLabel : ($optionLabel . ' (' . $optionRepo . ')');
                    ?>
                    <option value="<?= e($optionKey) ?>"<?= $optionKey === $sourceKey ? ' selected' : '' ?>>
                        <?= e($optionText) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <form method="post" action="<?= e($panelBase) ?>/updates/check" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <button type="submit" class="btn btn-primary btn-sm">Check for Updates</button>
    </form>

    <form method="post" action="<?= e($panelBase) ?>/updates/run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <button
            type="submit"
            class="btn btn-warning btn-sm"
            <?= $canRunUpdater ? '' : 'disabled' ?>
            title="<?= $canRunUpdater ? 'Run updater' : 'Updater can run only when a newer revision is detected.' ?>"
        >
            Run Updater
        </button>
    </form>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3">Current Status</h2>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($status)) ?></span>
            <span class="text-muted small">
                <?= e($statusMessage !== '' ? $statusMessage : 'No status message available.') ?>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Series</label>
                <div><code><?= e($currentSeries) ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Latest Upstream Series</label>
                <div><code><?= e($latestSeries) ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Git Revision</label>
                <div><code><?= e($currentRevision !== '' ? $currentRevision : '<unknown>') ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Latest Upstream Git Revision</label>
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
</div>

<div class="d-flex flex-wrap justify-content-end gap-2">
    <form method="post" action="<?= e($panelBase) ?>/updates/check" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <button type="submit" class="btn btn-primary btn-sm">Check for Updates</button>
    </form>

    <form method="post" action="<?= e($panelBase) ?>/updates/run" class="m-0">
        <?= $csrfField ?>
        <input type="hidden" name="source_key" value="<?= e($sourceKey) ?>" data-updater-source-key="1">
        <button
            type="submit"
            class="btn btn-warning btn-sm"
            <?= $canRunUpdater ? '' : 'disabled' ?>
            title="<?= $canRunUpdater ? 'Run updater' : 'Updater can run only when a newer revision is detected.' ?>"
        >
            Run Updater
        </button>
    </form>
</div>

<script>
  (function () {
    var sourceSelect = document.getElementById('update-source-key');
    if (!(sourceSelect instanceof HTMLSelectElement)) {
      return;
    }

    var hiddenInputs = document.querySelectorAll('input[type="hidden"][data-updater-source-key="1"]');
    var syncSourceValue = function () {
      hiddenInputs.forEach(function (node) {
        if (node instanceof HTMLInputElement) {
          node.value = sourceSelect.value;
        }
      });
    };

    sourceSelect.addEventListener('change', syncSourceValue);
    syncSourceValue();
  })();
</script>
