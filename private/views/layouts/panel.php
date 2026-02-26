<?php

/**
 * RAVEN CMS
 * ~/private/views/layouts/panel.php
 * Shared layout template for rendered views.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Layout wraps child content and shared navigation/theme chrome.

/** @var array<string, string> $site */
/** @var string $content */
/** @var string|null $section */
/** @var string|null $csrfField */
/** @var bool|null $showSidebar */
/** @var bool|null $canManageContent */
/** @var bool|null $canManageTaxonomy */
/** @var bool|null $canManageUsers */
/** @var bool|null $canManageGroups */
/** @var bool|null $canManageConfiguration */
/** @var string|null $userTheme */
/** @var string|null $pagesNav */
/** @var string|null $pageTitle */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$section ??= null;
$showSidebar = (bool) ($showSidebar ?? false);
$sessionCanManageContent = $_SESSION['_raven_can_manage_content'] ?? null;
$sessionCanManageTaxonomy = $_SESSION['_raven_can_manage_taxonomy'] ?? null;
$sessionCanManageUsers = $_SESSION['_raven_can_manage_users'] ?? null;
$sessionCanManageGroups = $_SESSION['_raven_can_manage_groups'] ?? null;
$sessionCanManageConfiguration = $_SESSION['_raven_can_manage_configuration'] ?? null;
if ($canManageContent === null && is_bool($sessionCanManageContent)) {
    $canManageContent = $sessionCanManageContent;
}
if ($canManageTaxonomy === null && is_bool($sessionCanManageTaxonomy)) {
    $canManageTaxonomy = $sessionCanManageTaxonomy;
}
if ($canManageUsers === null && is_bool($sessionCanManageUsers)) {
    $canManageUsers = $sessionCanManageUsers;
}
if ($canManageGroups === null && is_bool($sessionCanManageGroups)) {
    $canManageGroups = $sessionCanManageGroups;
}
if ($canManageConfiguration === null && is_bool($sessionCanManageConfiguration)) {
    $canManageConfiguration = $sessionCanManageConfiguration;
}
$canManageContent = (bool) ($canManageContent ?? false);
$canManageTaxonomy = (bool) ($canManageTaxonomy ?? false);
$canManageUsers = (bool) ($canManageUsers ?? false);
$canManageGroups = (bool) ($canManageGroups ?? false);
$canManageConfiguration = (bool) ($canManageConfiguration ?? false);
$userTheme = strtolower((string) ($userTheme ?? 'default'));
$pagesNav = is_string($pagesNav ?? null) ? $pagesNav : null;
if (!in_array($userTheme, ['default', 'light', 'dark'], true)) {
    // Guard against unexpected persisted values to keep class names predictable.
    $userTheme = 'default';
}

// Shared Welcome heading uses session-cached identity set by panel auth flow.
/** @var mixed $rawPanelIdentity */
$rawPanelIdentity = $_SESSION['raven_panel_identity'] ?? null;
$welcomeDisplayName = '';
$welcomeUsername = '';
if (is_array($rawPanelIdentity)) {
    $welcomeDisplayName = trim((string) ($rawPanelIdentity['display_name'] ?? ''));
    $welcomeUsername = trim((string) ($rawPanelIdentity['username'] ?? ''));
}
$welcomeName = $welcomeDisplayName !== '' ? $welcomeDisplayName : $welcomeUsername;
if ($welcomeName === '') {
    // Safety fallback for any edge case where session identity is unavailable.
    $welcomeName = 'User';
}

// Extension-provided nav visibility is derived from the enabled extension list.
$rawExtensionNavItems = $_SESSION['_raven_nav_extensions'] ?? [];
$rawSystemExtensionNavItems = $_SESSION['_raven_nav_system_extensions'] ?? [];
$extensionNavItems = [];
$systemExtensionNavItems = [];
if (is_array($rawExtensionNavItems)) {
    foreach ($rawExtensionNavItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        $path = trim((string) ($item['path'] ?? ''));
        $itemSection = trim((string) ($item['section'] ?? ''));
        if ($label === '' || $path === '' || $itemSection === '') {
            continue;
        }

        $extensionNavItems[] = [
            'label' => $label,
            'path' => $path,
            'section' => $itemSection,
        ];
    }
}
if (is_array($rawSystemExtensionNavItems)) {
    foreach ($rawSystemExtensionNavItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        $path = trim((string) ($item['path'] ?? ''));
        $itemSection = trim((string) ($item['section'] ?? ''));
        if ($label === '' || $path === '' || $itemSection === '') {
            continue;
        }

        $systemExtensionNavItems[] = [
            'label' => $label,
            'path' => $path,
            'section' => $itemSection,
        ];
    }
}
$showExtensionsCategory = $extensionNavItems !== [];
$systemNavItems = [
    ['label' => 'Configuration', 'path' => $panelBase . '/configuration', 'section' => 'configuration'],
    ['label' => 'Extension Manager', 'path' => $panelBase . '/extensions', 'section' => 'extensions'],
    ['label' => 'Updates', 'path' => $panelBase . '/updates', 'section' => 'updates'],
];
foreach ($systemExtensionNavItems as $item) {
    $systemNavItems[] = $item;
}
$seenSystemPaths = [];
$systemNavItems = array_values(array_filter($systemNavItems, static function (array $item) use (&$seenSystemPaths): bool {
    $path = strtolower(trim((string) ($item['path'] ?? '')));
    if ($path === '' || isset($seenSystemPaths[$path])) {
        return false;
    }

    $seenSystemPaths[$path] = true;
    return true;
}));
usort($systemNavItems, static function (array $left, array $right): int {
    return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

$siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
if ($siteName === '') {
    $siteName = 'Raven CMS';
}
$panelBrandNameInput = trim((string) ($site['panel_brand_name'] ?? ''));
$showPoweredByRaven = $panelBrandNameInput !== '';
$panelBrandName = $panelBrandNameInput !== '' ? $panelBrandNameInput : 'Raven CMS';
$panelBrandLogoRaw = trim((string) ($site['panel_brand_logo'] ?? ''));
$panelBrandLogoUrl = $panelBase . '/theme/img/logo-white_sm.png';
if ($panelBrandLogoRaw !== '') {
    if (preg_match('/^https?:\/\//i', $panelBrandLogoRaw) === 1) {
        $panelBrandLogoUrl = $panelBrandLogoRaw;
    } else {
        $panelBrandLogoUrl = '/' . ltrim($panelBrandLogoRaw, '/');
    }
}
$projectRoot = dirname(__DIR__, 3);
$panelThemeCustomCssPath = $projectRoot . '/panel/theme/css/custom.css';
$hasPanelThemeCustomCss = is_file($panelThemeCustomCssPath);

$baseDocumentTitle = $siteName . ' on Raven CMS';
$resolvedPageTitle = trim((string) ($pageTitle ?? ''));
if ($section !== 'dashboard' && $resolvedPageTitle === '') {
    // Keep page titles consistent by deriving the visible heading when explicit title data is absent.
    if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $content, $matches) === 1) {
        $headingText = html_entity_decode(strip_tags((string) ($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $resolvedPageTitle = trim((string) preg_replace('/\s+/', ' ', $headingText));
    }
}

$documentTitle = $baseDocumentTitle;
if ($section === 'login') {
    // Login page title is intentionally minimal and not site-branded.
    $documentTitle = 'Login';
} elseif ($section !== 'dashboard' && $resolvedPageTitle !== '') {
    $documentTitle = $resolvedPageTitle . ' [' . $baseDocumentTitle . ']';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($documentTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= e($panelBase) ?>/theme/img/favicon.png">
    <link rel="stylesheet" href="<?= e($panelBase) ?>/theme/css/style.css">
    <link rel="stylesheet" href="<?= e($panelBase) ?>/theme/css/bootstrap-icons.min.css">
    <?php if ($hasPanelThemeCustomCss): ?>
        <link rel="stylesheet" href="<?= e($panelBase) ?>/theme/css/custom.css">
    <?php endif; ?>
    <style>
        body.raven-panel-theme table[data-raven-sort-table="1"] thead th[data-sort-key] {
            color: var(--raven-muted);
            cursor: pointer;
            user-select: none;
            transition: color 140ms ease;
        }

        body.raven-panel-theme table[data-raven-sort-table="1"] thead th[data-sort-key].is-active-sort {
            color: var(--bs-emphasis-color);
            font-weight: 700;
        }

        body.raven-panel-theme table[data-raven-sort-table="1"] thead th[data-sort-key].is-active-sort .raven-routing-sort-caret {
            opacity: 1;
        }

        body.raven-panel-theme .raven-panel-brand-text-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.05;
            gap: 0.14rem;
        }

        body.raven-panel-theme .raven-panel-brand-powered {
            display: block;
            font-size: 0.58rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            opacity: 0.82;
            line-height: 1.1;
        }

        body.raven-panel-theme .raven-panel-footer-placeholder {
            height: 50px;
            background: transparent;
        }
    </style>
</head>
<body class="raven-panel-theme theme-<?= e($userTheme) ?><?= $showSidebar ? ' has-sidebar' : '' ?>">
<?php if ($showSidebar): ?>
    <!-- Mobile-only header navigation (xs/sm); sidebar appears from md upward. -->
    <!-- Navigation groups intentionally mirror desktop sidebar so IA remains consistent across breakpoints. -->
    <nav class="navbar navbar-expand-md navbar-dark bg-dark d-md-none">
        <div class="container-fluid">
            <a class="navbar-brand raven-panel-brand-link" href="<?= e($panelBase) ?>/">
                <span class="raven-panel-brand-lockup">
                    <img
                        class="raven-panel-brand-logo"
                        src="<?= e($panelBrandLogoUrl) ?>"
                        alt=""
                        aria-hidden="true"
                        decoding="async"
                    >
                    <span class="raven-panel-brand-text-wrap">
                        <span class="raven-panel-brand-text"><?= e($panelBrandName) ?></span>
                        <?php if ($showPoweredByRaven): ?>
                            <small class="raven-panel-brand-powered">Powered by Raven</small>
                        <?php endif; ?>
                    </span>
                </span>
            </a>
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#ravenMobilePanelNav"
                aria-controls="ravenMobilePanelNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="ravenMobilePanelNav">
                <div class="w-100 py-2">
                    <h2 class="h6 text-uppercase text-white-50">Welcome back, <?= e($welcomeName) ?>!</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <li class="nav-item">
                            <a class="nav-link<?= $section === 'dashboard' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/">Dashboard</a>
                        </li>
                        <li class="nav-item"><a class="nav-link<?= $section === 'preferences' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/preferences">Preferences</a></li>
                        <li class="nav-item">
                            <!-- Logout remains POST-only with CSRF to avoid accidental/logged URL-triggered sign-outs. -->
                            <form method="post" action="<?= e($panelBase) ?>/logout" class="m-0">
                                <?php if ($csrfField !== null): ?>
                                    <?= $csrfField ?>
                                <?php endif; ?>
                                <button type="submit" class="nav-link text-start w-100">Logout</button>
                            </form>
                        </li>
                    </ul>

                    <?php if ($canManageContent): ?>
                        <h2 class="h6 text-uppercase text-white-50">Content</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <li class="nav-item"><a class="nav-link<?= ($section === 'pages' && $pagesNav === 'create') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/pages/edit">Create Page</a></li>
                            <li class="nav-item"><a class="nav-link<?= ($section === 'pages' && $pagesNav === 'list') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/pages">List Pages</a></li>
                        </ul>
                    <?php endif; ?>

                    <?php if ($canManageTaxonomy): ?>
                        <h2 class="h6 text-uppercase text-white-50">Taxonomy</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <li class="nav-item"><a class="nav-link<?= $section === 'categories' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/categories">Categories</a></li>
                            <li class="nav-item"><a class="nav-link<?= $section === 'channels' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/channels">Channels</a></li>
                            <li class="nav-item"><a class="nav-link<?= $section === 'redirects' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/redirects">Redirects</a></li>
                            <li class="nav-item"><a class="nav-link<?= $section === 'routing' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/routing">Routing Table</a></li>
                            <li class="nav-item"><a class="nav-link<?= $section === 'tags' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/tags">Tags</a></li>
                        </ul>
                    <?php endif; ?>

                    <?php if ($canManageUsers || $canManageGroups): ?>
                        <h2 class="h6 text-uppercase text-white-50">Users &amp; Permissions</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php if ($canManageGroups): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'groups' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/groups">Groups</a></li>
                            <?php endif; ?>
                            <?php if ($canManageUsers): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'users' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/users">User Manager</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($showExtensionsCategory): ?>
                        <h2 class="h6 text-uppercase text-white-50">Extensions</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php foreach ($extensionNavItems as $extensionItem): ?>
                                <li class="nav-item">
                                    <a class="nav-link<?= $section === (string) $extensionItem['section'] ? ' active' : '' ?>" href="<?= e((string) $extensionItem['path']) ?>">
                                        <?= e((string) $extensionItem['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($canManageConfiguration): ?>
                        <h2 class="h6 text-uppercase text-white-50">System</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php foreach ($systemNavItems as $systemItem): ?>
                                <li class="nav-item">
                                    <a class="nav-link<?= $section === (string) $systemItem['section'] ? ' active' : '' ?>" href="<?= e((string) $systemItem['path']) ?>">
                                        <?= e((string) $systemItem['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

<div class="container-fluid py-3 raven-panel-layout">
    <div class="row g-3 raven-panel-layout-row">
        <?php if ($showSidebar): ?>
            <aside class="d-none d-md-block col-md-3 col-lg-3 col-xl-2 raven-panel-sidebar-column">
                <div class="card raven-panel-sidebar-card">
                    <div class="card-body">
                        <!-- Sidebar brand link replaces the removed top navbar brand. -->
                        <div class="mb-3 pb-2 border-bottom raven-panel-sidebar-brand">
                            <a class="text-decoration-none fw-semibold fs-5 raven-panel-sidebar-brand-link raven-panel-brand-link" href="<?= e($panelBase) ?>/">
                                <span class="raven-panel-brand-lockup">
                                    <img
                                        class="raven-panel-brand-logo"
                                        src="<?= e($panelBrandLogoUrl) ?>"
                                        alt=""
                                        aria-hidden="true"
                                        decoding="async"
                                    >
                                    <span class="raven-panel-brand-text-wrap">
                                        <span class="raven-panel-brand-text"><?= e($panelBrandName) ?></span>
                                        <?php if ($showPoweredByRaven): ?>
                                            <small class="raven-panel-brand-powered">Powered by Raven</small>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </a>
                        </div>

                        <!-- Welcome group contains the dashboard landing link. -->
                        <h2 class="h6 text-uppercase text-muted">Welcome back, <?= e($welcomeName) ?>!</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <li class="nav-item">
                                <a class="nav-link<?= $section === 'dashboard' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/">Dashboard</a>
                            </li>
                            <li class="nav-item"><a class="nav-link<?= $section === 'preferences' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/preferences">Preferences</a></li>
                            <li class="nav-item">
                                <!-- Use POST + CSRF for logout here as well to match mobile behavior. -->
                                <form method="post" action="<?= e($panelBase) ?>/logout" class="m-0">
                                    <?php if ($csrfField !== null): ?>
                                        <?= $csrfField ?>
                                    <?php endif; ?>
                                    <button type="submit" class="nav-link text-start w-100">Logout</button>
                                </form>
                            </li>
                        </ul>

                        <?php if ($canManageContent): ?>
                            <!-- Content group for publishing entities. -->
                            <h2 class="h6 text-uppercase text-muted">Content</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <li class="nav-item"><a class="nav-link<?= ($section === 'pages' && $pagesNav === 'create') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/pages/edit">Create Page</a></li>
                                <li class="nav-item"><a class="nav-link<?= ($section === 'pages' && $pagesNav === 'list') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/pages">List Pages</a></li>
                            </ul>
                        <?php endif; ?>

                        <?php if ($canManageTaxonomy): ?>
                            <!-- Taxonomy group for content classification entities. -->
                            <h2 class="h6 text-uppercase text-muted">Taxonomy</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <li class="nav-item"><a class="nav-link<?= $section === 'categories' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/categories">Categories</a></li>
                                <li class="nav-item"><a class="nav-link<?= $section === 'channels' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/channels">Channels</a></li>
                                <li class="nav-item"><a class="nav-link<?= $section === 'redirects' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/redirects">Redirects</a></li>
                                <li class="nav-item"><a class="nav-link<?= $section === 'routing' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/routing">Routing Table</a></li>
                                <li class="nav-item"><a class="nav-link<?= $section === 'tags' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/tags">Tags</a></li>
                            </ul>
                        <?php endif; ?>

                        <?php if ($canManageUsers || $canManageGroups): ?>
                            <!-- Users & Permissions group for account/group access controls. -->
                            <h2 class="h6 text-uppercase text-muted">Users &amp; Permissions</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php if ($canManageGroups): ?>
                                    <li class="nav-item"><a class="nav-link<?= $section === 'groups' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/groups">Groups</a></li>
                                <?php endif; ?>
                                <?php if ($canManageUsers): ?>
                                    <li class="nav-item"><a class="nav-link<?= $section === 'users' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/users">User Manager</a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($showExtensionsCategory): ?>
                            <h2 class="h6 text-uppercase text-muted">Extensions</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php foreach ($extensionNavItems as $extensionItem): ?>
                                    <li class="nav-item">
                                        <a class="nav-link<?= $section === (string) $extensionItem['section'] ? ' active' : '' ?>" href="<?= e((string) $extensionItem['path']) ?>">
                                            <?= e((string) $extensionItem['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($canManageConfiguration): ?>
                            <!-- System group for app-level settings and account administration. -->
                            <h2 class="h6 text-uppercase text-muted">System</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php foreach ($systemNavItems as $systemItem): ?>
                                    <li class="nav-item">
                                        <a class="nav-link<?= $section === (string) $systemItem['section'] ? ' active' : '' ?>" href="<?= e((string) $systemItem['path']) ?>">
                                            <?= e((string) $systemItem['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                    </div>
                </div>
            </aside>
        <?php endif; ?>

        <main class="<?= $showSidebar ? 'col-12 col-md-9 col-lg-9 col-xl-10 raven-panel-main' : 'col-12 raven-panel-main raven-panel-login-main' ?>">
            <?= $content ?>
            <footer class="raven-panel-footer-placeholder" aria-hidden="true"></footer>
        </main>
    </div>
</div>
<script src="/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        function syncRowHighlight(checkbox) {
            var row = checkbox.closest('tr');
            if (!row) {
                return;
            }

            row.classList.toggle('raven-row-selected', checkbox.checked);
        }

        document.querySelectorAll('input[type="checkbox"][data-raven-row-select="1"]').forEach(function (checkbox) {
            syncRowHighlight(checkbox);
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            if (target.type !== 'checkbox' || target.getAttribute('data-raven-row-select') !== '1') {
                return;
            }

            syncRowHighlight(target);
        });
    })();
</script>
<script>
    (function () {
        function keyToSortAttr(key) {
            return 'data-sort-' + String(key || '').trim().toLowerCase().replace(/_/g, '-');
        }

        function compareNatural(left, right) {
            return String(left || '').localeCompare(String(right || ''), undefined, {
                numeric: true,
                sensitivity: 'base'
            });
        }

        function initSortableTable(table) {
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

            var sortHeaders = Array.prototype.slice.call(table.querySelectorAll('thead th[data-sort-key]'));
            if (sortHeaders.length === 0) {
                return;
            }

            var defaultKey = String(table.getAttribute('data-sort-default-key') || '').trim().toLowerCase();
            if (defaultKey === '') {
                defaultKey = String(sortHeaders[0].getAttribute('data-sort-key') || '').trim().toLowerCase();
            }

            var defaultDirection = String(table.getAttribute('data-sort-default-direction') || 'asc').trim().toLowerCase();
            if (defaultDirection !== 'asc' && defaultDirection !== 'desc') {
                defaultDirection = 'asc';
            }

            var sortState = {
                key: defaultKey,
                direction: defaultDirection
            };

            function sortValue(row, key) {
                var attrName = keyToSortAttr(key);
                return String(row.getAttribute(attrName) || '');
            }

            function updateSortHeaderState() {
                sortHeaders.forEach(function (header) {
                    if (!(header instanceof HTMLTableCellElement)) {
                        return;
                    }

                    var key = String(header.getAttribute('data-sort-key') || '').trim().toLowerCase();
                    var caretIcon = header.querySelector('.raven-routing-sort-caret');
                    if (key === '' || key !== sortState.key) {
                        header.setAttribute('aria-sort', 'none');
                        header.classList.remove('is-active-sort');
                        if (caretIcon instanceof HTMLElement) {
                            caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                        }
                        return;
                    }

                    header.setAttribute('aria-sort', sortState.direction === 'desc' ? 'descending' : 'ascending');
                    header.classList.add('is-active-sort');
                    if (caretIcon instanceof HTMLElement) {
                        caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                        caretIcon.classList.add(sortState.direction === 'desc' ? 'bi-caret-down-fill' : 'bi-caret-up-fill');
                    }
                });
            }

            function sortRowsBy(key, forcedDirection) {
                var normalizedKey = String(key || '').trim().toLowerCase();
                if (normalizedKey === '') {
                    return;
                }

                var direction = 'asc';
                if (forcedDirection === 'asc' || forcedDirection === 'desc') {
                    direction = forcedDirection;
                } else if (sortState.key === normalizedKey) {
                    direction = sortState.direction === 'asc' ? 'desc' : 'asc';
                }

                sortState = {
                    key: normalizedKey,
                    direction: direction
                };

                rows.sort(function (leftRow, rightRow) {
                    var primaryResult = compareNatural(
                        sortValue(leftRow, normalizedKey),
                        sortValue(rightRow, normalizedKey)
                    );

                    if (primaryResult !== 0) {
                        return direction === 'desc' ? -primaryResult : primaryResult;
                    }

                    var tieBreakTitle = compareNatural(
                        sortValue(leftRow, 'title'),
                        sortValue(rightRow, 'title')
                    );
                    if (tieBreakTitle !== 0) {
                        return direction === 'desc' ? -tieBreakTitle : tieBreakTitle;
                    }

                    var tieBreakId = compareNatural(
                        sortValue(leftRow, 'id'),
                        sortValue(rightRow, 'id')
                    );
                    return direction === 'desc' ? -tieBreakId : tieBreakId;
                });

                rows.forEach(function (row) {
                    tableBody.appendChild(row);
                });

                updateSortHeaderState();
            }

            sortHeaders.forEach(function (header) {
                if (!(header instanceof HTMLTableCellElement)) {
                    return;
                }

                var key = String(header.getAttribute('data-sort-key') || '').trim().toLowerCase();
                if (key === '') {
                    return;
                }

                header.addEventListener('click', function () {
                    sortRowsBy(key);
                });

                header.addEventListener('keydown', function (event) {
                    if (!(event instanceof KeyboardEvent)) {
                        return;
                    }

                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    event.preventDefault();
                    sortRowsBy(key);
                });
            });

            sortRowsBy(defaultKey, defaultDirection);
        }

        document.querySelectorAll('table[data-raven-sort-table="1"]').forEach(function (table) {
            initSortableTable(table);
        });
    })();
</script>
</body>
</html>
