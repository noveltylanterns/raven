<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/ListWrapper.php
 * Universal panel list card renderer with search, filter, and pagination.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use function Raven\Lib\Security\e;

/**
 * Renders the standard panel list card: outer card shell, search/filter row,
 * count label, table slot, empty-match message, and pagination controls.
 *
 * Route-specific logic (column definitions, row data, filter option generation,
 * client-side filter JS) stays in the calling template. This class owns only
 * the shell structure that is identical across every core list screen.
 *
 * Typical usage in a list template:
 *
 *   ob_start();
 *   // ... emit the <table>...</table> HTML here ...
 *   $tableHtml = (string) ob_get_clean();
 *
 *   echo ListWrapper::render([
 *       'is_empty'           => $rows === [],
 *       'empty_message'      => 'No items yet.',
 *       'search_id'          => 'my-search',
 *       'search_col'         => 'col-12 col-md-8',
 *       'search_placeholder' => 'Filter by title or slug...',
 *       'filters'            => [
 *           ['id' => 'my-type', 'label' => 'Type', 'col' => 'col-12 col-md-4',
 *            'options_html' => '<option value="">All</option>...'],
 *       ],
 *       'count_id'           => 'my-count',
 *       'empty_id'           => 'my-empty',
 *       'empty_match_message'=> 'No items match the current filters.',
 *       'table_html'         => $tableHtml,
 *       'pagination'         => [
 *           'current'     => $page,
 *           'total_pages' => $totalPages,
 *           'total_items' => $totalItems,
 *           'base_path'   => $panelBase . '/items',
 *           'query'       => [],
 *           'label'       => 'items',
 *           'aria_label'  => 'Items pagination',
 *       ],
 *   ]);
 *
 * Config keys:
 *   is_empty             (bool)         True -> render empty_message only; false -> full list UI.
 *   empty_message        (string)       Text when the row collection itself is empty.
 *   search_id            (string)       HTML id for the search input element.
 *   search_col           (string)       Bootstrap col class for the search column.
 *   search_placeholder   (string)       Placeholder text for the search input.
 *   filters              (array)        Filter select definitions (see below). May be empty.
 *   count_id             (string)       HTML id for the count label div.
 *   empty_id             (string)       HTML id for the no-results paragraph.
 *   empty_match_message  (string)       Text when all rows are hidden by filters.
 *   table_html           (string)       Trusted full `<table ...>...</table>` HTML from the template.
 *   pagination           (array|null)   Pagination data (see below). Null or missing = no pagination.
 *
 * Each filter entry (in `filters`) must contain:
 *   id           (string)  HTML id for the `<select>` element.
 *   label        (string)  Text for the `<label>` above the select.
 *   col          (string)  Bootstrap col class for this filter's column.
 *   options_html (string)  Trusted HTML for the `<option>` elements including selected state.
 *
 * The `pagination` array must contain:
 *   current     (int)     Current page number (1-based).
 *   total_pages (int)     Total number of pages.
 *   total_items (int)     Total item count across all pages.
 *   base_path   (string)  URL path for pagination links (without query string).
 *   query       (array)   Additional query parameters to preserve across page links.
 *   label       (string)  Noun appended to "Page X of Y (Z total ...)".
 *   aria_label  (string)  Aria-label for the pagination btn-group.
 */
final class ListWrapper
{
    /**
     * Renders the full list card HTML from a declarative config array.
     *
     * @param array<string, mixed> $config Declarative list configuration (see class docblock).
     * @return string Trusted HTML for the list card, ready to echo into the panel template.
     */
    public static function render(array $config): string
    {
        $isEmpty = (bool) ($config['is_empty'] ?? true);
        $emptyMessage = (string) ($config['empty_message'] ?? 'No items found.');
        $tableHtml = isset($config['table_html']) ? (string) $config['table_html'] : '';
        $countId = (string) ($config['count_id'] ?? '');
        $emptyId = (string) ($config['empty_id'] ?? '');
        $emptyMatchMsg = (string) ($config['empty_match_message'] ?? 'No items match the current filters.');
        $paginationData = isset($config['pagination']) && is_array($config['pagination']) ? $config['pagination'] : null;

        ob_start();
        ?>
<section class="card">
    <div class="card-body">
        <?php if ($isEmpty): ?>
            <p class="text-muted mb-0"><?= e($emptyMessage) ?></p>
        <?php else: ?>
            <?= self::renderFilters($config) ?>
            <?php if ($countId !== ''): ?>
            <div class="small text-muted mb-2" id="<?= e($countId) ?>"></div>
            <?php endif; ?>
            <div class="table-responsive">
                <?= $tableHtml ?>
            </div>
            <?php if ($emptyId !== ''): ?>
            <p id="<?= e($emptyId) ?>" class="text-muted mb-0 mt-2 d-none"><?= e($emptyMatchMsg) ?></p>
            <?php endif; ?>
            <?php if ($paginationData !== null): ?>
                <?= self::renderPagination($paginationData) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the search input and optional filter select row.
     *
     * If no filters are configured the search input takes the full row width.
     * Each filter entry's option elements are accepted as trusted HTML so the
     * template can pre-build selected state without passing complex data arrays.
     *
     * @param array<string, mixed> $config Config array (uses search_id, search_col, search_placeholder, filters).
     * @return string Trusted HTML for the filter row, or an empty string when no controls are needed.
     */
    private static function renderFilters(array $config): string
    {
        $searchId = (string) ($config['search_id'] ?? '');
        $searchPlaceholder = (string) ($config['search_placeholder'] ?? 'Search...');
        $filters = is_array($config['filters'] ?? null) ? $config['filters'] : [];

        // Default search column width fills the row when there are no extra filter selects.
        $defaultSearchCol = $filters === [] ? 'col-12' : 'col-12 col-md-8';
        $searchCol = trim((string) ($config['search_col'] ?? $defaultSearchCol));
        if ($searchCol === '') {
            $searchCol = $defaultSearchCol;
        }

        if ($searchId === '' && $filters === []) {
            return '';
        }

        ob_start();
        ?>
            <div class="row g-2 mb-3">
                <?php if ($searchId !== ''): ?>
                <div class="<?= e($searchCol) ?>">
                    <label class="form-label mb-1" for="<?= e($searchId) ?>">Search</label>
                    <input
                        id="<?= e($searchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="<?= e($searchPlaceholder) ?>"
                    >
                </div>
                <?php endif; ?>
                <?php foreach ($filters as $filter): ?>
                    <?php
                    $filterId = (string) ($filter['id'] ?? '');
                    $filterLabel = (string) ($filter['label'] ?? '');
                    $filterCol = (string) ($filter['col'] ?? 'col-12 col-md-4');
                    $filterOptions = (string) ($filter['options_html'] ?? '');
                    if ($filterId === '') {
                        continue;
                    }
                    ?>
                    <div class="<?= e($filterCol) ?>">
                        <?php if ($filterLabel !== ''): ?>
                        <label class="form-label mb-1" for="<?= e($filterId) ?>"><?= e($filterLabel) ?></label>
                        <?php endif; ?>
                        <select id="<?= e($filterId) ?>" class="form-select form-select-sm">
                            <?= $filterOptions ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders Bootstrap pagination controls from a pagination data array.
     *
     * Skips rendering entirely when total_items is zero or total_pages is one,
     * which avoids displaying an empty or single-page pagination bar.
     *
     * @param array<string, mixed> $pagination Pagination data (see class docblock for keys).
     * @return string Trusted HTML for the pagination row, or an empty string.
     */
    private static function renderPagination(array $pagination): string
    {
        $current = max(1, (int) ($pagination['current'] ?? 1));
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $totalItems = max(0, (int) ($pagination['total_items'] ?? 0));
        $basePath = (string) ($pagination['base_path'] ?? '');
        $query = is_array($pagination['query'] ?? null) ? $pagination['query'] : [];
        $label = trim((string) ($pagination['label'] ?? 'items'));
        $ariaLabel = trim((string) ($pagination['aria_label'] ?? 'Pagination'));

        if ($totalItems === 0) {
            return '';
        }

        $prevUrl = self::buildPageUrl($basePath, $query, $current - 1);
        $nextUrl = self::buildPageUrl($basePath, $query, $current + 1);

        ob_start();
        ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $current ?> of <?= $totalPages ?> (<?= $totalItems ?> total<?= $label !== '' ? ' ' . e($label) : '' ?>)
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="<?= e($ariaLabel) ?>">
                            <a class="btn btn-outline-secondary<?= $current <= 1 ? ' disabled' : '' ?>" href="<?= e($prevUrl) ?>">Previous</a>
                            <a class="btn btn-outline-secondary<?= $current >= $totalPages ? ' disabled' : '' ?>" href="<?= e($nextUrl) ?>">Next</a>
                        </div>
                    <?php endif; ?>
                </div>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Builds a pagination URL for a given page number.
     *
     * Page 1 omits the `page` parameter entirely to keep the canonical first-page
     * URL clean and consistent with how controllers route the default view.
     *
     * @param string $basePath URL path without query string.
     * @param array<string, mixed> $query Existing query params to preserve.
     * @param int $page Target page number (clamped to minimum 1).
     * @return string Fully assembled URL string for the pagination link.
     */
    private static function buildPageUrl(string $basePath, array $query, int $page): string
    {
        $page = max(1, $page);
        if ($page > 1) {
            $query['page'] = (string) $page;
        } else {
            // Remove any stale page param so page-1 links are canonical.
            unset($query['page']);
        }

        $queryString = http_build_query($query);
        return $basePath . ($queryString !== '' ? '?' . $queryString : '');
    }
}
