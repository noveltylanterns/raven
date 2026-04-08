<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/tag/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $tagRows */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $setOptions */
/** @var int|null $selectedSetId */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Lib\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-tag-delete-form';
$tagTableId = 'tag-table';
$tagSearchId = 'tag-filter-search';
$tagCountId = 'tag-filter-count';
$tagEmptyId = 'tag-filter-empty';
$tagSetFilterId = 'tag-filter-set';
$tagSetNames = [];
foreach ($setOptions as $setOption) {
    $tagSetNames[(int) ($setOption['id'] ?? 0)] = (string) ($setOption['name'] ?? 'Set');
}
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($tagRows)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/tag'));
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
        <h1>Tags</h1>
        <p class="text-muted mb-0">Manage tags used for page labeling, filtering, and public tag index views.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/tag/delete">
    <?= $csrfField ?>
</form>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/tag/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Tag</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/tag/set"><i class="bi bi-collection me-2" aria-hidden="true"></i>Manage Sets</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected tags? Existing page-tag links will be removed.');"
    ><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>
</nav>

<section class="card">
    <div class="card-body">
        <?php if ($tagRows === []): ?>
            <p class="text-muted mb-0">No tags yet.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label mb-1" for="<?= e($tagSearchId) ?>">Search</label>
                    <input
                        id="<?= e($tagSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by ID, title, or slug..."
                    >
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="<?= e($tagSetFilterId) ?>">Set</label>
                    <select id="<?= e($tagSetFilterId) ?>" class="form-select form-select-sm">
                        <option value="">All Sets</option>
                        <?php foreach ($setOptions as $setOption): ?>
                            <?php
                            $setId = (int) ($setOption['id'] ?? 0);
                            $setSlug = (string) ($setOption['slug'] ?? '');
                            ?>
                            <option value="<?= $setId ?>"<?= $selectedSetId === $setId ? ' selected' : '' ?>>
                                <?= e((string) ($setOption['name'] ?? 'Set')) ?> (<?= e($setSlug) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($tagCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($tagTableId) ?>"
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
                        <th scope="col" data-sort-key="set" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Set</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="pages" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Pages</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tagRows as $tag): ?>
                        <?php
                        $tagId = (int) ($tag['id'] ?? 0);
                        $tagName = (string) ($tag['name'] ?? '');
                        $tagSlug = (string) ($tag['slug'] ?? '');
                        $tagSetId = (int) ($tag['set'] ?? 0);
                        $tagSetName = (string) ($tagSetNames[$tagSetId] ?? ('Set #' . $tagSetId));
                        $tagPageCount = (int) ($tag['page_count'] ?? 0);
                        $tagPagesUrl = $panelBase . '/page?tag=' . rawurlencode((string) $tagId);
                        ?>
                        <tr
                            data-rvn-sort-row="1"
                            data-sort-id="<?= e((string) $tagId) ?>"
                            data-sort-title="<?= e($tagName) ?>"
                            data-sort-slug="<?= e($tagSlug) ?>"
                            data-sort-set="<?= e($tagSetName) ?>"
                            data-sort-pages="<?= e((string) $tagPageCount) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <?php // `data-rvn-row-select` hooks into global layout row-highlighting script. ?>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="<?= $tagId ?>"
                                    form="<?= e($bulkDeleteFormId) ?>"
                                    data-rvn-row-select="1"
                                    aria-label="Select tag <?= $tagId ?>"
                                >
                            </td>
                            <td><?= $tagId ?></td>
                            <td>
                                <?php // Name is primary affordance and links directly to edit screen. ?>
                                <a href="<?= e($panelBase) ?>/tag/edit/<?= $tagId ?>">
                                    <?= e($tagName) ?>
                                </a>
                            </td>
                            <td><?= e($tagSlug) ?></td>
                            <td><?= e($tagSetName) ?></td>
                            <td>
                                <?php if ($tagPageCount > 0 && $tagId > 0): ?>
                                    <a href="<?= e($tagPagesUrl) ?>"><?= $tagPageCount ?></a>
                                <?php else: ?>
                                    <?= $tagPageCount ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a
                                        class="btn btn-primary btn-sm"
                                        href="<?= e($panelBase) ?>/tag/edit/<?= $tagId ?>"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <form method="post" action="<?= e($panelBase) ?>/tag/delete" onsubmit="return confirm('Delete this tag? Existing page-tag links will be removed.');">
                                        <?= $csrfField ?>
                                        <?php // Single-row delete path uses explicit id hidden field. ?>
                                        <input type="hidden" name="id" value="<?= $tagId ?>">
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
            <p id="<?= e($tagEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No tags match the current filters.</p>
            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= $paginationTotalItems ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Tags pagination">
                            <a class="btn btn-outline-secondary<?= $paginationCurrent <= 1 ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent - 1)) ?>">Previous</a>
                            <a class="btn btn-outline-secondary<?= $paginationCurrent >= $paginationTotalPages ? ' disabled' : '' ?>" href="<?= e($buildPaginationUrl($paginationCurrent + 1)) ?>">Next</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/tag/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Tag</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/tag/set"><i class="bi bi-collection me-2" aria-hidden="true"></i>Manage Sets</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected tags? Existing page-tag links will be removed.');"
    ><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($tagTableId) ?>');
        var searchInput = document.getElementById('<?= e($tagSearchId) ?>');
        var setFilter = document.getElementById('<?= e($tagSetFilterId) ?>');
        var countLabel = document.getElementById('<?= e($tagCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($tagEmptyId) ?>');

        if (!(table instanceof HTMLTableElement) || !(searchInput instanceof HTMLInputElement)) {
            return;
        }

        if (setFilter instanceof HTMLSelectElement) {
            setFilter.addEventListener('change', function () {
                var url = new URL(window.location.href);
                if (String(setFilter.value || '') === '') {
                    url.searchParams.delete('set');
                } else {
                    url.searchParams.set('set', String(setFilter.value || ''));
                }
                url.searchParams.delete('page');
                window.location.href = url.toString();
            });
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
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' tags';
            }
            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        searchInput.addEventListener('input', applyFilters);
        applyFilters();
    });
</script>
