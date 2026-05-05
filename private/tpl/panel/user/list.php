<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/user/list.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $users */
/** @var array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> $groupOptions */
/** @var string|null $loginIdentifierMode */
/** @var string|null $registrationMode */
/** @var string $prefilterGroup */
/** @var array<string, mixed> $pagination */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\ListWrapper;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$bulkDeleteFormId = 'bulk-users-delete-form';
$usersTableId = 'users-table';
$usersSearchId = 'users-filter-search';
$usersGroupFilterId = 'users-filter-group';
$usersCountId = 'users-filter-count';
$usersEmptyId = 'users-filter-empty';
$loginIdentifierMode = strtolower(trim((string) ($loginIdentifierMode ?? 'email')));
if (!in_array($loginIdentifierMode, ['email', 'username'], true)) {
    $loginIdentifierMode = 'email';
}
$showUsernameColumn = $loginIdentifierMode === 'username';
$registrationMode = strtolower(trim((string) ($registrationMode ?? 'closed')));
if (!in_array($registrationMode, ['open', 'invite', 'closed'], true)) {
    $registrationMode = 'closed';
}
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
// Build group filter options HTML with client-side pre-selected state.
$usersGroupOptionsHtml = '<option value=""' . ($prefilterGroup === '' ? ' selected' : '') . '>All Groups</option>';
foreach ($groupFilterOptions as $groupValue => $groupLabel) {
    $groupValueE = e((string) $groupValue);
    $groupLabelE = e((string) $groupLabel);
    $groupSelected = $prefilterGroup === (string) $groupValue ? ' selected' : '';
    $usersGroupOptionsHtml .= "<option value=\"{$groupValueE}\"{$groupSelected}>{$groupLabelE}</option>";
}
$usersSearchPlaceholder = $showUsernameColumn
    ? 'Filter by username, display name, email, or groups...'
    : 'Filter by display name, email, or groups...';
$userListToolbarItems = [
    '<a class="btn btn-primary" href="' . e($panelBase) . '/user/edit"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>New User</a>',
];
if ($registrationMode === 'invite') {
    $userListToolbarItems[] = '<a class="btn btn-secondary" href="' . e($panelBase) . '/user/invites"><i class="bi bi-ticket-perforated me-2" aria-hidden="true"></i>Invite Tokens</a>';
}
$userListToolbarItems[] = '<button type="submit" class="btn btn-danger" form="' . e($bulkDeleteFormId) . '" onclick="return confirm(\'Delete selected users? You cannot delete your currently logged-in account.\');"><i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected</button>';
?>
<?= Header::render([
    'title' => 'Users',
    'summary' => 'Manage user accounts, profile details, and group memberships.',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<!-- Standalone bulk-delete form receives selected row ids via `form` attribute. -->
<form id="<?= e($bulkDeleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/user/delete">
    <?= $csrfField ?>
</form>

<?= Toolbar::render([
    'items' => $userListToolbarItems,
]) ?>

<?php ob_start(); ?>
<table
    id="<?= e($usersTableId) ?>"
    class="table table-sm align-middle"
    data-rvn-sort-table="1"
    data-sort-default-key="<?= e($showUsernameColumn ? 'username' : 'display_name') ?>"
    data-sort-default-direction="asc"
>
    <thead>
    <tr>
        <th></th>
        <th scope="col" data-sort-key="id" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">ID</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <th scope="col" data-sort-key="display_name" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Display Name</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <?php if ($showUsernameColumn): ?>
        <th scope="col" data-sort-key="username" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Username</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
        <?php endif; ?>
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
        $displayName = (string) ($user['name'] ?? '');
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
                    'permissions' => (int) ($rawGroupEntry['permissions'] ?? 0),
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
                    'permissions' => 0,
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
            data-rvn-sort-row="1"
            data-sort-id="<?= e((string) $userId) ?>"
            data-sort-username="<?= e($username) ?>"
            data-sort-display-name="<?= e($displayName) ?>"
            data-sort-email="<?= e($email) ?>"
            data-sort-groups="<?= e($groupsText) ?>"
            data-filter-groups="<?= e($groupsFilterValue) ?>"
        >
            <?php // Row checkboxes post to dedicated bulk-delete form. ?>
            <?php // `data-rvn-row-select` hooks into global layout row-highlighting script. ?>
            <td>
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="selected_ids[]"
                    value="<?= $userId ?>"
                    form="<?= e($bulkDeleteFormId) ?>"
                    data-rvn-row-select="1"
                    aria-label="Select user <?= $userId ?>"
                >
            </td>
            <td><?= $userId ?></td>
            <td>
                <a href="<?= e($panelBase) ?>/user/edit/<?= $userId ?>">
                    <?= e($displayName) ?>
                </a>
            </td>
            <?php if ($showUsernameColumn): ?>
                <td><?= e($username) ?></td>
            <?php endif; ?>
            <td><?= e($email) ?></td>
            <td>
                <?php if ($groupEntries === []): ?>
                    <span class="text-muted">&lt;none&gt;</span>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($groupEntries as $groupEntry): ?>
                            <?php
                            $groupName = (string) ($groupEntry['name'] ?? '');
                            $groupPermissionMask = (int) ($groupEntry['permissions'] ?? 0);
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
                        href="<?= e($panelBase) ?>/user/edit/<?= $userId ?>"
                        title="Edit"
                        aria-label="Edit"
                    >
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                        <span class="visually-hidden">Edit</span>
                    </a>
                    <form method="post" action="<?= e($panelBase) ?>/user/delete" onsubmit="return confirm('Delete this user?');">
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
<?php $usersTableHtml = (string) ob_get_clean(); ?>
<?= ListWrapper::render([
    'is_empty'            => $users === [],
    'empty_message'       => 'No users found.',
    'search_id'           => $usersSearchId,
    'search_col'          => 'col-12 col-lg-8',
    'search_placeholder'  => $usersSearchPlaceholder,
    'filters'             => [
        [
            'id'           => $usersGroupFilterId,
            'label'        => 'Filter by Group',
            'col'          => 'col-12 col-lg-4',
            'options_html' => $usersGroupOptionsHtml,
        ],
    ],
    'count_id'            => $usersCountId,
    'empty_id'            => $usersEmptyId,
    'empty_match_message' => 'No users match the current filters.',
    'table_html'          => $usersTableHtml,
    'pagination'          => [
        'current'     => max(1, (int) ($pagination['current'] ?? 1)),
        'total_pages' => max(1, (int) ($pagination['total_pages'] ?? 1)),
        'total_items' => max(0, (int) ($pagination['total_items'] ?? count($users))),
        'base_path'   => (string) ($pagination['base_path'] ?? ($panelBase . '/user')),
        'query'       => is_array($pagination['query'] ?? null) ? $pagination['query'] : [],
        'label'       => 'users',
        'aria_label'  => 'Users pagination',
    ],
]) ?>

<?= Toolbar::render([
    'items' => $userListToolbarItems,
]) ?>

<?php ob_start(); ?>
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('<?= e($usersTableId) ?>');
        var searchInput = document.getElementById('<?= e($usersSearchId) ?>');
        var groupFilterSelect = document.getElementById('<?= e($usersGroupFilterId) ?>');
        var countLabel = document.getElementById('<?= e($usersCountId) ?>');
        var emptyLabel = document.getElementById('<?= e($usersEmptyId) ?>');

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
                window.history.replaceState({}, document.title, '<?= e($panelBase) ?>/user');
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
<?php Footer::pushScript((string) ob_get_clean()); ?>
