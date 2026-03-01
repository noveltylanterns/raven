<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/pages/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $pages */
/** @var string $prefilterChannel */
/** @var int $prefilterCategoryId */
/** @var int $prefilterTagId */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-pages-delete-form';
$pagesTableId = 'pages-table';
$pagesSearchId = 'pages-filter-search';
$pagesStatusSortId = 'pages-sort-status';
$pagesChannelSortId = 'pages-sort-channel';
$pagesCountId = 'pages-filter-count';
$pagesEmptyId = 'pages-filter-empty';
$prefilterChannel = strtolower(trim((string) $prefilterChannel));
$prefilterCategoryId = max(0, (int) $prefilterCategoryId);
$prefilterTagId = max(0, (int) $prefilterTagId);
$pagesStatusOptions = [];
$pagesChannelOptions = [];
foreach ($pages as $pageRow) {
    $statusLabel = (int) ($pageRow['is_published'] ?? 0) === 1 ? 'Published' : 'Draft';
    $statusOptionKey = strtolower($statusLabel);
    if (!isset($pagesStatusOptions[$statusOptionKey])) {
        $pagesStatusOptions[$statusOptionKey] = $statusLabel;
    }

    $channelSlugValue = trim((string) ($pageRow['channel_slug'] ?? ''));
    $channelLabel = $channelSlugValue === '' ? '<none>' : $channelSlugValue;
    $channelOptionKey = strtolower($channelLabel);
    if (!isset($pagesChannelOptions[$channelOptionKey])) {
        $pagesChannelOptions[$channelOptionKey] = $channelLabel;
    }
}
asort($pagesStatusOptions, SORT_NATURAL | SORT_FLAG_CASE);
asort($pagesChannelOptions, SORT_NATURAL | SORT_FLAG_CASE);
$prefilterPayload = [
    'channel' => $prefilterChannel,
    'category' => $prefilterCategoryId,
    'tag' => $prefilterTagId,
];
$prefilterPayloadJson = (string) json_encode($prefilterPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($pages)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/pages'));
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
        <h1>Pages</h1>
        <p class="text-muted mb-0">Create, organize, and manage your site pages with publication and channel controls.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/pages/delete">
    <?= $csrfField ?>
</form>

<div class="d-flex justify-content-end gap-2 mb-3">
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/pages/edit"><i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>Create Page</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected pages?');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<div class="card">
    <div class="card-body">
        <?php // Table contains edit/delete row actions for each page. ?>
        <?php if ($pages === []): ?>
            <p class="text-muted mb-0">No pages yet.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-lg-6">
                    <label class="form-label h6 mb-1" for="<?= e($pagesSearchId) ?>">Search</label>
                    <input
                        id="<?= e($pagesSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by title, slug, channel, or status..."
                    >
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label h6 mb-1" for="<?= e($pagesStatusSortId) ?>">Sort by Status</label>
                    <select id="<?= e($pagesStatusSortId) ?>" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($pagesStatusOptions as $statusValue): ?>
                            <option value="<?= e(strtolower((string) $statusValue)) ?>"><?= e((string) $statusValue) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label h6 mb-1" for="<?= e($pagesChannelSortId) ?>">Sort by Channel</label>
                    <select id="<?= e($pagesChannelSortId) ?>" class="form-select form-select-sm">
                        <option value=""<?= $prefilterChannel === '' ? ' selected' : '' ?>>All Channels</option>
                        <?php foreach ($pagesChannelOptions as $channelValue): ?>
                            <?php $channelValueLower = strtolower((string) $channelValue); ?>
                            <option value="<?= e($channelValueLower) ?>"<?= $prefilterChannel === $channelValueLower ? ' selected' : '' ?>><?= e((string) $channelValue) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($pagesCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($pagesTableId) ?>"
                    class="table table-sm align-middle"
                    data-raven-sort-table="1"
                    data-sort-default-key="id"
                    data-sort-default-direction="desc"
                >
                    <thead>
                    <tr>
                        <th></th>
                        <th scope="col" data-sort-key="id" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">ID</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="title" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Title</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="slug" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Slug</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="channel" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Channel</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="status" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Status</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $row): ?>
                        <?php
                        $rowId = (int) ($row['id'] ?? 0);
                        $rowTitle = (string) ($row['title'] ?? '');
                        $rowSlug = (string) ($row['slug'] ?? '');
                        $channelSlug = trim((string) ($row['channel_slug'] ?? ''));
                        $channelLabel = $channelSlug === '' ? '<none>' : $channelSlug;
                        $statusLabel = (int) ($row['is_published'] ?? 0) === 1 ? 'Published' : 'Draft';
                        $statusBadgeClass = $statusLabel === 'Published' ? 'text-bg-success' : 'text-bg-warning';
                        $rowCategoryIds = [];
                        $rowTagIds = [];
                        /** @var mixed $rawCategoryIds */
                        $rawCategoryIds = $row['category_ids'] ?? [];
                        if (is_array($rawCategoryIds)) {
                            foreach ($rawCategoryIds as $rawCategoryId) {
                                $categoryId = (int) $rawCategoryId;
                                if ($categoryId > 0) {
                                    $rowCategoryIds[$categoryId] = true;
                                }
                            }
                        }
                        /** @var mixed $rawTagIds */
                        $rawTagIds = $row['tag_ids'] ?? [];
                        if (is_array($rawTagIds)) {
                            foreach ($rawTagIds as $rawTagId) {
                                $tagId = (int) $rawTagId;
                                if ($tagId > 0) {
                                    $rowTagIds[$tagId] = true;
                                }
                            }
                        }
                        $rowCategoryFilter = '|' . implode('|', array_keys($rowCategoryIds)) . '|';
                        $rowTagFilter = '|' . implode('|', array_keys($rowTagIds)) . '|';
                        ?>
                        <tr
                            data-raven-sort-row="1"
                            data-sort-id="<?= e((string) $rowId) ?>"
                            data-sort-title="<?= e($rowTitle) ?>"
                            data-sort-slug="<?= e($rowSlug) ?>"
                            data-sort-channel="<?= e($channelLabel) ?>"
                            data-sort-status="<?= e($statusLabel) ?>"
                            data-filter-categories="<?= e($rowCategoryFilter) ?>"
                            data-filter-tags="<?= e($rowTagFilter) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="<?= $rowId ?>"
                                    form="<?= e($bulkDeleteFormId) ?>"
                                    data-raven-row-select="1"
                                    aria-label="Select page <?= $rowId ?>"
                                >
                            </td>
                            <td><?= $rowId ?></td>
                            <td>
                                <a href="<?= e($panelBase) ?>/pages/edit/<?= $rowId ?>">
                                    <?= e($rowTitle) ?>
                                </a>
                            </td>
                            <td><?= e($rowSlug) ?></td>
                            <td><?= $channelSlug === '' ? '&lt;none&gt;' : e($channelSlug) ?></td>
                            <td><span class="badge <?= e($statusBadgeClass) ?>"><?= e($statusLabel) ?></span></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a class="btn btn-primary btn-sm" href="<?= e($panelBase) ?>/pages/edit/<?= $rowId ?>" title="Edit" aria-label="Edit">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <?php // CSRF-protected delete action for this page row. ?>
                                    <form method="post" action="<?= e($panelBase) ?>/pages/delete" onsubmit="return confirm('Delete this page?');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            <span class="visually-hidden">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($pagesEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No pages match the current filters.</p>
            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= $paginationTotalItems ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Pages pagination">
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
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/pages/edit"><i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>Create Page</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected pages?');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($pagesTableId) ?>');
        var searchInput = document.getElementById('<?= e($pagesSearchId) ?>');
        var statusSortSelect = document.getElementById('<?= e($pagesStatusSortId) ?>');
        var channelSortSelect = document.getElementById('<?= e($pagesChannelSortId) ?>');
        var countLabel = document.getElementById('<?= e($pagesCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($pagesEmptyId) ?>');

        if (!(table instanceof HTMLTableElement)) {
            return;
        }

        var tableBody = table.tBodies.length > 0 ? table.tBodies[0] : null;
        if (!(tableBody instanceof HTMLTableSectionElement)) {
            return;
        }

        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-raven-sort-row="1"]'));
        if (rows.length === 0) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase();
        }

        var prefilter = <?= $prefilterPayloadJson ?>;
        if (typeof prefilter !== 'object' || prefilter === null) {
            prefilter = { channel: '', category: 0, tag: 0 };
        }
        prefilter.channel = normalize(prefilter.channel).trim();
        prefilter.category = Number(prefilter.category || 0);
        prefilter.tag = Number(prefilter.tag || 0);
        prefilter.active = prefilter.channel !== '' || prefilter.category > 0 || prefilter.tag > 0;

        function clearPrefilter(source) {
            if (!prefilter.active) {
                return;
            }

            if (source !== 'channel' && channelSortSelect instanceof HTMLSelectElement) {
                channelSortSelect.value = '';
            }

            prefilter.channel = '';
            prefilter.category = 0;
            prefilter.tag = 0;
            prefilter.active = false;

            if (typeof window.history.replaceState === 'function') {
                window.history.replaceState({}, document.title, '<?= e($panelBase) ?>/pages');
            }
        }

        function applyFilters() {
            var query = searchInput instanceof HTMLInputElement
                ? normalize(searchInput.value).trim()
                : '';
            var selectedStatus = statusSortSelect instanceof HTMLSelectElement
                ? normalize(statusSortSelect.value).trim()
                : '';
            var selectedChannel = channelSortSelect instanceof HTMLSelectElement
                ? normalize(channelSortSelect.value).trim()
                : '';

            var visibleCount = 0;
            rows.forEach(function (row) {
                var matchesSearch = true;
                if (query !== '') {
                    var searchableText = [
                        row.getAttribute('data-sort-id'),
                        row.getAttribute('data-sort-title'),
                        row.getAttribute('data-sort-slug'),
                        row.getAttribute('data-sort-channel'),
                        row.getAttribute('data-sort-status')
                    ].map(function (value) {
                        return normalize(value);
                    }).join(' ');
                    matchesSearch = searchableText.indexOf(query) !== -1;
                }
                var rowStatus = normalize(row.getAttribute('data-sort-status'));
                var rowChannel = normalize(row.getAttribute('data-sort-channel'));
                var matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
                var matchesChannel = selectedChannel === '' || rowChannel === selectedChannel;
                var matchesPrefilter = true;
                if (prefilter.active) {
                    if (prefilter.channel !== '' && rowChannel !== prefilter.channel) {
                        matchesPrefilter = false;
                    }
                    if (matchesPrefilter && prefilter.category > 0) {
                        var rowCategoryFilter = normalize(row.getAttribute('data-filter-categories'));
                        matchesPrefilter = rowCategoryFilter.indexOf('|' + String(prefilter.category) + '|') !== -1;
                    }
                    if (matchesPrefilter && prefilter.tag > 0) {
                        var rowTagFilter = normalize(row.getAttribute('data-filter-tags'));
                        matchesPrefilter = rowTagFilter.indexOf('|' + String(prefilter.tag) + '|') !== -1;
                    }
                }

                var isVisible = matchesSearch && matchesStatus && matchesChannel && matchesPrefilter;

                row.classList.toggle('d-none', !isVisible);
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' pages';
            }

            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        if (searchInput instanceof HTMLInputElement) {
            searchInput.addEventListener('input', function () {
                clearPrefilter('search');
                applyFilters();
            });
        }
        if (statusSortSelect instanceof HTMLSelectElement) {
            statusSortSelect.addEventListener('change', function () {
                clearPrefilter('status');
                applyFilters();
            });
        }
        if (channelSortSelect instanceof HTMLSelectElement) {
            channelSortSelect.addEventListener('change', function () {
                clearPrefilter('channel');
                applyFilters();
            });
        }

        applyFilters();
    });
</script>
