<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/groups/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $groups */
/** @var bool $groupRoutingEnabledSystemWide */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-groups-delete-form';
$groupsTableId = 'groups-table';
$groupsSearchId = 'groups-filter-search';
$groupsTypeFilterId = 'groups-filter-type';
$groupsCountId = 'groups-filter-count';
$groupsEmptyId = 'groups-filter-empty';
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($groups)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/groups'));
$paginationQuery = is_array($pagination['query'] ?? null) ? $pagination['query'] : [];
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
?>
<header class="card">
    <div class="card-body">
        <h1>Groups</h1>
        <p class="text-muted mb-0">Manage permission groups, member assignments, and optional group route behavior.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/groups/delete">
    <?= $csrfField ?>
</form>

<div class="d-flex justify-content-end gap-2 mb-3">
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/groups/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Group</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected groups? Stock groups cannot be deleted.');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<div class="card">
    <div class="card-body">
        <?php if ($groups === []): ?>
            <p class="text-muted mb-0">No groups found.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label h6 mb-1" for="<?= e($groupsSearchId) ?>">Search</label>
                    <input
                        id="<?= e($groupsSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by ID or name..."
                    >
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label h6 mb-1" for="<?= e($groupsTypeFilterId) ?>">Type</label>
                    <select id="<?= e($groupsTypeFilterId) ?>" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="stock">Stock</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($groupsCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($groupsTableId) ?>"
                    class="table table-sm align-middle"
                    data-raven-sort-table="1"
                    data-sort-default-key="title"
                    data-sort-default-direction="asc"
                >
                    <thead>
                    <tr>
                        <th></th>
                        <th scope="col" data-sort-key="id" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">ID</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="title" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Title</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="members" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Members</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="routed" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Routed</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="type" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Type</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $group): ?>
                        <?php
                        $groupId = (int) ($group['id'] ?? 0);
                        $groupName = (string) ($group['name'] ?? '');
                        $memberCount = (int) ($group['member_count'] ?? 0);
                        $isStock = (int) ($group['is_stock'] ?? 0) === 1;
                        $isRouted = (int) ($group['route_enabled'] ?? 0) === 1;
                        $routedLabel = $isRouted ? 'Yes' : 'No';
                        $typeLabel = $isStock ? 'Stock' : 'Custom';
                        $typeBadgeClass = $isStock ? 'text-bg-info' : 'text-bg-success';
                        ?>
                        <tr
                            data-raven-sort-row="1"
                            data-sort-id="<?= e((string) $groupId) ?>"
                            data-sort-title="<?= e($groupName) ?>"
                            data-sort-members="<?= e((string) $memberCount) ?>"
                            data-sort-routed="<?= e($routedLabel) ?>"
                            data-sort-type="<?= e($typeLabel) ?>"
                            data-filter-type="<?= e(strtolower($typeLabel)) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <?php // `data-raven-row-select` hooks into global layout row-highlighting script. ?>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="<?= $groupId ?>"
                                    form="<?= e($bulkDeleteFormId) ?>"
                                    data-raven-row-select="1"
                                    aria-label="Select group <?= $groupId ?>"
                                >
                            </td>
                            <td><?= $groupId ?></td>
                            <td>
                                <a href="<?= e($panelBase) ?>/groups/edit/<?= $groupId ?>">
                                    <?= e($groupName) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($memberCount > 0): ?>
                                    <a
                                        href="<?= e($panelBase) ?>/users?group=<?= rawurlencode(strtolower($groupName)) ?>"
                                        title="View users in this group"
                                    >
                                        <?= $memberCount ?>
                                    </a>
                                <?php else: ?>
                                    <?= $memberCount ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$groupRoutingEnabledSystemWide && $isRouted): ?>
                                    <span class="text-decoration-line-through"><?= e($routedLabel) ?></span>
                                <?php else: ?>
                                    <?= e($routedLabel) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e($typeBadgeClass) ?>"><?= e($typeLabel) ?></span></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a
                                        class="btn btn-secondary btn-sm"
                                        href="<?= e($panelBase) ?>/groups/edit/<?= $groupId ?>"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <?php if (!$isStock): ?>
                                        <form method="post" action="<?= e($panelBase) ?>/groups/delete" onsubmit="return confirm('Delete this group? Users left without groups will be reassigned to User.');">
                                            <?= $csrfField ?>
                                            <?php // Single-row delete path uses explicit id hidden field. ?>
                                            <input type="hidden" name="id" value="<?= $groupId ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">
                                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                                <span class="visually-hidden">Delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($groupsEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No groups match the current filters.</p>
            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= $paginationTotalItems ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Groups pagination">
                            <a class="btn btn-outline-secondary<?= $paginationCurrent <= 1 ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent - 1)) ?>">Previous</a>
                            <a class="btn btn-outline-secondary<?= $paginationCurrent >= $paginationTotalPages ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent + 1)) ?>">Next</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/groups/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Group</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected groups? Stock groups cannot be deleted.');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($groupsTableId) ?>');
        var searchInput = document.getElementById('<?= e($groupsSearchId) ?>');
        var typeFilter = document.getElementById('<?= e($groupsTypeFilterId) ?>');
        var countLabel = document.getElementById('<?= e($groupsCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($groupsEmptyId) ?>');

        if (!(table instanceof HTMLTableElement) || !(searchInput instanceof HTMLInputElement)) {
            return;
        }

        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-raven-sort-row="1"]'));
        if (rows.length === 0) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase();
        }

        function applyFilters() {
            var query = normalize(searchInput.value).trim();
            var selectedType = typeFilter instanceof HTMLSelectElement
                ? normalize(typeFilter.value).trim()
                : '';
            var visibleCount = 0;

            rows.forEach(function (row) {
                var searchableText = [
                    row.getAttribute('data-sort-id'),
                    row.getAttribute('data-sort-title')
                ].map(function (value) {
                    return normalize(value);
                }).join(' ');
                var matchesSearch = query === '' || searchableText.indexOf(query) !== -1;
                var rowType = normalize(row.getAttribute('data-filter-type'));
                var matchesType = selectedType === '' || rowType === selectedType;
                var visible = matchesSearch && matchesType;
                row.classList.toggle('d-none', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' groups';
            }
            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        searchInput.addEventListener('input', applyFilters);
        if (typeFilter instanceof HTMLSelectElement) {
            typeFilter.addEventListener('change', applyFilters);
        }
        applyFilters();
    });
</script>
