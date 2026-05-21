<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/category/list.php
 * Admin panel category-listing template.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $categoryRows */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $setOptions */
/** @var int|null $selectedSetId */
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
$bulkDeleteFormId = 'bulk-category-delete-form';
$categoryTableId = 'category-table';
$categorySearchId = 'category-filter-search';
$categoryCountId = 'category-filter-count';
$categoryEmptyId = 'category-filter-empty';
$categorySetFilterId = 'category-filter-set';
$categorySetNames = [];
foreach ($setOptions as $setOption) {
    $categorySetNames[(int) ($setOption['id'] ?? 0)] = (string) ($setOption['name'] ?? 'Set');
}
$pagination = is_array($pagination ?? null) ? $pagination : [];
// Build set filter options HTML with server-side selected state.
$categorySetOptionsHtml = '<option value="">All Sets</option>';
foreach ($setOptions as $setOption) {
    $setId = (int) ($setOption['id'] ?? 0);
    $setSlug = (string) ($setOption['slug'] ?? '');
    $setName = e((string) ($setOption['name'] ?? 'Set'));
    $setSlugE = e($setSlug);
    $setSelected = $selectedSetId === $setId ? ' selected' : '';
    $categorySetOptionsHtml .= "<option value=\"{$setId}\"{$setSelected}>{$setName} ({$setSlugE})</option>";
}
$categoryListToolbarItems = [
    '<a class="btn btn-primary" href="' . e($panelBase) . '/category/edit"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Category</a>',
    '<a class="btn btn-secondary" href="' . e($panelBase) . '/category/set"><i class="bi bi-collection me-2" aria-hidden="true"></i>Manage Sets</a>',
    '<button type="submit" class="btn btn-danger" form="' . e($bulkDeleteFormId) . '" onclick="return confirm(\'Delete selected categories? Existing page-category links will be removed.\');"><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>',
];
?>
<?= Header::render([
    'title' => 'Categories',
    'summary' => 'Manage category taxonomy to organize pages and category landing views.',
]) ?>

<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/category/delete">
    <?= $csrfField ?>
</form>

<?= Toolbar::render([
    'items' => $categoryListToolbarItems,
]) ?>

<?php ob_start(); ?>
<table
    id="<?= e($categoryTableId) ?>"
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
    <?php foreach ($categoryRows as $category): ?>
        <?php
        $categoryId = (int) ($category['id'] ?? 0);
        $categoryName = (string) ($category['name'] ?? '');
        $categorySlug = (string) ($category['slug'] ?? '');
        $categorySetId = (int) ($category['set'] ?? 0);
        $categorySetName = (string) ($categorySetNames[$categorySetId] ?? ('Set #' . $categorySetId));
        $categoryPageCount = (int) ($category['page_count'] ?? 0);
        $categoryPagesUrl = $panelBase . '/page?category=' . rawurlencode((string) $categoryId);
        ?>
        <tr
            data-rvn-sort-row="1"
            data-sort-id="<?= e((string) $categoryId) ?>"
            data-sort-title="<?= e($categoryName) ?>"
            data-sort-slug="<?= e($categorySlug) ?>"
            data-sort-set="<?= e($categorySetName) ?>"
            data-sort-pages="<?= e((string) $categoryPageCount) ?>"
        >
            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
            <?php // `data-rvn-row-select` hooks into global layout row-highlighting script. ?>
            <td>
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="selected_ids[]"
                    value="<?= $categoryId ?>"
                    form="<?= e($bulkDeleteFormId) ?>"
                    data-rvn-row-select="1"
                    aria-label="Select category <?= $categoryId ?>"
                >
            </td>
            <td><?= $categoryId ?></td>
            <td>
                <?php // Name is primary affordance and links directly to edit screen. ?>
                <a href="<?= e($panelBase) ?>/category/edit/<?= $categoryId ?>">
                    <?= e($categoryName) ?>
                </a>
            </td>
            <td><?= e($categorySlug) ?></td>
            <td><?= e($categorySetName) ?></td>
            <td>
                <?php if ($categoryPageCount > 0 && $categoryId > 0): ?>
                    <a href="<?= e($categoryPagesUrl) ?>"><?= $categoryPageCount ?></a>
                <?php else: ?>
                    <?= $categoryPageCount ?>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <div class="d-flex justify-content-center gap-2">
                    <a
                        class="btn btn-primary btn-sm"
                        href="<?= e($panelBase) ?>/category/edit/<?= $categoryId ?>"
                        title="Edit"
                        aria-label="Edit"
                    >
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                        <span class="visually-hidden">Edit</span>
                    </a>
                    <form method="post" action="<?= e($panelBase) ?>/category/delete" onsubmit="return confirm('Delete this category? Existing page-category links will be removed.');">
                        <?= $csrfField ?>
                        <?php // Single-row delete path uses explicit id hidden field. ?>
                        <input type="hidden" name="id" value="<?= $categoryId ?>">
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
<?php $categoryTableHtml = (string) ob_get_clean(); ?>
<?= ListWrapper::render([
    'is_empty'            => $categoryRows === [],
    'empty_message'       => 'No categories yet.',
    'search_id'           => $categorySearchId,
    'search_col'          => 'col-12 col-md-8',
    'search_placeholder'  => 'Filter by ID, title, or slug...',
    'filters'             => [
        [
            'id'           => $categorySetFilterId,
            'label'        => 'Set',
            'col'          => 'col-12 col-md-4',
            // Set filter navigates to a new URL (server-side pagination) on change; JS in Footer::pushScript handles it.
            'options_html' => $categorySetOptionsHtml,
        ],
    ],
    'count_id'            => $categoryCountId,
    'empty_id'            => $categoryEmptyId,
    'empty_match_message' => 'No categories match the current filters.',
    'table_html'          => $categoryTableHtml,
    'pagination'          => [
        'current'     => max(1, (int) ($pagination['current'] ?? 1)),
        'total_pages' => max(1, (int) ($pagination['total_pages'] ?? 1)),
        'total_items' => max(0, (int) ($pagination['total_items'] ?? count($categoryRows))),
        'base_path'   => (string) ($pagination['base_path'] ?? ($panelBase . '/category')),
        'query'       => is_array($pagination['query'] ?? null) ? $pagination['query'] : [],
        'label'       => 'categories',
        'aria_label'  => 'Categories pagination',
    ],
]) ?>

<?= Toolbar::render([
    'items' => $categoryListToolbarItems,
]) ?>

<?php ob_start(); ?>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($categoryTableId) ?>');
        var searchInput = document.getElementById('<?= e($categorySearchId) ?>');
        var setFilter = document.getElementById('<?= e($categorySetFilterId) ?>');
        var countLabel = document.getElementById('<?= e($categoryCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($categoryEmptyId) ?>');

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
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' categories';
            }
            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        searchInput.addEventListener('input', applyFilters);
        applyFilters();
    });
<?php Footer::pushScript((string) ob_get_clean()); ?>
