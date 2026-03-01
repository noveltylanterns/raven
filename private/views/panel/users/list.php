<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/users/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $users */
/** @var array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}> $groupOptions */
/** @var string $prefilterGroup */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use Raven\Core\Auth\PanelAccess;
use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-users-delete-form';
$usersTableId = 'users-table';
$usersSearchId = 'users-filter-search';
$usersGroupFilterId = 'users-filter-group';
$usersCountId = 'users-filter-count';
$usersEmptyId = 'users-filter-empty';
$prefilterGroup = strtolower(trim((string) ($prefilterGroup ?? '')));
$groupFilterOptions = [];
foreach ($groupOptions as $groupOption) {
    $groupName = trim((string) ($groupOption['name'] ?? ''));
    if ($groupName === '') {
        continue;
    }

    $groupFilterOptions[strtolower($groupName)] = $groupName;
}
asort($groupFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationCurrent = max(1, (int) ($pagination['current'] ?? 1));
$paginationTotalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$paginationTotalItems = max(0, (int) ($pagination['total_items'] ?? count($users)));
$paginationBasePath = (string) ($pagination['base_path'] ?? ($panelBase . '/users'));
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
        <h1>Users</h1>
        <p class="text-muted mb-0">Manage user accounts, profile details, and group memberships.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/users/delete">
    <?= $csrfField ?>
</form>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/users/edit"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>New User</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected users? You cannot delete your currently logged-in account.');"
    ><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>
</nav>

<section class="card">
    <div class="card-body">
        <?php if ($users === []): ?>
            <p class="text-muted mb-0">No users found.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-lg-8">
                    <label class="form-label h6 mb-1" for="<?= e($usersSearchId) ?>">Search</label>
                    <input
                        id="<?= e($usersSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by username, display name, email, or groups..."
                    >
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label h6 mb-1" for="<?= e($usersGroupFilterId) ?>">Filter by Group</label>
                    <select id="<?= e($usersGroupFilterId) ?>" class="form-select form-select-sm">
                        <option value=""<?= $prefilterGroup === '' ? ' selected' : '' ?>>All Groups</option>
                        <?php foreach ($groupFilterOptions as $groupValue => $groupLabel): ?>
                            <option value="<?= e((string) $groupValue) ?>"<?= $prefilterGroup === (string) $groupValue ? ' selected' : '' ?>><?= e((string) $groupLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($usersCountId) ?>"></div>
            <div class="table-responsive">
                <table
                    id="<?= e($usersTableId) ?>"
                    class="table table-sm align-middle"
                    data-raven-sort-table="1"
                    data-sort-default-key="username"
                    data-sort-default-direction="asc"
                >
                    <thead>
                    <tr>
                        <th></th>
                        <th scope="col" data-sort-key="id" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">ID</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="username" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Username</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="display_name" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Display Name</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="email" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Email</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="groups" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Groups</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $userId = (int) ($user['id'] ?? 0);
                        $username = (string) ($user['username'] ?? '');
                        $displayName = (string) ($user['display_name'] ?? '');
                        $email = (string) ($user['email'] ?? '');
                        $groupsText = (string) ($user['groups_text'] ?? '');
                        /** @var mixed $rawGroupEntries */
                        $rawGroupEntries = $user['group_entries'] ?? [];
                        $groupEntries = [];
                        if (is_array($rawGroupEntries)) {
                            foreach ($rawGroupEntries as $rawGroupEntry) {
                                if (!is_array($rawGroupEntry)) {
                                    continue;
                                }

                                $groupName = trim((string) ($rawGroupEntry['name'] ?? ''));
                                if ($groupName === '') {
                                    continue;
                                }

                                $groupEntries[] = [
                                    'name' => $groupName,
                                    'permission_mask' => (int) ($rawGroupEntry['permission_mask'] ?? 0),
                                ];
                            }
                        }

                        if ($groupEntries === [] && $groupsText !== '') {
                            foreach (explode(',', $groupsText) as $groupNamePart) {
                                $groupName = trim((string) $groupNamePart);
                                if ($groupName === '') {
                                    continue;
                                }

                                $groupEntries[] = [
                                    'name' => $groupName,
                                    'permission_mask' => 0,
                                ];
                            }
                        }

                        $groupTokens = [];
                        foreach ($groupEntries as $groupEntry) {
                            $groupToken = strtolower(trim((string) ($groupEntry['name'] ?? '')));
                            if ($groupToken !== '') {
                                $groupTokens[$groupToken] = true;
                            }
                        }
                        $groupsFilterValue = '|' . implode('|', array_keys($groupTokens)) . '|';
                        ?>
                        <tr
                            data-raven-sort-row="1"
                            data-sort-id="<?= e((string) $userId) ?>"
                            data-sort-username="<?= e($username) ?>"
                            data-sort-display-name="<?= e($displayName) ?>"
                            data-sort-email="<?= e($email) ?>"
                            data-sort-groups="<?= e($groupsText) ?>"
                            data-filter-groups="<?= e($groupsFilterValue) ?>"
                        >
                            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
                            <?php // `data-raven-row-select` hooks into global layout row-highlighting script. ?>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="<?= $userId ?>"
                                    form="<?= e($bulkDeleteFormId) ?>"
                                    data-raven-row-select="1"
                                    aria-label="Select user <?= $userId ?>"
                                >
                            </td>
                            <td><?= $userId ?></td>
                            <td>
                                <a href="<?= e($panelBase) ?>/users/edit/<?= $userId ?>">
                                    <?= e($username) ?>
                                </a>
                            </td>
                            <td>
                                <?= e($displayName) ?>
                            </td>
                            <td><?= e($email) ?></td>
                            <td>
                                <?php if ($groupEntries === []): ?>
                                    <span class="text-muted">&lt;none&gt;</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($groupEntries as $groupEntry): ?>
                                            <?php
                                            $groupName = (string) ($groupEntry['name'] ?? '');
                                            $groupPermissionMask = (int) ($groupEntry['permission_mask'] ?? 0);
                                            $groupBadgeClass = 'text-bg-success';
                                            if (PanelAccess::canManageConfiguration($groupPermissionMask)) {
                                                $groupBadgeClass = 'text-bg-danger';
                                            } elseif (PanelAccess::canLoginPanel($groupPermissionMask)) {
                                                $groupBadgeClass = 'text-bg-warning';
                                            }
                                            ?>
                                            <span class="badge <?= e($groupBadgeClass) ?>"><?= e($groupName) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a
                                        class="btn btn-primary btn-sm"
                                        href="<?= e($panelBase) ?>/users/edit/<?= $userId ?>"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <form method="post" action="<?= e($panelBase) ?>/users/delete" onsubmit="return confirm('Delete this user?');">
                                        <?= $csrfField ?>
                                        <?php // Single-row delete path uses explicit id hidden field. ?>
                                        <input type="hidden" name="id" value="<?= $userId ?>">
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
            <p id="<?= e($usersEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No users match the current filters.</p>
            <?php if ($paginationTotalItems > 0): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div class="small text-muted">
                        Page <?= $paginationCurrent ?> of <?= $paginationTotalPages ?> (<?= $paginationTotalItems ?> total)
                    </div>
                    <?php if ($paginationTotalPages > 1): ?>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Users pagination">
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
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/users/edit"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>New User</a>
    <button
        type="submit"
        class="btn btn-danger"
        form="<?= e($bulkDeleteFormId) ?>"
        onclick="return confirm('Delete selected users? You cannot delete your currently logged-in account.');"
    ><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($usersTableId) ?>');
        var searchInput = document.getElementById('<?= e($usersSearchId) ?>');
        var groupFilterSelect = document.getElementById('<?= e($usersGroupFilterId) ?>');
        var countLabel = document.getElementById('<?= e($usersCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($usersEmptyId) ?>');

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

        var hasPrefilterGroup = <?= $prefilterGroup !== '' ? 'true' : 'false' ?>;

        function clearPrefilter(source) {
            if (!hasPrefilterGroup) {
                return;
            }

            if (source !== 'group' && groupFilterSelect instanceof HTMLSelectElement) {
                groupFilterSelect.value = '';
            }

            hasPrefilterGroup = false;

            if (typeof window.history.replaceState === 'function') {
                window.history.replaceState({}, document.title, '<?= e($panelBase) ?>/users');
            }
        }

        function applyFilters() {
            var query = normalize(searchInput.value).trim();
            var groupFilter = groupFilterSelect instanceof HTMLSelectElement
                ? normalize(groupFilterSelect.value).trim()
                : '';
            var visibleCount = 0;

            rows.forEach(function (row) {
                var matchesSearch = true;
                if (query !== '') {
                    var searchableText = [
                        row.getAttribute('data-sort-id'),
                        row.getAttribute('data-sort-username'),
                        row.getAttribute('data-sort-display-name'),
                        row.getAttribute('data-sort-email'),
                        row.getAttribute('data-sort-groups')
                    ].map(function (value) {
                        return normalize(value);
                    }).join(' ');
                    matchesSearch = searchableText.indexOf(query) !== -1;
                }

                var matchesGroup = true;
                if (groupFilter !== '') {
                    var rowGroups = normalize(row.getAttribute('data-filter-groups'));
                    matchesGroup = rowGroups.indexOf('|' + groupFilter + '|') !== -1;
                }

                var visible = matchesSearch && matchesGroup;
                row.classList.toggle('d-none', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(rows.length) + ' users';
            }
            if (emptyLabel instanceof HTMLElement) {
                emptyLabel.classList.toggle('d-none', visibleCount !== 0);
            }
        }

        searchInput.addEventListener('input', function () {
            clearPrefilter('search');
            applyFilters();
        });
        if (groupFilterSelect instanceof HTMLSelectElement) {
            groupFilterSelect.addEventListener('change', function () {
                clearPrefilter('group');
                applyFilters();
            });
        }

        applyFilters();
    });
</script>
