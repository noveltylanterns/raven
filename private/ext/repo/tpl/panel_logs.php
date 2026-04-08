<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/panel_logs.php
 * Repositories extension log viewer.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use function Raven\Lib\Support\e;

$formatTimestamp = static function (?string $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Unknown';
    }

    $time = strtotime($value);
    return $time === false ? e($value) : gmdate('Y-m-d H:i:s', $time) . ' UTC';
};

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Repositories'));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
$initialRepoFilter = trim((string) ($initialRepoFilter ?? ''));
$logsTableId = 'repo-logs-table';
$levelFilterId = 'repo-logs-filter-level';
$repoFilterId = 'repo-logs-filter-repo';
$countLabelId = 'repo-logs-filter-count';
$emptyMessageId = 'repo-logs-filter-empty';
$globalRepoFilterValue = '__global__';

// Derive the live filter options directly from the loaded log rows so this view stays self-contained.
$levelFilterOptions = [];
$repoFilterOptions = [];
$hasGlobalLogRows = false;
foreach ($logs as $row) {
    $levelKey = strtolower(trim((string) ($row['level'] ?? 'info')));
    if ($levelKey !== '') {
        $levelFilterOptions[$levelKey] = strtoupper($levelKey);
    }

    $repoSlug = trim((string) ($row['slug'] ?? ''));
    if ($repoSlug === '') {
        $hasGlobalLogRows = true;
        continue;
    }

    $repoFilterOptions[$repoSlug] = $repoSlug;
}

// Keep the selected repo visible in the filter even when it has no rows in the current log window.
if ($initialRepoFilter !== '' && !isset($repoFilterOptions[$initialRepoFilter])) {
    $repoFilterOptions[$initialRepoFilter] = (string) (($repo['slug'] ?? $initialRepoFilter));
}

asort($levelFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
asort($repoFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
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
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Mirror Git repositories into Raven with optional public browsing.') ?></p>
    </div>
</header>

<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
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
    <a href="<?= e($settingsPath) ?>" class="btn btn-primary"><i class="bi bi-gear me-2" aria-hidden="true"></i>Settings</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Repos</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h3><?= $repo !== null ? e((string) ($repo['label'] ?? $repo['slug'] ?? 'Repo')) . ' Logs' : 'Event Logs' ?></h3>
        <?php if ($logs === []): ?>
            <div class="border rounded p-4 text-center text-muted">No log rows are available for this view yet.</div>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-0" for="<?= e($levelFilterId) ?>">Level</label>
                    <select id="<?= e($levelFilterId) ?>" class="form-select form-select-sm">
                        <option value="">All Levels</option>
                        <?php foreach ($levelFilterOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label mb-0" for="<?= e($repoFilterId) ?>">Repo</label>
                    <select id="<?= e($repoFilterId) ?>" class="form-select form-select-sm">
                        <option value=""<?= $initialRepoFilter === '' ? ' selected' : '' ?>>All Repos</option>
                        <?php if ($hasGlobalLogRows): ?>
                            <option value="<?= e($globalRepoFilterValue) ?>"<?= $initialRepoFilter === $globalRepoFilterValue ? ' selected' : '' ?>>Global</option>
                        <?php endif; ?>
                        <?php foreach ($repoFilterOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>"<?= (string) $value === $initialRepoFilter ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="small text-muted mb-2" id="<?= e($countLabelId) ?>"></div>
            <div class="table-responsive">
                <table id="<?= e($logsTableId) ?>" class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Time</th>
                        <th>Level</th>
                        <th>Event</th>
                        <th>Repo</th>
                        <th>Message</th>
                    </tr>
                    </thead>
                    <tbody class="small">
                    <?php foreach ($logs as $row): ?>
                        <?php
                        $level = strtolower((string) ($row['level'] ?? 'info'));
                        $repoSlug = trim((string) ($row['slug'] ?? ''));
                        $repoFilterValue = $repoSlug !== '' ? $repoSlug : $globalRepoFilterValue;
                        ?>
                        <tr data-repo-log-row="1" data-level="<?= e($level) ?>" data-repo="<?= e($repoFilterValue) ?>">
                            <td><?= e($formatTimestamp(is_string($row['time'] ?? null) ? $row['time'] : null)) ?></td>
                            <td>
                                <span class="badge <?= $level === 'error' ? 'text-bg-danger' : ($level === 'warn' ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= e(strtoupper($level)) ?></span>
                            </td>
                            <td><code><?= e((string) ($row['event'] ?? 'unknown')) ?></code></td>
                            <td>
                                <?php if ($repoSlug !== ''): ?>
                                    <a href="<?= e($logsPath . '?repo=' . rawurlencode($repoSlug)) ?>"><?= e($repoSlug) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">Global</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($row['message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($emptyMessageId) ?>" class="text-muted mb-0 mt-2 d-none">No log rows match the current filters.</p>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <a href="<?= e($settingsPath) ?>" class="btn btn-primary"><i class="bi bi-gear me-2" aria-hidden="true"></i>Settings</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Repos</a>
</div>

<script>
  (function () {
    var table = document.getElementById('<?= e($logsTableId) ?>');
    var levelFilter = document.getElementById('<?= e($levelFilterId) ?>');
    var repoFilter = document.getElementById('<?= e($repoFilterId) ?>');
    var countLabel = document.getElementById('<?= e($countLabelId) ?>');
    var emptyMessage = document.getElementById('<?= e($emptyMessageId) ?>');

    if (!(table instanceof HTMLTableElement) || !(levelFilter instanceof HTMLSelectElement) || !(repoFilter instanceof HTMLSelectElement)) {
      return;
    }

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-repo-log-row="1"]'));

    function normalize(value) {
      return String(value || '').trim().toLowerCase();
    }

    function selectedValue(element) {
      return normalize(element.value);
    }

    // Keep filtering client-side to mirror Raven's routing table behavior without extra panel requests.
    function applyFilters() {
      var selectedLevel = selectedValue(levelFilter);
      var selectedRepo = selectedValue(repoFilter);
      var visibleRows = 0;

      rows.forEach(function (row) {
        var rowLevel = normalize(row.getAttribute('data-level') || '');
        var rowRepo = normalize(row.getAttribute('data-repo') || '');
        var visible = (selectedLevel === '' || rowLevel === selectedLevel)
          && (selectedRepo === '' || rowRepo === selectedRepo);

        row.classList.toggle('d-none', !visible);
        if (visible) {
          visibleRows += 1;
        }
      });

      if (countLabel) {
        countLabel.textContent = 'Showing ' + visibleRows + ' of ' + rows.length + ' log rows.';
      }

      if (emptyMessage) {
        emptyMessage.classList.toggle('d-none', visibleRows > 0);
      }
    }

    levelFilter.addEventListener('change', applyFilters);
    repoFilter.addEventListener('change', applyFilters);
    applyFilters();
  })();
</script>
