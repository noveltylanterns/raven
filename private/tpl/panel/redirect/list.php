<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/redirect/list.php
 * Admin panel view template for Redirects list screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $redirectRows */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\ListWrapper;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-redirect-delete-form';
$redirectTableId = 'redirect-table';
$redirectSearchId = 'redirect-filter-search';
$redirectStatusSortId = 'redirect-sort-status';
$redirectChannelSortId = 'redirect-sort-channel';
$redirectCountId = 'redirect-filter-count';
$redirectEmptyId = 'redirect-filter-empty';
$pagination = is_array($pagination ?? null) ? $pagination : [];
$redirectStatusOptions = [];
$redirectChannelOptions = [];
foreach ($redirectRows as $redirectRow) {
    $statusLabel = (int) ($redirectRow['active'] ?? 0) === 1 ? 'Active' : 'Inactive';
    $statusOptionKey = strtolower($statusLabel);
    if (!isset($redirectStatusOptions[$statusOptionKey])) {
        $redirectStatusOptions[$statusOptionKey] = $statusLabel;
    }

    $channelSlugValue = trim((string) ($redirectRow['channel_slug'] ?? ''));
    $channelLabel = $channelSlugValue === '' ? '<none>' : $channelSlugValue;
    $channelOptionKey = strtolower($channelLabel);
    if (!isset($redirectChannelOptions[$channelOptionKey])) {
        $redirectChannelOptions[$channelOptionKey] = $channelLabel;
    }
}
asort($redirectStatusOptions, SORT_NATURAL | SORT_FLAG_CASE);
asort($redirectChannelOptions, SORT_NATURAL | SORT_FLAG_CASE);
// Build status and channel filter options HTML from collected row values.
$redirectStatusOptionsHtml = '<option value="">All Statuses</option>';
foreach ($redirectStatusOptions as $statusValue) {
    $statusLower = e(strtolower((string) $statusValue));
    $statusLabel = e((string) $statusValue);
    $redirectStatusOptionsHtml .= "<option value=\"{$statusLower}\">{$statusLabel}</option>";
}
$redirectChannelOptionsHtml = '<option value="">All Channels</option>';
foreach ($redirectChannelOptions as $channelValue) {
    $channelValueLower = e(strtolower((string) $channelValue));
    $channelValueE = e((string) $channelValue);
    $redirectChannelOptionsHtml .= "<option value=\"{$channelValueLower}\">{$channelValueE}</option>";
}
$redirectListToolbarItems = [
    '<a class="btn btn-primary" href="' . e($panelBase) . '/redirect/edit"><i class="bi bi-bookmark-plus me-2" aria-hidden="true"></i>New Redirect</a>',
    '<button type="submit" class="btn btn-danger" form="' . e($bulkDeleteFormId) . '" onclick="return confirm(\'Delete selected redirects?\');"><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>',
];
?>
<?= Header::render([
    'title' => 'Redirects',
    'summary' => 'Manage redirect rules, target destinations, and active route behavior.',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/redirect/delete">
    <?= $csrfField ?>
</form>

<?= Toolbar::render([
    'items' => $redirectListToolbarItems,
]) ?>

<?php ob_start(); ?>
<table
    id="<?= e($redirectTableId) ?>"
    class="table table-sm align-middle"
    data-rvn-sort-table="1"
    data-sort-default-key="title"
    data-sort-default-direction="asc"
>
    <thead>
    <tr>
        <th></th>
        <th scope="col" data-sort-key="id" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">ID</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="title" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Title</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="slug" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Slug</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="channel" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Channel</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="target" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Target URL</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="status" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Status</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" class="text-center">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($redirectRows as $row): ?>
        <?php
        $redirectId = (int) ($row['id'] ?? 0);
        $redirectTitle = (string) ($row['title'] ?? '');
        $redirectSlug = (string) ($row['slug'] ?? '');
        $channelSlug = trim((string) ($row['channel_slug'] ?? ''));
        $channelLabel = $channelSlug === '' ? '<none>' : $channelSlug;
        $statusLabel = (int) ($row['active'] ?? 0) === 1 ? 'Active' : 'Inactive';
        $statusBadgeClass = $statusLabel === 'Active' ? 'text-bg-success' : 'text-bg-warning';
        $targetUrl = trim((string) ($row['target'] ?? ''));
        $targetUrlLabel = $targetUrl !== '' ? $targetUrl : '<empty>';
        ?>
        <tr
            data-rvn-sort-row="1"
            data-sort-id="<?= e((string) $redirectId) ?>"
            data-sort-title="<?= e($redirectTitle) ?>"
            data-sort-slug="<?= e($redirectSlug) ?>"
            data-sort-channel="<?= e($channelLabel) ?>"
            data-sort-status="<?= e($statusLabel) ?>"
            data-sort-target="<?= e($targetUrlLabel) ?>"
        >
            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
            <?php // `data-rvn-row-select` hooks into global layout row-highlighting script. ?>
            <td>
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="selected_ids[]"
                    value="<?= $redirectId ?>"
                    form="<?= e($bulkDeleteFormId) ?>"
                    data-rvn-row-select="1"
                    aria-label="Select redirect <?= $redirectId ?>"
                >
            </td>
            <td><?= $redirectId ?></td>
            <td>
                <?php // Title is primary affordance and links directly to edit screen. ?>
                <a href="<?= e($panelBase) ?>/redirect/edit/<?= $redirectId ?>">
                    <?= e($redirectTitle) ?>
                </a>
            </td>
            <td><?= e($redirectSlug) ?></td>
            <td><?= $channelSlug === '' ? '&lt;none&gt;' : e($channelSlug) ?></td>
            <td>
                <?php if ($targetUrl !== ''): ?>
                    <a href="<?= e($targetUrl) ?>" target="_blank" rel="noreferrer noopener">
                        <?= e($targetUrl) ?>
                    </a>
                <?php else: ?>
                    <span class="text-muted">&lt;empty&gt;</span>
                <?php endif; ?>
            </td>
            <td><span class="badge <?= e($statusBadgeClass) ?>"><?= e($statusLabel) ?></span></td>
            <td class="text-center">
                <div class="d-flex justify-content-center gap-2">
                    <a
                        class="btn btn-primary btn-sm"
                        href="<?= e($panelBase) ?>/redirect/edit/<?= $redirectId ?>"
                        title="Edit"
                        aria-label="Edit"
                    >
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                        <span class="visually-hidden">Edit</span>
                    </a>
                    <form method="post" action="<?= e($panelBase) ?>/redirect/delete" onsubmit="return confirm('Delete this redirect?');">
                        <?= $csrfField ?>
                        <?php // Single-row delete path uses explicit id hidden field. ?>
                        <input type="hidden" name="id" value="<?= $redirectId ?>">
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
<?php $redirectTableHtml = (string) ob_get_clean(); ?>
<?= ListWrapper::render([
    'is_empty'            => $redirectRows === [],
    'empty_message'       => 'No redirects yet.',
    'search_id'           => $redirectSearchId,
    'search_col'          => 'col-12 col-lg-6',
    'search_placeholder'  => 'Filter by title, slug, status, channel, or target...',
    'filters'             => [
        [
            'id'           => $redirectStatusSortId,
            'label'        => 'Sort by Status',
            'col'          => 'col-12 col-sm-6 col-lg-3',
            'options_html' => $redirectStatusOptionsHtml,
        ],
        [
            'id'           => $redirectChannelSortId,
            'label'        => 'Sort by Channel',
            'col'          => 'col-12 col-sm-6 col-lg-3',
            'options_html' => $redirectChannelOptionsHtml,
        ],
    ],
    'count_id'            => $redirectCountId,
    'empty_id'            => $redirectEmptyId,
    'empty_match_message' => 'No redirects match the current filters.',
    'table_html'          => $redirectTableHtml,
    'pagination'          => [
        'current'     => max(1, (int) ($pagination['current'] ?? 1)),
        'total_pages' => max(1, (int) ($pagination['total_pages'] ?? 1)),
        'total_items' => max(0, (int) ($pagination['total_items'] ?? count($redirectRows))),
        'base_path'   => (string) ($pagination['base_path'] ?? ($panelBase . '/redirect')),
        'query'       => is_array($pagination['query'] ?? null) ? $pagination['query'] : [],
        'label'       => 'redirects',
        'aria_label'  => 'Redirects pagination',
    ],
]) ?>

<?= Toolbar::render([
    'items' => $redirectListToolbarItems,
]) ?>

<?php ob_start(); ?>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($redirectTableId) ?>');
        var searchInput = document.getElementById('<?= e($redirectSearchId) ?>');
        var statusSortSelect = document.getElementById('<?= e($redirectStatusSortId) ?>');
        var channelSortSelect = document.getElementById('<?= e($redirectChannelSortId) ?>');
        var countLabel = document.getElementById('<?= e($redirectCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($redirectEmptyId) ?>');

        if (!(table instanceof HTMLTableElement)) {
            return;
        }

        var tableBody = table.tBodies.length > 0 ? table.tBodies[0] : null;
        if (!(tableBody instanceof HTMLTableSectionElement)) {
            return;
        }

        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-rvn-sort-row="1"]'));
        if (rows.length === 0) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase();
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
                        row.getAttribute('data-sort-status'),
                        row.getAttribute('data-sort-target')
                    ].map(function (value) {
                        return normalize(value);
                    }).join(' ');
                    matchesSearch = searchableText.indexOf(query) !== -1;
                }
                var rowStatus = normalize(row.getAttribute('data-sort-status'));
                var rowChannel = normalize(row.getAttribute('data-sort-channel'));
                var matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
                var matchesChannel = selectedChannel === '' || rowChannel === selectedChannel;
                var isVisible = matchesSearch && matchesStatus && matchesChannel;

                row.classList.toggle('d-none', !isVisible);
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' redirects';
            }

            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        if (searchInput instanceof HTMLInputElement) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (statusSortSelect instanceof HTMLSelectElement) {
            statusSortSelect.addEventListener('change', applyFilters);
        }
        if (channelSortSelect instanceof HTMLSelectElement) {
            channelSortSelect.addEventListener('change', applyFilters);
        }

        applyFilters();
    });
<?php Footer::pushScript((string) ob_get_clean()); ?>
