<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/logs.php
 * Admin panel view template for the Event Log viewer.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Filters are submitted as a GET form so bookmarkable/shareable filter URLs work naturally.
// The clear action uses a POST form with CSRF, rendered via a Bootstrap modal to prevent accidental clears.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $rows */
/** @var array{severity: string, search: string} $filters */
/** @var array<string, mixed> $pagination */
/** @var int $totalItems */
/** @var bool $loggingEnabled */
/** @var bool $canClear */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$filters = is_array($filters ?? null) ? $filters : [];
$activeSeverity = trim((string) ($filters['severity'] ?? ''));
$activeSearch   = trim((string) ($filters['search'] ?? ''));
$rows = is_array($rows ?? null) ? $rows : [];

$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent    = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($rows)));
$paginationBasePath   = (string) ($pagination['base_path'] ?? ($panelBase . '/logs'));
$paginationQuery      = is_array($pagination['query'] ?? null) ? $pagination['query'] : [];

$buildPaginationUrl = static function (int $pageNumber) use ($paginationBasePath, $paginationQuery): string {
    $pageNumber = max(1, $pageNumber);
    $query = $paginationQuery;
    if ($pageNumber > 1) {
        $query['page'] = (string) $pageNumber;
    } else {
        unset($query['page']);
    }

    $queryString = http_build_query($query);
    return $paginationBasePath . ($queryString !== '' ? '?' . $queryString : '');
};

// Build export URL preserving active filters.
$exportQuery = [];
if ($activeSeverity !== '') {
    $exportQuery['severity'] = $activeSeverity;
}
if ($activeSearch !== '') {
    $exportQuery['search'] = $activeSearch;
}
$exportQueryString = http_build_query($exportQuery);
$exportUrl = $panelBase . '/logs/export' . ($exportQueryString !== '' ? '?' . $exportQueryString : '');

$severityBadgeClass = [
    'error' => 'text-bg-danger',
    'warn'  => 'text-bg-warning',
    'info'  => 'text-bg-info',
];

$severityOptions = [
    ''      => 'All Levels',
    'error' => 'Error',
    'warn'  => 'Warning',
    'info'  => 'Info',
];

$hasActiveFilter = $activeSeverity !== '' || $activeSearch !== '';
$clearFiltersUrl = $panelBase . '/logs';
?>

<header class="card">
    <div class="card-body">
        <h1>Event Log</h1>
        <p class="text-muted mb-0">Runtime errors, warnings, and informational events recorded by Raven.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if (!$loggingEnabled): ?>
<div class="alert alert-warning" role="alert">
    <strong>Logging is disabled.</strong>
    No events are being recorded. Enable <em>Log Errors</em> (or other levels) under
    <a href="<?= e($panelBase) ?>/configuration" class="alert-link">Configuration &rarr; Debug</a>.
</div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <!-- Server-side filter form: submits GET so URLs remain bookmarkable. -->
        <form method="get" action="<?= e($panelBase) ?>/logs" class="row g-2 mb-3">
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label mb-1" for="log-filter-severity">Severity</label>
                <select id="log-filter-severity" name="severity" class="form-select form-select-sm">
                    <?php foreach ($severityOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $activeSeverity === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-7">
                <label class="form-label mb-1" for="log-filter-search">Search</label>
                <input
                    id="log-filter-search"
                    type="search"
                    name="search"
                    class="form-control form-control-sm"
                    value="<?= e($activeSearch) ?>"
                    placeholder="Filter by message or channel..."
                >
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($hasActiveFilter): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e($clearFiltersUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($rows === [] && !$hasActiveFilter): ?>
            <p class="text-muted mb-0">No log entries yet.</p>
        <?php elseif ($rows === []): ?>
            <p class="text-muted mb-0">No entries match the current filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle font-monospace" id="log-table">
                    <thead>
                    <tr>
                        <th scope="col" class="text-nowrap" style="width:9em">Time (UTC)</th>
                        <th scope="col" style="width:6em">Level</th>
                        <th scope="col" style="width:8em">Channel</th>
                        <th scope="col">Message</th>
                        <th scope="col" style="width:4em" class="text-center">Context</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowId       = (int) ($row['id'] ?? 0);
                        $loggedAt    = (string) ($row['logged_at'] ?? '');
                        $severity    = strtolower(trim((string) ($row['severity'] ?? '')));
                        $channel     = (string) ($row['channel'] ?? 'system');
                        $message     = (string) ($row['message'] ?? '');
                        $context     = trim((string) ($row['context'] ?? ''));
                        $badgeClass  = (string) ($severityBadgeClass[$severity] ?? 'text-bg-secondary');
                        $severityLabel = $severity === 'warn' ? 'Warning' : ucfirst($severity);
                        $hasContext  = $context !== '' && $context !== 'null';
                        $contextId   = 'log-ctx-' . $rowId;
                        ?>
                        <tr>
                            <td class="text-nowrap small text-muted"><?= e($loggedAt) ?></td>
                            <td><span class="badge <?= e($badgeClass) ?>"><?= e($severityLabel) ?></span></td>
                            <td class="small"><?= e($channel) ?></td>
                            <td class="small"><?= e($message) ?></td>
                            <td class="text-center">
                                <?php if ($hasContext): ?>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= e($contextId) ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= e($contextId) ?>"
                                        title="Show context"
                                    ><i class="bi bi-braces" aria-hidden="true"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hasContext): ?>
                            <tr class="collapse" id="<?= e($contextId) ?>">
                                <td colspan="5">
                                    <pre class="mb-0 small bg-light p-2 rounded"><?= e($context) ?></pre>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= number_format($paginationTotalItems) ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Event log pagination">
                            <a
                                class="btn btn-outline-secondary<?= $paginationCurrent <= 1 ? ' disabled' : '' ?>"
                                href="<?= e($buildPaginationUrl($paginationCurrent - 1)) ?>"
                            >Previous</a>
                            <a
                                class="btn btn-outline-secondary<?= $paginationCurrent >= $paginationTotalPages ? ' disabled' : '' ?>"
                                href="<?= e($buildPaginationUrl($paginationCurrent + 1)) ?>"
                            >Next</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<nav class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-primary btn-sm" href="<?= e($exportUrl) ?>">
        <i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV
    </a>
    <?php if ($canClear): ?>
        <button
            type="button"
            class="btn btn-outline-danger btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#log-clear-modal"
        >
            <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear Log
        </button>
    <?php endif; ?>
</nav>

<?php if ($canClear): ?>
<!-- Clear Log confirmation modal -->
<div class="modal fade" id="log-clear-modal" tabindex="-1" aria-labelledby="log-clear-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="log-clear-modal-label">Clear Event Log</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This will permanently delete <strong>all</strong> event log entries. This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" action="<?= e($panelBase) ?>/logs/clear" class="d-inline">
                    <?= $csrfField ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear All Entries
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
