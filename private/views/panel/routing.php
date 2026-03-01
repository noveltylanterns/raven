<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/routing.php
 * Admin panel routing inventory for public page/channel/redirect URL paths.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This screen is read-only and exists to surface effective public route paths and collisions.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array{total: int, pages: int, channels: int, redirects: int, conflicts: int} $routeSummary */
/** @var array<int, array{
 *   type_key: string,
 *   type_label: string,
 *   source_label: string,
 *   edit_url: string,
 *   public_url: string,
 *   target_url: string,
 *   status_key: string,
 *   status_label: string,
 *   notes: string,
 *   is_conflict: bool
 * }> $routeRows */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$conflictCount = max(0, (int) ($routeSummary['conflicts'] ?? 0));

$statusBadgeClass = [
    'published' => 'text-bg-success',
    'draft' => 'text-bg-secondary',
    'active' => 'text-bg-success',
    'inactive' => 'text-bg-secondary',
    'missing' => 'text-bg-warning',
];

$typeFilterOptions = [];
$statusFilterOptions = [];
foreach ($routeRows as $row) {
    $typeKey = strtolower(trim((string) ($row['type_key'] ?? '')));
    $typeLabel = trim((string) ($row['type_label'] ?? ''));
    if ($typeKey !== '' && $typeLabel !== '') {
        $typeFilterOptions[$typeKey] = $typeLabel;
    }

    $statusKey = strtolower(trim((string) ($row['status_key'] ?? '')));
    $statusLabel = trim((string) ($row['status_label'] ?? ''));
    if ($statusKey !== '' && $statusLabel !== '' && !in_array($typeKey, ['user', 'group'], true)) {
        $statusFilterOptions[$statusKey] = $statusLabel;
    }
}
asort($typeFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
asort($statusFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
?>
<header class="card">
    <div class="card-body">
        <h1>Routing Table</h1>
        <p class="text-muted mb-0">A sortable inventory of all public URI routes and their destinations.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="small text-muted text-uppercase">Pages</div>
                <div class="h5 mb-0"><?= (int) ($routeSummary['pages'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="small text-muted text-uppercase">Channels</div>
                <div class="h5 mb-0"><?= (int) ($routeSummary['channels'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="small text-muted text-uppercase">Redirects</div>
                <div class="h5 mb-0"><?= (int) ($routeSummary['redirects'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="small text-muted text-uppercase">Conflicts</div>
                <div class="h5 mb-0"><?= $conflictCount ?></div>
                <?php if ($conflictCount > 0): ?>
                    <button
                        type="button"
                        id="routing-filter-conflicts-toggle"
                        class="btn btn-danger btn-sm mt-2"
                        aria-pressed="false"
                    >
                        Conflicts Only
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($routeRows !== []): ?>
    <div class="d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-primary" href="<?= e($panelBase) ?>/routing/export">
            <i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV
        </a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if ($routeRows === []): ?>
            <p class="text-muted mb-0">No routing records found.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label h6 mb-1" for="routing-filter-search">Search</label>
                    <input
                        id="routing-filter-search"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by title, URL, type, or status..."
                    >
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label h6 mb-1" for="routing-filter-status">Status</label>
                    <select id="routing-filter-status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusFilterOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <span class="form-label h6 mb-1 d-inline-block">Types</span>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($typeFilterOptions as $value => $label): ?>
                            <?php $typeFilterId = 'routing-filter-type-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $value); ?>
                            <div class="form-check form-check-inline m-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="<?= e($typeFilterId) ?>"
                                    value="<?= e((string) $value) ?>"
                                    data-routing-type-filter="1"
                                    checked
                                >
                                <label class="form-check-label" for="<?= e($typeFilterId) ?>"><?= e((string) $label) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="small text-muted mb-2" id="routing-filter-count"></div>
            <div class="table-responsive">
                <table id="routing-table" class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th scope="col" data-sort-key="public_url" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label ps-4">URI</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="title" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Title</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="type" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Type</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center" data-sort-key="status" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Status</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($routeRows as $row): ?>
                        <?php
                        $typeKey = strtolower((string) ($row['type_key'] ?? ''));
                        $statusKey = strtolower((string) ($row['status_key'] ?? 'draft'));
                        $typeLabel = (string) ($row['type_label'] ?? '');
                        $sourceLabel = (string) ($row['source_label'] ?? '');
                        $statusLabel = (string) ($row['status_label'] ?? '');
                        $publicUrl = (string) ($row['public_url'] ?? '');
                        $badgeClass = (string) ($statusBadgeClass[$statusKey] ?? 'text-bg-secondary');
                        $isConflict = !empty($row['is_conflict']);
                        ?>
                        <tr
                            data-routing-row="1"
                            data-type="<?= e($typeKey) ?>"
                            data-status="<?= e($statusKey) ?>"
                            data-conflict="<?= $isConflict ? '1' : '0' ?>"
                            data-sort-type="<?= e($typeLabel) ?>"
                            data-sort-title="<?= e($sourceLabel) ?>"
                            data-sort-public-url="<?= e($publicUrl) ?>"
                            data-sort-status="<?= e($statusLabel) ?>"
                        >
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="raven-routing-copy-icon"
                                        data-routing-copy="1"
                                        data-copy-text="<?= e($publicUrl) ?>"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-title="Copy URI"
                                        aria-label="Copy URI"
                                    >
                                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                                        <span class="visually-hidden">Copy URI</span>
                                    </button>
                                    <a href="<?= e($publicUrl) ?>" target="_blank" rel="noreferrer noopener"><?= e($publicUrl) ?></a>
                                </div>
                            </td>
                            <td>
                                <?php $editUrl = trim((string) ($row['edit_url'] ?? '')); ?>
                                <?php if ($editUrl !== ''): ?>
                                    <a href="<?= e($editUrl) ?>"><?= e($sourceLabel) ?></a>
                                <?php else: ?>
                                    <?= e($sourceLabel) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="fw-normal"><?= e($typeLabel) ?></span></td>
                            <td class="text-center"><span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="routing-filter-empty" class="text-muted mb-0 mt-2 d-none">No routing records match the current filters.</p>
        <?php endif; ?>
    </div>
</div>
<?php if ($routeRows !== []): ?>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <a class="btn btn-primary" href="<?= e($panelBase) ?>/routing/export">
            <i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV
        </a>
    </div>
<?php endif; ?>
<script>
    (function () {
        var table = document.getElementById('routing-table');
        if (!(table instanceof HTMLTableElement)) {
            return;
        }

        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-routing-row="1"]'));
        var tableBody = table.tBodies.length > 0 ? table.tBodies[0] : null;
        var sortHeaders = Array.prototype.slice.call(table.querySelectorAll('thead th[data-sort-key]'));
        var typeFilters = Array.prototype.slice.call(document.querySelectorAll('input[data-routing-type-filter="1"]'));
        var statusFilter = document.getElementById('routing-filter-status');
        var searchFilter = document.getElementById('routing-filter-search');
        var conflictsToggle = document.getElementById('routing-filter-conflicts-toggle');
        var emptyMessage = document.getElementById('routing-filter-empty');
        var countLabel = document.getElementById('routing-filter-count');
        var conflictsOnly = false;
        var sortState = {
            key: 'public_url',
            direction: 'asc'
        };
        var sortAttrByKey = {
            type: 'data-sort-type',
            title: 'data-sort-title',
            public_url: 'data-sort-public-url',
            status: 'data-sort-status'
        };

        function normalize(value) {
            return String(value || '').trim().toLowerCase();
        }

        function selectedValue(element) {
            if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLSelectElement)) {
                return '';
            }

            return normalize(element.value);
        }

        function selectedTypeSet() {
            var selected = {};
            typeFilters.forEach(function (element) {
                if (!(element instanceof HTMLInputElement) || !element.checked) {
                    return;
                }

                var value = normalize(element.value);
                if (value !== '') {
                    selected[value] = true;
                }
            });

            return selected;
        }

        function sortValue(row, key) {
            var attrName = sortAttrByKey[key];
            if (typeof attrName !== 'string' || attrName === '') {
                return '';
            }

            return String(row.getAttribute(attrName) || '');
        }

        function compareNatural(left, right) {
            return String(left).localeCompare(String(right), undefined, {
                numeric: true,
                sensitivity: 'base'
            });
        }

        function updateSortHeaderState() {
            sortHeaders.forEach(function (header) {
                if (!(header instanceof HTMLTableCellElement)) {
                    return;
                }

                var key = String(header.getAttribute('data-sort-key') || '').trim();
                var caretIcon = header.querySelector('.raven-routing-sort-caret');
                if (key === '' || key !== sortState.key) {
                    header.setAttribute('aria-sort', 'none');
                    header.classList.remove('is-active-sort');
                    if (caretIcon instanceof HTMLElement) {
                        caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                    }
                    return;
                }

                header.setAttribute('aria-sort', sortState.direction === 'desc' ? 'descending' : 'ascending');
                header.classList.add('is-active-sort');
                if (caretIcon instanceof HTMLElement) {
                    caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                    caretIcon.classList.add(sortState.direction === 'desc' ? 'bi-caret-down-fill' : 'bi-caret-up-fill');
                }
            });
        }

        function sortRowsBy(key, forcedDirection) {
            if (!(tableBody instanceof HTMLTableSectionElement)) {
                return;
            }

            if (key === '') {
                return;
            }

            var direction = 'asc';
            if (forcedDirection === 'asc' || forcedDirection === 'desc') {
                direction = forcedDirection;
            } else if (sortState.key === key) {
                direction = sortState.direction === 'asc' ? 'desc' : 'asc';
            }

            sortState = {
                key: key,
                direction: direction
            };

            rows.sort(function (leftRow, rightRow) {
                var leftValue = sortValue(leftRow, key);
                var rightValue = sortValue(rightRow, key);
                var result = compareNatural(leftValue, rightValue);

                if (result === 0) {
                    result = compareNatural(
                        sortValue(leftRow, 'public_url'),
                        sortValue(rightRow, 'public_url')
                    );
                }

                if (result === 0) {
                    result = compareNatural(
                        sortValue(leftRow, 'title'),
                        sortValue(rightRow, 'title')
                    );
                }

                return direction === 'desc' ? -result : result;
            });

            rows.forEach(function (row) {
                tableBody.appendChild(row);
            });

            updateSortHeaderState();
        }

        function updateConflictsToggleState() {
            if (!(conflictsToggle instanceof HTMLButtonElement)) {
                return;
            }

            conflictsToggle.classList.toggle('active', conflictsOnly);
            conflictsToggle.setAttribute('aria-pressed', conflictsOnly ? 'true' : 'false');
        }

        function applyFilters() {
            var selectedTypes = selectedTypeSet();
            var typeSelectionCount = Object.keys(selectedTypes).length;
            var statusValue = selectedValue(statusFilter);
            var queryValue = selectedValue(searchFilter);
            var visibleCount = 0;

            rows.forEach(function (row) {
                var rowType = normalize(row.getAttribute('data-type'));
                var rowStatus = normalize(row.getAttribute('data-status'));
                var rowConflict = normalize(row.getAttribute('data-conflict'));
                var rowText = normalize(row.textContent);

                var matchesType = typeFilters.length === 0 || (typeSelectionCount > 0 && selectedTypes[rowType] === true);
                var matchesStatus = statusValue === '' || rowStatus === statusValue;
                var matchesQuery = queryValue === '' || rowText.indexOf(queryValue) !== -1;
                var matchesConflict = !conflictsOnly || rowConflict === '1';
                var visible = matchesType && matchesStatus && matchesQuery && matchesConflict;

                row.classList.toggle('d-none', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (emptyMessage instanceof HTMLElement) {
                emptyMessage.classList.toggle('d-none', visibleCount !== 0);
            }

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = visibleCount + ' shown';
            }
        }

        function copyViaLegacyCommand(value) {
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            var copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }

            document.body.removeChild(textarea);
            return copied;
        }

        function tooltipFor(element) {
            if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
                return null;
            }

            return window.bootstrap.Tooltip.getOrCreateInstance(element, {
                trigger: 'manual'
            });
        }

        function showCopyFeedback(button, message) {
            var tooltip = tooltipFor(button);
            if (tooltip === null) {
                return;
            }

            tooltip.setContent({
                '.tooltip-inner': message
            });
            tooltip.show();

            window.setTimeout(function () {
                tooltip.hide();
                tooltip.setContent({
                    '.tooltip-inner': 'Copy URL'
                });
            }, 900);
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

        [statusFilter, searchFilter].forEach(function (element) {
            if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLSelectElement)) {
                return;
            }

            element.addEventListener('input', applyFilters);
            element.addEventListener('change', applyFilters);
        });

        typeFilters.forEach(function (element) {
            if (!(element instanceof HTMLInputElement)) {
                return;
            }

            element.addEventListener('change', applyFilters);
        });

        if (conflictsToggle instanceof HTMLButtonElement) {
            conflictsToggle.addEventListener('click', function () {
                conflictsOnly = !conflictsOnly;
                updateConflictsToggleState();
                applyFilters();
            });
        }

        sortHeaders.forEach(function (header) {
            if (!(header instanceof HTMLTableCellElement)) {
                return;
            }

            header.addEventListener('click', function () {
                var key = String(header.getAttribute('data-sort-key') || '').trim();
                if (key === '') {
                    return;
                }

                sortRowsBy(key);
            });

            header.addEventListener('keydown', function (event) {
                if (!(event instanceof KeyboardEvent)) {
                    return;
                }

                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                var key = String(header.getAttribute('data-sort-key') || '').trim();
                if (key === '') {
                    return;
                }

                sortRowsBy(key);
            });
        });

        if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
            table.querySelectorAll('button[data-routing-copy="1"][data-bs-toggle="tooltip"]').forEach(function (element) {
                window.bootstrap.Tooltip.getOrCreateInstance(element);
            });
        }

        table.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            var button = target.closest('button[data-routing-copy="1"]');
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            event.preventDefault();
            var value = String(button.getAttribute('data-copy-text') || '').trim();
            if (value === '') {
                showCopyFeedback(button, 'Copy failed');
                return;
            }

            copyText(value, function (ok) {
                showCopyFeedback(button, ok ? 'Copied' : 'Copy failed');
            });
        });

        sortRowsBy('public_url', 'asc');
        updateConflictsToggleState();
        applyFilters();
    })();
</script>
