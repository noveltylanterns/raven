<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/channels/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $channels */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-channels-delete-form';
$channelsTableId = 'channels-table';
$channelsSearchId = 'channels-filter-search';
$channelsCountId = 'channels-filter-count';
$channelsEmptyId = 'channels-filter-empty';
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($channels)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/channels'));
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
        <h1>Channels</h1>
        <p class="text-muted mb-0">Manage channel sections used for URL structure and channel landing pages.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/channels/delete">
    <?= $csrfField ?>
</form>

<div class="d-flex justify-content-end gap-2 mb-3">
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/channels/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Channel</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected channels? Linked pages will be detached.');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<div class="card">
    <div class="card-body">
        <?php if ($channels === []): ?>
            <p class="text-muted mb-0">No channels yet.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label h6 mb-1" for="<?= e($channelsSearchId) ?>">Search</label>
                    <input
                        id="<?= e($channelsSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by ID, title, or slug..."
                    >
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($channelsCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($channelsTableId) ?>"
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
                        <th scope="col" data-sort-key="slug" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Slug</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="pages" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Pages</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($channels as $channel): ?>
                        <?php
                        $channelId = (int) ($channel['id'] ?? 0);
                        $channelName = (string) ($channel['name'] ?? '');
                        $channelSlug = (string) ($channel['slug'] ?? '');
                        $channelPageCount = (int) ($channel['page_count'] ?? 0);
                        $channelPagesUrl = $panelBase . '/pages?channel=' . rawurlencode($channelSlug);
                        ?>
                        <tr
                            data-raven-sort-row="1"
                            data-sort-id="<?= e((string) $channelId) ?>"
                            data-sort-title="<?= e($channelName) ?>"
                            data-sort-slug="<?= e($channelSlug) ?>"
                            data-sort-pages="<?= e((string) $channelPageCount) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <?php // `data-raven-row-select` hooks into global layout row-highlighting script. ?>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="<?= $channelId ?>"
                                    form="<?= e($bulkDeleteFormId) ?>"
                                    data-raven-row-select="1"
                                    aria-label="Select channel <?= $channelId ?>"
                                >
                            </td>
                            <td><?= $channelId ?></td>
                            <td>
                                <?php // Name is primary affordance and links directly to edit screen. ?>
                                <a href="<?= e($panelBase) ?>/channels/edit/<?= $channelId ?>">
                                    <?= e($channelName) ?>
                                </a>
                            </td>
                            <td><?= e($channelSlug) ?></td>
                            <td>
                                <?php if ($channelPageCount > 0 && $channelSlug !== ''): ?>
                                    <a href="<?= e($channelPagesUrl) ?>"><?= $channelPageCount ?></a>
                                <?php else: ?>
                                    <?= $channelPageCount ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a
                                        class="btn btn-primary btn-sm"
                                        href="<?= e($panelBase) ?>/channels/edit/<?= $channelId ?>"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <form method="post" action="<?= e($panelBase) ?>/channels/delete" onsubmit="return confirm('Delete this channel? Linked pages will be detached.');">
                                        <?= $csrfField ?>
                                        <?php // Single-row delete path uses explicit id hidden field. ?>
                                        <input type="hidden" name="id" value="<?= $channelId ?>">
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
            <p id="<?= e($channelsEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No channels match the current filters.</p>
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
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/channels/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Channel</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected channels? Linked pages will be detached.');"
    >
        <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
    </button>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($channelsTableId) ?>');
        var searchInput = document.getElementById('<?= e($channelsSearchId) ?>');
        var countLabel = document.getElementById('<?= e($channelsCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($channelsEmptyId) ?>');

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
</script>
