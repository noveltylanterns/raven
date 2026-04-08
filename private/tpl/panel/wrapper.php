<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/wrapper.php
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
/** @var string|null $pageNav */
/** @var string|null $pageNavChannel */
/** @var string|null $pageTitle */

use function Raven\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$section ??= null;
$showSidebar = (bool) ($showSidebar ?? false);
$canManageContent = $canManageContent ?? null;
$canManageTaxonomy = $canManageTaxonomy ?? null;
$canManageUsers = $canManageUsers ?? null;
$canManageGroups = $canManageGroups ?? null;
$canManageConfiguration = $canManageConfiguration ?? null;
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
$sessionCategoryEnabled = $_SESSION['_raven_category_enabled'] ?? null;
$sessionTagEnabled = $_SESSION['_raven_tag_enabled'] ?? null;
$categoryEnabled = filter_var(
    array_key_exists('category_enabled', $site) ? $site['category_enabled'] : $sessionCategoryEnabled,
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
);
$tagEnabled = filter_var(
    array_key_exists('tag_enabled', $site) ? $site['tag_enabled'] : $sessionTagEnabled,
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
);
if (!is_bool($categoryEnabled)) {
    $categoryEnabled = true;
}
if (!is_bool($tagEnabled)) {
    $tagEnabled = true;
}
$userTheme = strtolower((string) ($userTheme ?? 'default'));
$pageNav = is_string($pageNav ?? null) ? $pageNav : null;
$pageNavChannel = strtolower(trim((string) ($pageNavChannel ?? '')));
if ($pageNavChannel !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $pageNavChannel) !== 1) {
    $pageNavChannel = '';
}
if (!in_array($userTheme, ['default', 'corp', 'ice', 'midnight'], true)) {
    // Guard against unexpected persisted values to keep class names predictable.
    $userTheme = 'default';
}
$panelThemeClass = match ($userTheme) {
    'corp' => 'default',
    'ice' => 'light',
    'midnight' => 'dark',
    default => $userTheme,
};

// Shared Welcome heading uses session-cached identity set by panel auth flow.
/** @var mixed $rawPanelIdentity */
$rawPanelIdentity = $_SESSION['rvn-panel-identity'] ?? null;
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
$rawModuleNavItems = $_SESSION['_raven_nav_modules'] ?? [];
$rawSystemExtensionNavItems = $_SESSION['_raven_nav_system_extensions'] ?? [];
$rawPageCreateChannelItems = $_SESSION['_raven_nav_page_create_channels'] ?? [];
$rawStockNav = $_SESSION['_raven_nav_stock'] ?? [];
$extensionNavItems = [];
$moduleNavItems = [];
$systemExtensionNavItems = [];
$pageCreateChannelItems = [];
$stockNav = is_array($rawStockNav) ? $rawStockNav : [];
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
if (is_array($rawModuleNavItems)) {
    foreach ($rawModuleNavItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        $path = trim((string) ($item['path'] ?? ''));
        $itemSection = trim((string) ($item['section'] ?? ''));
        if ($label === '' || $path === '' || $itemSection === '') {
            continue;
        }

        $moduleNavItems[] = [
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
if (is_array($rawPageCreateChannelItems)) {
    foreach ($rawPageCreateChannelItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        $slug = strtolower(trim((string) ($item['slug'] ?? '')));
        if ($label === '' || $slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $slug) !== 1) {
            continue;
        }

        $pageCreateChannelItems[] = [
            'label' => $label,
            'slug' => $slug,
        ];
    }
}
$showExtensionsCategory = $extensionNavItems !== [];
$showModulesCategory = $moduleNavItems !== [];
$showCreatePageLink = !empty($stockNav['content']['create_page']);
$showListPagesLink = !empty($stockNav['content']['list_pages']);
$showGroupsLink = !empty($stockNav['accounts']['groups']);
$showUsersLink = !empty($stockNav['accounts']['users']);
$showCategoriesLink = $categoryEnabled && !empty($stockNav['taxonomy']['categories']);
$showChannelsLink = !empty($stockNav['taxonomy']['channels']);
$showRedirectsLink = !empty($stockNav['taxonomy']['redirects']);
$showRoutingLink = !empty($stockNav['taxonomy']['routing']);
$showTagsLink = $tagEnabled && !empty($stockNav['taxonomy']['tags']);
$showConfigurationLink = !empty($stockNav['system']['configuration']);
$showLogsLink = !empty($stockNav['system']['logs']);
$showThemesLink = !empty($stockNav['system']['themes']);
$showExtensionsManagerLink = !empty($stockNav['system']['extensions']);
$showUpdateSystemLink = !empty($stockNav['system']['update']);
$showContentCategory = $showCreatePageLink || $showListPagesLink;
$showAccountsCategory = $showGroupsLink || $showUsersLink;
$showTaxonomyCategory = $showCategoriesLink || $showChannelsLink || $showRedirectsLink || $showRoutingLink || $showTagsLink;
$systemNavItems = [];
if ($showConfigurationLink) {
    $systemNavItems[] = ['label' => 'Configuration', 'path' => $panelBase . '/configuration', 'section' => 'configuration'];
}
if ($showLogsLink) {
    $systemNavItems[] = ['label' => 'Event Log', 'path' => $panelBase . '/logs', 'section' => 'logs'];
}
if ($showThemesLink) {
    $systemNavItems[] = ['label' => 'Theme Manager', 'path' => $panelBase . '/themes', 'section' => 'themes'];
}
if ($showExtensionsManagerLink) {
    $systemNavItems[] = ['label' => 'Extension Manager', 'path' => $panelBase . '/extensions', 'section' => 'extensions'];
}
if ($showUpdateSystemLink) {
    $systemNavItems[] = ['label' => 'Update Raven', 'path' => $panelBase . '/update', 'section' => 'update'];
}
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
$showSystemCategory = $systemNavItems !== [];

$currentSection = is_string($section) ? $section : '';
$createPageAccordionOpen = $currentSection === 'page' && $pageNav === 'create';
$showCreatePageAccordion = $pageCreateChannelItems !== [];

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
$includePanelLayoutScripts = $section !== 'login';
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
        body#rvnp table[data-rvn-sort-table="1"] thead th[data-sort-key] {
            color: var(--raven-muted);
            cursor: pointer;
            user-select: none;
            transition: color 140ms ease;
        }

        body#rvnp table[data-rvn-sort-table="1"] thead th[data-sort-key].is-active-sort {
            color: var(--bs-emphasis-color);
            font-weight: 700;
        }

        body#rvnp table[data-rvn-sort-table="1"] thead th[data-sort-key].is-active-sort .raven-routing-sort-caret {
            opacity: 1;
        }

        body#rvnp .rvnp-brand-text-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.05;
            gap: 0.14rem;
        }

        body#rvnp .rvnp-brand-powered {
            display: block;
            font-size: 0.58rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            opacity: 0.82;
            line-height: 1.1;
        }

        body#rvnp .rvnp-footer-placeholder {
            height: 50px;
            background: transparent;
        }

        body#rvnp .nav-tabs[data-rvn-tab-group][data-rvn-tab-position="top"],
        body#rvnp .nav-tabs[data-rvn-tab-group][data-rvn-tab-position="bottom"] {
            column-gap: 0.5rem;
            row-gap: 0;
        }

        @media (max-width: 767.98px) {
            body#rvnp .nav-tabs[data-rvn-tab-group][data-rvn-tab-position="top"] > .nav-item:first-child,
            body#rvnp .nav-tabs[data-rvn-tab-group][data-rvn-tab-position="bottom"] > .nav-item:first-child {
                margin-inline-start: 0.5rem;
            }
        }

        /* Bottom-cloned tab bars should face downward off the tab-content surface. */
        body#rvnp .nav-tabs[data-rvn-tab-position="bottom"] {
            position: relative;
            z-index: 1;
            border-bottom: 0;
            border-top: 0;
            margin-top: -1px;
        }

        body#rvnp .nav-tabs[data-rvn-tab-position="bottom"]::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            border-top: 1px solid var(--raven-border);
            pointer-events: none;
            z-index: 1;
        }

        body#rvnp .nav-tabs[data-rvn-tab-position="bottom"] .nav-link {
            position: relative;
            z-index: 2;
            margin-bottom: 0;
            margin-top: 0;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-bottom-right-radius: var(--bs-nav-tabs-border-radius);
            border-bottom-left-radius: var(--bs-nav-tabs-border-radius);
        }

        body#rvnp .nav-tabs[data-rvn-tab-position="bottom"] .nav-link.active,
        body#rvnp .nav-tabs[data-rvn-tab-position="bottom"] .nav-item.show .nav-link {
            z-index: 3;
            margin-top: -1px;
            border-top-color: var(--raven-surface);
            border-bottom-color: var(--raven-border);
        }

        body#rvnp .rvnp-nav-subaccordion {
            border: 0;
            padding-bottom: 0;
        }

        body#rvnp .rvnp-nav-subsummary {
            list-style: none;
            cursor: pointer;
            padding: var(--bs-nav-link-padding-y) var(--bs-nav-link-padding-x);
            border-radius: var(--bs-nav-pills-border-radius);
            color: var(--bs-nav-pills-link-color);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.35rem;
        }

        body#rvnp .rvnp-nav-subsummary::-webkit-details-marker {
            display: none;
        }

        body#rvnp .rvnp-nav-subsummary::after {
            content: "\f282";
            font-family: "bootstrap-icons", sans-serif;
            font-size: 0.72rem;
            line-height: 1;
            opacity: 0.8;
            transition: transform 160ms ease;
        }

        body#rvnp details[open] > .rvnp-nav-subsummary::after {
            transform: rotate(180deg);
        }

        body#rvnp .rvnp-nav-subsummary.active {
            color: var(--bs-nav-pills-link-active-color);
            background: var(--bs-nav-pills-link-active-bg);
        }

        body#rvnp .rvnp-nav-sublist {
            margin-top: 0.25rem;
            margin-left: 0.95rem;
            border-left: 1px dashed var(--raven-border);
            padding-left: 0.55rem;
        }
    </style>
</head>
<body id="rvnp" class="theme-<?= e($panelThemeClass) ?><?= $showSidebar ? ' has-sidebar' : '' ?>">
<?php if ($showSidebar): ?>
    <!-- Mobile-only header navigation (xs/sm); sidebar appears from md upward. -->
    <!-- Navigation groups intentionally mirror desktop sidebar so IA remains consistent across breakpoints. -->
    <nav id="rvnp-mobile" class="navbar navbar-expand-md navbar-dark bg-dark d-md-none">
        <div class="container-fluid">
            <a class="navbar-brand rvnp-brand-link" href="<?= e($panelBase) ?>/">
                <span class="rvnp-brand-lockup">
                    <img
                        class="rvnp-brand-logo"
                        src="<?= e($panelBrandLogoUrl) ?>"
                        alt=""
                        aria-hidden="true"
                        decoding="async"
                    >
                    <span class="rvnp-brand-text-wrap">
                        <span class="rvnp-brand-text"><?= e($panelBrandName) ?></span>
                        <?php if ($showPoweredByRaven): ?>
                            <small class="rvnp-brand-powered">Powered by Raven</small>
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

                    <?php if ($showContentCategory): ?>
                        <h2 class="h6 text-uppercase text-white-50">Content</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php if ($showCreatePageLink): ?>
                                <li class="nav-item">
                                    <?php if ($showCreatePageAccordion): ?>
                                    <details class="rvnp-nav-subaccordion"<?= $createPageAccordionOpen ? ' open' : '' ?>>
                                        <summary class="rvnp-nav-subsummary<?= $createPageAccordionOpen ? ' active' : '' ?>">Create Page</summary>
                                        <ul class="nav nav-pills flex-column gap-1 rvnp-nav-sublist">
                                            <li class="nav-item">
                                                <a class="nav-link<?= ($section === 'page' && $pageNav === 'create' && $pageNavChannel === '') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page/edit">
                                                    In Root
                                                </a>
                                            </li>
                                            <?php foreach ($pageCreateChannelItems as $channelItem): ?>
                                                <li class="nav-item">
                                                    <a
                                                        class="nav-link<?= ($section === 'page' && $pageNav === 'create' && $pageNavChannel === (string) $channelItem['slug']) ? ' active' : '' ?>"
                                                        href="<?= e($panelBase) ?>/page/edit?channel=<?= e(rawurlencode((string) $channelItem['slug'])) ?>"
                                                    >
                                                        In <?= e((string) $channelItem['label']) ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                    <?php else: ?>
                                        <a class="nav-link<?= ($section === 'page' && $pageNav === 'create') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page/edit">Create Page</a>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                            <?php if ($showListPagesLink): ?>
                                <li class="nav-item"><a class="nav-link<?= ($section === 'page' && $pageNav === 'list') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page">List Pages</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($showModulesCategory): ?>
                        <h2 class="h6 text-uppercase text-white-50">Modules</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php foreach ($moduleNavItems as $moduleItem): ?>
                                <li class="nav-item">
                                    <a class="nav-link<?= $section === (string) $moduleItem['section'] ? ' active' : '' ?>" href="<?= e((string) $moduleItem['path']) ?>">
                                        <?= e((string) $moduleItem['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($showAccountsCategory): ?>
                        <h2 class="h6 text-uppercase text-white-50">Accounts</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php if ($showGroupsLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'group' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/group">Groups</a></li>
                            <?php endif; ?>
                            <?php if ($showUsersLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'user' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/user">Users</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($showTaxonomyCategory): ?>
                        <h2 class="h6 text-uppercase text-white-50">Taxonomy</h2>
                        <ul class="nav nav-pills flex-column gap-1 mb-3">
                            <?php if ($showCategoriesLink): ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'category' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/category">Categories</a></li>
                            <?php endif; ?>
                            <?php if ($showChannelsLink): ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'channel' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/channel">Channels</a></li>
                            <?php endif; ?>
                            <?php if ($showRedirectsLink): ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'redirect' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/redirect">Redirects</a></li>
                            <?php endif; ?>
                            <?php if ($showRoutingLink): ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'routing' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/routing">Routing Table</a></li>
                            <?php endif; ?>
                            <?php if ($showTagsLink): ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'tag' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/tag">Tags</a></li>
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

                    <?php if ($showSystemCategory): ?>
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

<div class="container-fluid py-3 rvnp-layout">
    <div class="row g-3 rvnp-layout-row">
        <?php if ($showSidebar): ?>
            <aside id="rvnp-sidebar" class="d-none d-md-block col-md-3 col-lg-3 col-xl-2">
                <div class="card rvnp-sidebar-card">
                    <div class="card-body">
                        <!-- Sidebar brand link replaces the removed top navbar brand. -->
                        <div class="mb-3 pb-2 border-bottom rvnp-sidebar-brand">
                            <a class="text-decoration-none fw-semibold fs-5 rvnp-sidebar-brand-link rvnp-brand-link" href="<?= e($panelBase) ?>/">
                                <span class="rvnp-brand-lockup">
                                    <img
                                        class="rvnp-brand-logo"
                                        src="<?= e($panelBrandLogoUrl) ?>"
                                        alt=""
                                        aria-hidden="true"
                                        decoding="async"
                                    >
                                    <span class="rvnp-brand-text-wrap">
                                        <span class="rvnp-brand-text"><?= e($panelBrandName) ?></span>
                                        <?php if ($showPoweredByRaven): ?>
                                            <small class="rvnp-brand-powered">Powered by Raven</small>
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

                        <?php if ($showContentCategory): ?>
                            <!-- Content group for publishing entities. -->
                            <h2 class="h6 text-uppercase text-muted">Content</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php if ($showCreatePageLink): ?>
                                    <li class="nav-item">
                                        <?php if ($showCreatePageAccordion): ?>
                                        <details class="rvnp-nav-subaccordion"<?= $createPageAccordionOpen ? ' open' : '' ?>>
                                            <summary class="rvnp-nav-subsummary<?= $createPageAccordionOpen ? ' active' : '' ?>">Create Page</summary>
                                            <ul class="nav nav-pills flex-column gap-1 rvnp-nav-sublist">
                                                <li class="nav-item">
                                                    <a class="nav-link<?= ($section === 'page' && $pageNav === 'create' && $pageNavChannel === '') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page/edit">
                                                        In Root
                                                    </a>
                                                </li>
                                                <?php foreach ($pageCreateChannelItems as $channelItem): ?>
                                                    <li class="nav-item">
                                                        <a
                                                            class="nav-link<?= ($section === 'page' && $pageNav === 'create' && $pageNavChannel === (string) $channelItem['slug']) ? ' active' : '' ?>"
                                                            href="<?= e($panelBase) ?>/page/edit?channel=<?= e(rawurlencode((string) $channelItem['slug'])) ?>"
                                                        >
                                                            In <?= e((string) $channelItem['label']) ?>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                        <?php else: ?>
                                            <a class="nav-link<?= ($section === 'page' && $pageNav === 'create') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page/edit">Create Page</a>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                                <?php if ($showListPagesLink): ?>
                                    <li class="nav-item"><a class="nav-link<?= ($section === 'page' && $pageNav === 'list') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page">List Pages</a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($showModulesCategory): ?>
                            <h2 class="h6 text-uppercase text-muted">Modules</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php foreach ($moduleNavItems as $moduleItem): ?>
                                    <li class="nav-item">
                                        <a class="nav-link<?= $section === (string) $moduleItem['section'] ? ' active' : '' ?>" href="<?= e((string) $moduleItem['path']) ?>">
                                            <?= e((string) $moduleItem['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($showAccountsCategory): ?>
                            <!-- Accounts group for user/group access controls. -->
                            <h2 class="h6 text-uppercase text-muted">Accounts</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php if ($showGroupsLink): ?>
                                    <li class="nav-item"><a class="nav-link<?= $section === 'group' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/group">Groups</a></li>
                                <?php endif; ?>
                                <?php if ($showUsersLink): ?>
                                    <li class="nav-item"><a class="nav-link<?= $section === 'user' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/user">Users</a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($showTaxonomyCategory): ?>
                            <!-- Taxonomy group for content classification entities. -->
                            <h2 class="h6 text-uppercase text-muted">Taxonomy</h2>
                            <ul class="nav nav-pills flex-column gap-1 mb-3">
                                <?php if ($showCategoriesLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'category' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/category">Categories</a></li>
                                <?php endif; ?>
                                <?php if ($showChannelsLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'channel' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/channel">Channels</a></li>
                                <?php endif; ?>
                                <?php if ($showRedirectsLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'redirect' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/redirect">Redirects</a></li>
                                <?php endif; ?>
                                <?php if ($showRoutingLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'routing' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/routing">Routing Table</a></li>
                                <?php endif; ?>
                                <?php if ($showTagsLink): ?>
                                <li class="nav-item"><a class="nav-link<?= $section === 'tag' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/tag">Tags</a></li>
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

                        <?php if ($showSystemCategory): ?>
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

        <main id="rvnp-main" class="<?= $showSidebar ? 'col-12 col-md-9 col-lg-9 col-xl-10' : 'col-12 rvnp-login-main' ?>">
            <?= $content ?>
            <footer class="rvnp-footer-placeholder" aria-hidden="true"></footer>
        </main>
    </div>
</div>
<?php if ($includePanelLayoutScripts): ?>
<script src="/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        function tabButtons(nav) {
            if (!(nav instanceof HTMLElement)) {
                return [];
            }

            return Array.prototype.slice.call(nav.querySelectorAll('button[data-bs-toggle="tab"]'));
        }

        function syncTabGroup(group, targetSelector) {
            if (group === '' || targetSelector === '') {
                return;
            }

            document.querySelectorAll('ul.nav.nav-tabs[data-rvn-tab-group="' + group + '"]').forEach(function (nav) {
                tabButtons(nav).forEach(function (button) {
                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }

                    var isMatch = String(button.getAttribute('data-bs-target') || '') === targetSelector;
                    button.classList.toggle('active', isMatch);
                    button.setAttribute('aria-selected', isMatch ? 'true' : 'false');
                    if (isMatch) {
                        button.removeAttribute('tabindex');
                    } else {
                        button.setAttribute('tabindex', '-1');
                    }
                });
            });
        }

        function tabKeyFromTargetSelector(targetSelector) {
            var normalized = String(targetSelector || '').trim();
            if (normalized === '') {
                return '';
            }

            if (normalized.charAt(0) === '#') {
                normalized = normalized.slice(1);
            }

            if (normalized.indexOf('rvnp-editor-pane-') === 0) {
                normalized = normalized.slice('rvnp-editor-pane-'.length);
            }

            normalized = normalized.toLowerCase();
            return /^[a-z0-9_-]{1,40}$/.test(normalized) ? normalized : '';
        }

        function syncEditorFormTabInput(nav, targetSelector) {
            if (!(nav instanceof HTMLElement)) {
                return;
            }

            var editorLayout = nav.closest('.rvnp-editor-layout[data-rvn-tab-layout="editor"]');
            if (!(editorLayout instanceof HTMLElement)) {
                return;
            }

            var form = editorLayout.closest('form');
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            var tabKey = tabKeyFromTargetSelector(targetSelector);
            if (tabKey === '') {
                return;
            }

            var input = form.querySelector('input[type="hidden"][name="tab"][data-rvn-editor-active-tab="1"]');
            if (!(input instanceof HTMLInputElement)) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'tab';
                input.setAttribute('data-rvn-editor-active-tab', '1');
                form.appendChild(input);
            }

            input.value = tabKey;
        }

        function cloneTabsToBottom(topNav) {
            if (!(topNav instanceof HTMLElement)) {
                return;
            }

            var group = String(topNav.id || '').trim();
            if (group === '') {
                return;
            }

            topNav.setAttribute('data-rvn-tab-group', group);
            topNav.setAttribute('data-rvn-tab-position', 'top');

            var existingBottom = document.querySelector(
                'ul.nav.nav-tabs[data-rvn-tab-group="' + group + '"][data-rvn-tab-position="bottom"]'
            );
            if (existingBottom instanceof HTMLElement) {
                return;
            }

            var next = topNav.nextElementSibling;
            while (next && !(next instanceof HTMLElement && next.classList.contains('tab-content'))) {
                next = next.nextElementSibling;
            }
            if (!(next instanceof HTMLElement)) {
                return;
            }

            var bottomNav = topNav.cloneNode(true);
            if (!(bottomNav instanceof HTMLElement)) {
                return;
            }

            bottomNav.id = group.endsWith('-top')
                ? group.slice(0, -4) + '-bottom'
                : group + '-bottom';
            bottomNav.classList.add('mb-3');
            bottomNav.setAttribute('data-rvn-tab-group', group);
            bottomNav.setAttribute('data-rvn-tab-position', 'bottom');

            tabButtons(bottomNav).forEach(function (button) {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                var id = String(button.id || '').trim();
                if (id !== '') {
                    button.id = id + '-bottom';
                }
            });

            next.insertAdjacentElement('afterend', bottomNav);
        }

        document.querySelectorAll('ul.nav.nav-tabs[id]').forEach(function (nav) {
            cloneTabsToBottom(nav);
        });

        var groupState = Object.create(null);
        document.querySelectorAll('ul.nav.nav-tabs[data-rvn-tab-group]').forEach(function (nav) {
            if (!(nav instanceof HTMLElement)) {
                return;
            }

            var group = String(nav.getAttribute('data-rvn-tab-group') || '').trim();
            if (group === '') {
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(groupState, group)) {
                groupState[group] = '';
            }

            var activeButton = nav.querySelector('button.nav-link.active[data-bs-toggle="tab"]');
            if (activeButton instanceof HTMLButtonElement) {
                var target = String(activeButton.getAttribute('data-bs-target') || '').trim();
                if (target !== '' && String(groupState[group]).trim() === '') {
                    groupState[group] = target;
                }
            }
        });

        Object.keys(groupState).forEach(function (group) {
            var target = String(groupState[group] || '').trim();
            if (target === '') {
                var firstButton = document.querySelector(
                    'ul.nav.nav-tabs[data-rvn-tab-group="' + group + '"] button[data-bs-toggle="tab"]'
                );
                if (firstButton instanceof HTMLButtonElement) {
                    target = String(firstButton.getAttribute('data-bs-target') || '').trim();
                }
            }

            syncTabGroup(group, target);
            var topNav = document.querySelector(
                'ul.nav.nav-tabs[data-rvn-tab-group="' + group + '"][data-rvn-tab-position="top"]'
            );
            if (!(topNav instanceof HTMLElement)) {
                topNav = document.querySelector('ul.nav.nav-tabs[data-rvn-tab-group="' + group + '"]');
            }
            syncEditorFormTabInput(topNav, target);
        });

        document.addEventListener('shown.bs.tab', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLButtonElement)) {
                return;
            }

            if (target.getAttribute('data-bs-toggle') !== 'tab') {
                return;
            }

            var nav = target.closest('ul.nav.nav-tabs[data-rvn-tab-group]');
            if (!(nav instanceof HTMLElement)) {
                return;
            }

            var group = String(nav.getAttribute('data-rvn-tab-group') || '').trim();
            var targetSelector = String(target.getAttribute('data-bs-target') || '').trim();
            syncTabGroup(group, targetSelector);
            syncEditorFormTabInput(nav, targetSelector);
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            var editorLayout = form.querySelector('.rvnp-editor-layout[data-rvn-tab-layout="editor"]');
            if (!(editorLayout instanceof HTMLElement)) {
                return;
            }

            var activeButton = editorLayout.querySelector(
                'ul.nav.nav-tabs[data-rvn-tab-position="top"] button.nav-link.active[data-bs-toggle="tab"]'
            );
            if (!(activeButton instanceof HTMLButtonElement)) {
                activeButton = editorLayout.querySelector('ul.nav.nav-tabs button.nav-link.active[data-bs-toggle="tab"]');
            }
            if (!(activeButton instanceof HTMLButtonElement)) {
                return;
            }

            var nav = activeButton.closest('ul.nav.nav-tabs');
            var targetSelector = String(activeButton.getAttribute('data-bs-target') || '').trim();
            syncEditorFormTabInput(nav, targetSelector);
        });
    })();
</script>
<script>
    (function () {
        function syncRowHighlight(checkbox) {
            var row = checkbox.closest('tr');
            if (!row) {
                return;
            }

            row.classList.toggle('raven-row-selected', checkbox.checked);
        }

        document.querySelectorAll('input[type="checkbox"][data-rvn-row-select="1"]').forEach(function (checkbox) {
            syncRowHighlight(checkbox);
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            if (target.type !== 'checkbox' || target.getAttribute('data-rvn-row-select') !== '1') {
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

            var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-rvn-sort-row="1"]'));
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

        document.querySelectorAll('table[data-rvn-sort-table="1"]').forEach(function (table) {
            initSortableTable(table);
        });
    })();
</script>
<script>
    (function () {
        function normalizedPathFromAction(action) {
            if (typeof action !== 'string' || action.trim() === '') {
                return '';
            }

            try {
                var parsed = new URL(action, window.location.origin);
                return String(parsed.pathname || '').toLowerCase();
            } catch (error) {
                return '';
            }
        }

        function isAutoSlugSaveForm(form) {
            if (!(form instanceof HTMLFormElement)) {
                return false;
            }

            var explicitMode = String(form.getAttribute('data-rvn-autoslug') || '').trim().toLowerCase();
            if (explicitMode === '0' || explicitMode === 'false' || explicitMode === 'off') {
                return false;
            }
            if (explicitMode === '1' || explicitMode === 'true' || explicitMode === 'on') {
                return true;
            }

            var actionPath = normalizedPathFromAction(String(form.getAttribute('action') || ''));
            if (actionPath === '') {
                return false;
            }

            return /\/save$/.test(actionPath);
        }

        function isCreateMode(form) {
            if (!(form instanceof HTMLFormElement)) {
                return false;
            }

            var idInput = form.querySelector('input[name="id"]');
            if (idInput instanceof HTMLInputElement) {
                var rawId = String(idInput.value || '').trim();
                if (rawId !== '' && !/^0+$/.test(rawId)) {
                    return false;
                }
            }

            var originalSlugInput = form.querySelector('input[name="original_slug"]');
            if (originalSlugInput instanceof HTMLInputElement) {
                return String(originalSlugInput.value || '').trim() === '';
            }

            return true;
        }

        function slugify(value) {
            var slug = String(value || '').toLowerCase();
            slug = slug.replace(/[\s_]+/g, '-');
            slug = slug.replace(/[^a-z0-9-]/g, '');
            slug = slug.replace(/-+/g, '-');
            slug = slug.replace(/^-+|-+$/g, '');
            return slug;
        }

        function initAutoSlugForm(form) {
            if (!isAutoSlugSaveForm(form) || !isCreateMode(form)) {
                return;
            }

            var slugInput = form.querySelector('input[name="slug"]');
            if (!(slugInput instanceof HTMLInputElement) || slugInput.readOnly || slugInput.disabled) {
                return;
            }

            var sourceInput = form.querySelector('input[name="title"]');
            if (!(sourceInput instanceof HTMLInputElement)) {
                sourceInput = form.querySelector('input[name="name"]');
            }
            if (!(sourceInput instanceof HTMLInputElement) || sourceInput.disabled) {
                return;
            }

            var manualSlug = String(slugInput.value || '').trim() !== '';
            var syncing = false;

            function syncSlugFromSource() {
                if (manualSlug) {
                    return;
                }

                syncing = true;
                slugInput.value = slugify(sourceInput.value);
                syncing = false;
            }

            sourceInput.addEventListener('input', syncSlugFromSource);
            sourceInput.addEventListener('change', syncSlugFromSource);

            slugInput.addEventListener('input', function () {
                if (syncing) {
                    return;
                }

                manualSlug = true;
            });

            syncSlugFromSource();
        }

        document.querySelectorAll('form').forEach(function (form) {
            initAutoSlugForm(form);
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
