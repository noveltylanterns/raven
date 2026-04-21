<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/channel/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $channelRows */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-channel-delete-form';
$channelTableId = 'channel-table';
$channelSearchId = 'channel-filter-search';
$channelCountId = 'channel-filter-count';
$channelEmptyId = 'channel-filter-empty';
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($channelRows)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/channel'));
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
$channelListToolbarItems = [
    '<a class="btn btn-primary" href="' . e($panelBase) . '/channel/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Channel</a>',
    '<button type="submit" class="btn btn-danger" form="' . e($bulkDeleteFormId) . '" onclick="return confirm(\'Delete selected channels? Linked pages will be detached.\');"><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>',
];
?>
<?= Header::render([
    'title' => 'Channels',
    'summary_html' => 'Manage channel taxonomy for URL structure &amp; landing pages.',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/channel/delete">
    <?= $csrfField ?>
</form>

<?= Toolbar::render([
    'items' => $channelListToolbarItems,
]) ?>

<section class="card">
    <div class="card-body">
        <?php if ($channelRows === []): ?>
            <p class="text-muted mb-0">No channels yet.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label mb-1" for="<?= e($channelSearchId) ?>">Search</label>
                    <input
                        id="<?= e($channelSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by ID, title, or slug..."
                    >
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($channelCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($channelTableId) ?>"
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
                        <th scope="col" data-sort-key="pages" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Pages</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($channelRows as $channel): ?>
                        <?php
                        $channelId = (int) ($channel['id'] ?? 0);
                        $channelName = (string) ($channel['name'] ?? '');
                        $channelSlug = (string) ($channel['slug'] ?? '');
                        $channelPageCount = (int) ($channel['page_count'] ?? 0);
                        $channelPagesUrl = $panelBase . '/page?channel=' . rawurlencode($channelSlug);
                        $isStockRoot = $channelId === 0 && $channelSlug === 'root';
                        ?>
                        <tr
                            data-rvn-sort-row="1"
                            data-sort-id="<?= e((string) $channelId) ?>"
                            data-sort-title="<?= e($channelName) ?>"
                            data-sort-slug="<?= e($channelSlug) ?>"
                            data-sort-pages="<?= e((string) $channelPageCount) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <?php // `data-rvn-row-select` hooks into global layout row-highlighting script. ?>
                            <td>
                                <?php if (!$isStockRoot): ?>
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="selected_ids[]"
                                        value="<?= $channelId ?>"
                                        form="<?= e($bulkDeleteFormId) ?>"
                                        data-rvn-row-select="1"
                                        aria-label="Select channel <?= $channelId ?>"
                                    >
                                <?php endif; ?>
                            </td>
                            <td><?= $channelId ?></td>
                            <td>
                                <?php if ($isStockRoot): ?>
                                    <?= e($channelName) ?>
                                <?php else: ?>
                                    <?php // Name is primary affordance and links directly to edit screen. ?>
                                    <a href="<?= e($panelBase) ?>/channel/edit/<?= $channelId ?>">
                                        <?= e($channelName) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?= e($channelSlug) ?></td>
                            <td>
                                <?php if ($channelPageCount > 0 && $channelSlug !== '' && !$isStockRoot): ?>
                                    <a href="<?= e($channelPagesUrl) ?>"><?= $channelPageCount ?></a>
                                <?php else: ?>
                                    <?= $channelPageCount ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <?php if ($isStockRoot): ?>
                                        <span class="badge text-bg-info">Protected</span>
                                    <?php else: ?>
                                        <a
                                            class="btn btn-primary btn-sm"
                                            href="<?= e($panelBase) ?>/channel/edit/<?= $channelId ?>"
                                            title="Edit"
                                            aria-label="Edit"
                                        >
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                            <span class="visually-hidden">Edit</span>
                                        </a>
                                        <form method="post" action="<?= e($panelBase) ?>/channel/delete" onsubmit="return confirm('Delete this channel? Linked pages will be detached.');">
                                            <?= $csrfField ?>
                                            <?php // Single-row delete path uses explicit id hidden field. ?>
                                            <input type="hidden" name="id" value="<?= $channelId ?>">
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
            <p id="<?= e($channelEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No channels match the current filters.</p>
            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= $paginationTotalItems ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Channels pagination">
                            <a class="btn btn-outline-secondary<?= $paginationCurrent <= 1 ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent - 1)) ?>">Previous</a>
                            <a class="btn btn-outline-secondary<?= $paginationCurrent >= $paginationTotalPages ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent + 1)) ?>">Next</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?= Toolbar::render([
    'items' => $channelListToolbarItems,
]) ?>

<?php ob_start(); ?>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($channelTableId) ?>');
        var searchInput = document.getElementById('<?= e($channelSearchId) ?>');
        var countLabel = document.getElementById('<?= e($channelCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($channelEmptyId) ?>');

        if (!(table instanceof HTMLTableElement) || !(searchInput instanceof HTMLInputElement)) {
            return;
        }

        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-rvn-sort-row="1"]'));
        if (rows.length === 0) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase();
        }

        function applyFilters() {
            var query = normalize(searchInput.value).trim();
            var visibleCount = 0;

            rows.forEach(function (row) {
                var searchableText = [
                    row.getAttribute('data-sort-id'),
                    row.getAttribute('data-sort-title'),
                    row.getAttribute('data-sort-slug')
                ].map(function (value) {
                    return normalize(value);
                }).join(' ');
                var visible = query === '' || searchableText.indexOf(query) !== -1;
                row.classList.toggle('d-none', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' channels';
            }
            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        searchInput.addEventListener('input', applyFilters);
        applyFilters();
    });
<?php Footer::pushScript((string) ob_get_clean()); ?>
