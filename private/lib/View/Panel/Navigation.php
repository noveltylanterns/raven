<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Navigation.php
 * Shared panel navigation renderer for desktop sidebar and mobile nav bar.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use function Raven\Lib\Security\e;

/**
 * Renders the panel sidebar and mobile navigation bar from a declarative config array.
 *
 * The caller (wrapper.php) is responsible for preparing all data: resolving
 * permissions, sanitising session-sourced nav items, computing visibility flags,
 * and building the config array. This class owns only the HTML structure so that
 * layout changes never require touching route controllers or the session layer.
 *
 * Both renderMobile() and renderSidebar() accept the same config shape. The
 * private renderGroups() helper renders the shared nav group list used by both.
 *
 * Expected config keys:
 *   panel_base                  (string)  Base panel URL path, e.g. "/admin"
 *   section                     (string)  Active nav section key for highlight
 *   page_nav                    (string)  Active page sub-nav key ('list', 'create', '')
 *   page_nav_channel            (string)  Active channel slug for create-in-channel highlight
 *   welcome_name                (string)  Display name for the "Welcome back" heading
 *   csrf_field                  (string)  Trusted CSRF hidden-input HTML for the logout form
 *   brand_name                  (string)  Panel brand name text
 *   brand_logo_url              (string)  Absolute or root-relative URL of the brand logo
 *   show_powered_by             (bool)    Show "Powered by Raven" sub-label under brand name
 *   show_content                (bool)    Show the Content nav group
 *   show_create_page            (bool)    Show the Create Page link inside Content
 *   show_create_page_accordion  (bool)    Expand Create Page into a per-channel sub-accordion
 *   create_page_accordion_open  (bool)    Render the accordion in its open state on load
 *   page_create_channel_items   (array)   Items for the Create Page sub-accordion; each has 'label' and 'slug'
 *   show_list_pages             (bool)    Show the List Pages link inside Content
 *   show_modules                (bool)    Show the Modules nav group
 *   module_items                (array)   Module nav items; each has 'label', 'path', 'section'
 *   show_accounts               (bool)    Show the Accounts nav group
 *   show_groups                 (bool)    Show the Groups link inside Accounts
 *   show_users                  (bool)    Show the Users link inside Accounts
 *   show_taxonomy               (bool)    Show the Taxonomy nav group
 *   show_categories             (bool)    Show the Categories link inside Taxonomy
 *   show_channels               (bool)    Show the Channels link inside Taxonomy
 *   show_redirects              (bool)    Show the Redirects link inside Taxonomy
 *   show_routing                (bool)    Show the Routing Table link inside Taxonomy
 *   show_tags                   (bool)    Show the Tags link inside Taxonomy
 *   show_extensions             (bool)    Show the Extensions nav group
 *   extension_items             (array)   Extension nav items; each has 'label', 'path', 'section'
 *   show_system                 (bool)    Show the System nav group
 *   system_items                (array)   System nav items; each has 'label', 'path', 'section'
 */
final class Navigation
{
    /**
     * Renders the mobile navigation bar shown at xs/sm breakpoints.
     *
     * The mobile bar is a Bootstrap navbar with a hamburger toggle. Its nav
     * groups mirror the desktop sidebar so information architecture stays
     * consistent across breakpoints without maintaining two independent trees.
     *
     * @param array<string, mixed> $config Declarative nav configuration (see class docblock).
     * @return string Trusted HTML for the mobile navbar, ready to echo before the layout container.
     */
    public static function renderMobile(array $config): string
    {
        $panelBase  = (string) ($config['panel_base'] ?? '');
        $brandName  = (string) ($config['brand_name'] ?? 'Raven CMS');
        $logoUrl    = (string) ($config['brand_logo_url'] ?? '');
        $showPoweredBy = (bool) ($config['show_powered_by'] ?? false);
        $welcomeName   = (string) ($config['welcome_name'] ?? 'User');
        $csrfField     = isset($config['csrf_field']) ? (string) $config['csrf_field'] : null;

        // Mobile headings use text-white-50 because the navbar is dark-backgrounded.
        $groups = self::renderGroups($config, 'text-uppercase text-white-50');

        ob_start();
        ?>
    <!-- Mobile-only header navigation (xs/sm); sidebar appears from md upward. -->
    <!-- Navigation groups intentionally mirror desktop sidebar so IA remains consistent across breakpoints. -->
    <nav id="rvnp-mobile" class="navbar navbar-expand-md navbar-dark bg-dark d-md-none">
        <div class="container-fluid">
            <a class="navbar-brand rvnp-brand-link" href="<?= e($panelBase) ?>/">
                <span class="rvnp-brand-lockup">
                    <img
                        class="rvnp-brand-logo"
                        src="<?= e($logoUrl) ?>"
                        alt=""
                        aria-hidden="true"
                        decoding="async"
                    >
                    <span class="rvnp-brand-text-wrap">
                        <span class="rvnp-brand-text"><?= e($brandName) ?></span>
                        <?php
                        /* Optional product attribution line under the brand text. */
                        if ($showPoweredBy):
                        ?>
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
                        <?= self::renderWelcomeItems($config) ?>
                        <li class="nav-item">
                            <!-- Logout remains POST-only with CSRF to avoid accidental/logged URL-triggered sign-outs. -->
                            <form method="post" action="<?= e($panelBase) ?>/logout" class="m-0">
                                <?php
                                /* Render CSRF field only when caller provided the trusted hidden input. */
                                if ($csrfField !== null):
                                ?>
                                    <?= $csrfField ?>
                                <?php endif; ?>
                                <button type="submit" class="nav-link text-start w-100">Logout</button>
                            </form>
                        </li>
                    </ul>
                    <?= $groups ?>
                </div>
            </div>
        </div>
    </nav>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the desktop sidebar shown at md+ breakpoints.
     *
     * The sidebar is a card-wrapped aside. It contains the brand lockup at the
     * top, then the same nav group tree as the mobile bar.
     *
     * @param array<string, mixed> $config Declarative nav configuration (see class docblock).
     * @return string Trusted HTML for the sidebar aside element, ready to echo inside the layout row.
     */
    public static function renderSidebar(array $config): string
    {
        $panelBase  = (string) ($config['panel_base'] ?? '');
        $brandName  = (string) ($config['brand_name'] ?? 'Raven CMS');
        $logoUrl    = (string) ($config['brand_logo_url'] ?? '');
        $showPoweredBy = (bool) ($config['show_powered_by'] ?? false);
        $welcomeName   = (string) ($config['welcome_name'] ?? 'User');
        $csrfField     = isset($config['csrf_field']) ? (string) $config['csrf_field'] : null;

        // Sidebar headings use text-muted because the sidebar card is light-backgrounded.
        $groups = self::renderGroups($config, 'text-uppercase text-muted');

        ob_start();
        ?>
        <aside id="rvnp-sidebar" class="d-none d-md-block col-md-3 col-lg-3 col-xl-2">
            <div class="card rvnp-sidebar-card">
                <div class="card-body">
                    <!-- Sidebar brand link replaces the removed top navbar brand. -->
                    <div class="mb-3 pb-2 border-bottom rvnp-sidebar-brand">
                        <a class="text-decoration-none fw-semibold fs-5 rvnp-sidebar-brand-link rvnp-brand-link" href="<?= e($panelBase) ?>/">
                            <span class="rvnp-brand-lockup">
                                <img
                                    class="rvnp-brand-logo"
                                    src="<?= e($logoUrl) ?>"
                                    alt=""
                                    aria-hidden="true"
                                    decoding="async"
                                >
                                <span class="rvnp-brand-text-wrap">
                                    <span class="rvnp-brand-text"><?= e($brandName) ?></span>
                                    <?php
                                    /* Optional product attribution line under the brand text. */
                                    if ($showPoweredBy):
                                    ?>
                                        <small class="rvnp-brand-powered">Powered by Raven</small>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </a>
                    </div>

                    <!-- Welcome group contains the dashboard landing link. -->
                    <h2 class="h6 text-uppercase text-muted">Welcome back, <?= e($welcomeName) ?>!</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?= self::renderWelcomeItems($config, true) ?>
                        <li class="nav-item">
                            <!-- Use POST + CSRF for logout to match mobile behavior and prevent URL-logged sign-outs. -->
                            <form method="post" action="<?= e($panelBase) ?>/logout" class="m-0">
                                <?php
                                /* Render CSRF field only when caller provided the trusted hidden input. */
                                if ($csrfField !== null):
                                ?>
                                    <?= $csrfField ?>
                                <?php endif; ?>
                                <button type="submit" class="nav-link btn btn-secondary text-start w-100">Logout</button>
                            </form>
                        </li>
                    </ul>
                    <?= $groups ?>
                </div>
            </div>
        </aside>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the Dashboard and Preferences nav items shared by both mobile and sidebar welcome groups.
     *
     * Logout is not included here because its CSRF form wiring differs in each
     * context and is handled by the calling public method.
     *
     * @param array<string, mixed> $config         Nav config (uses panel_base and section).
     * @param bool                 $useButtonStyles Whether to apply Bootstrap button tones to the desktop sidebar actions.
     * @return string Trusted HTML for the two welcome li elements.
     */
    private static function renderWelcomeItems(array $config, bool $useButtonStyles = false): string
    {
        $panelBase = (string) ($config['panel_base'] ?? '');
        $section   = (string) ($config['section'] ?? '');
        $dashboardTone = $useButtonStyles
            ? ($section === 'dashboard' ? ' btn btn-primary' : ' btn btn-secondary')
            : '';
        $preferencesTone = $useButtonStyles
            ? ($section === 'preferences' ? ' btn btn-primary' : ' btn btn-secondary')
            : '';

        ob_start();
        ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $section === 'dashboard' ? ' active' : '' ?><?= $dashboardTone ?>" href="<?= e($panelBase) ?>/">Dashboard</a>
                        </li>
                        <li class="nav-item"><a class="nav-link<?= $section === 'preferences' ? ' active' : '' ?><?= $preferencesTone ?>" href="<?= e($panelBase) ?>/preferences">Preferences</a></li>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the shared nav category groups (Content, Modules, Accounts, Taxonomy, Extensions, System).
     *
     * This method is called by both renderMobile() and renderSidebar() with a
     * different $headingClass so that heading colour matches each surface without
     * duplicating the full group tree.
     *
     * @param array<string, mixed> $config       Nav configuration (see class docblock).
     * @param string               $headingClass CSS classes applied to each category h2 heading.
     * @return string Trusted HTML for all visible nav category groups.
     */
    private static function renderGroups(array $config, string $headingClass): string
    {
        $panelBase = (string) ($config['panel_base'] ?? '');
        $section   = (string) ($config['section'] ?? '');
        $pageNav   = isset($config['page_nav']) ? (string) $config['page_nav'] : null;
        $pageNavChannel = (string) ($config['page_nav_channel'] ?? '');

        $showContent   = (bool) ($config['show_content'] ?? false);
        $showCreatePage = (bool) ($config['show_create_page'] ?? false);
        $showCreateAccordion = (bool) ($config['show_create_page_accordion'] ?? false);
        $accordionOpen = (bool) ($config['create_page_accordion_open'] ?? false);
        $channelItems  = is_array($config['page_create_channel_items'] ?? null) ? $config['page_create_channel_items'] : [];
        $showListPages = (bool) ($config['show_list_pages'] ?? false);

        $showModules   = (bool) ($config['show_modules'] ?? false);
        $moduleItems   = is_array($config['module_items'] ?? null) ? $config['module_items'] : [];

        $showAccounts  = (bool) ($config['show_accounts'] ?? false);
        $showGroups    = (bool) ($config['show_groups'] ?? false);
        $showUsers     = (bool) ($config['show_users'] ?? false);

        $showTaxonomy  = (bool) ($config['show_taxonomy'] ?? false);
        $showCategories = (bool) ($config['show_categories'] ?? false);
        $showChannels  = (bool) ($config['show_channels'] ?? false);
        $showRedirects = (bool) ($config['show_redirects'] ?? false);
        $showRouting   = (bool) ($config['show_routing'] ?? false);
        $showTags      = (bool) ($config['show_tags'] ?? false);

        $showExtensions  = (bool) ($config['show_extensions'] ?? false);
        $extensionItems  = is_array($config['extension_items'] ?? null) ? $config['extension_items'] : [];

        $showSystem  = (bool) ($config['show_system'] ?? false);
        $systemItems = is_array($config['system_items'] ?? null) ? $config['system_items'] : [];

        ob_start();

        /* Render Content group only when content navigation is enabled. */
        if ($showContent): ?>
                    <!-- Content group for publishing entities. -->
                    <h2 class="h6 <?= e($headingClass) ?>">Content</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Show Create Page entry only when the caller enables page creation links. */
                        if ($showCreatePage):
                        ?>
                            <li class="nav-item">
                                <?php
                                /* Optional accordion mode expands create links per channel. */
                                if ($showCreateAccordion):
                                ?>
                                <details class="rvnp-nav-subaccordion"<?= $accordionOpen ? ' open' : '' ?>>
                                    <summary class="rvnp-nav-subsummary<?= $accordionOpen ? ' active' : '' ?>">Create Page</summary>
                                    <ul class="nav nav-pills flex-column gap-1 rvnp-nav-sublist">
                                        <li class="nav-item">
                                            <a class="nav-link<?= ($section === 'page' && $pageNav === 'create' && $pageNavChannel === '') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page/edit">
                                                In Root
                                            </a>
                                        </li>
                                        <?php
                                        /* Render one channel-specific create link per configured channel item. */
                                        foreach ($channelItems as $channelItem):
                                        ?>
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
                        <?php
                        /* List Pages link is optional for installs with restricted content visibility. */
                        if ($showListPages):
                        ?>
                            <li class="nav-item"><a class="nav-link<?= ($section === 'page' && $pageNav === 'list') ? ' active' : '' ?>" href="<?= e($panelBase) ?>/page">List Pages</a></li>
                        <?php endif; ?>
                    </ul>
        <?php endif;

        /* Render Modules group only when module navigation items are enabled. */
        if ($showModules): ?>
                    <h2 class="h6 <?= e($headingClass) ?>">Modules</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Render one module link per configured module item. */
                        foreach ($moduleItems as $moduleItem):
                        ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $section === (string) $moduleItem['section'] ? ' active' : '' ?>" href="<?= e((string) $moduleItem['path']) ?>">
                                    <?= e((string) $moduleItem['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
        <?php endif;

        /* Render Accounts group only when account-management nav is enabled. */
        if ($showAccounts): ?>
                    <!-- Accounts group for user/group access controls. -->
                    <h2 class="h6 <?= e($headingClass) ?>">Accounts</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Groups link visibility follows caller-provided permission state. */
                        if ($showGroups):
                        ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'group' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/group">Groups</a></li>
                        <?php endif; ?>
                        <?php
                        /* Users link visibility follows caller-provided permission state. */
                        if ($showUsers):
                        ?>
                            <li class="nav-item"><a class="nav-link<?= $section === 'user' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/user">Users</a></li>
                        <?php endif; ?>
                    </ul>
        <?php endif;

        /* Render Taxonomy group only when taxonomy navigation is enabled. */
        if ($showTaxonomy): ?>
                    <!-- Taxonomy group for content classification entities. -->
                    <h2 class="h6 <?= e($headingClass) ?>">Taxonomy</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Categories link visibility follows caller-provided permission state. */
                        if ($showCategories):
                        ?>
                        <li class="nav-item"><a class="nav-link<?= $section === 'category' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/category">Categories</a></li>
                        <?php endif; ?>
                        <?php
                        /* Channels link visibility follows caller-provided permission state. */
                        if ($showChannels):
                        ?>
                        <li class="nav-item"><a class="nav-link<?= $section === 'channel' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/channel">Channels</a></li>
                        <?php endif; ?>
                        <?php
                        /* Redirects link visibility follows caller-provided permission state. */
                        if ($showRedirects):
                        ?>
                        <li class="nav-item"><a class="nav-link<?= $section === 'redirect' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/redirect">Redirects</a></li>
                        <?php endif; ?>
                        <?php
                        /* Routing link visibility follows caller-provided permission state. */
                        if ($showRouting):
                        ?>
                        <li class="nav-item"><a class="nav-link<?= $section === 'routing' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/routing">Routing Table</a></li>
                        <?php endif; ?>
                        <?php
                        /* Tags link visibility follows caller-provided permission state. */
                        if ($showTags):
                        ?>
                        <li class="nav-item"><a class="nav-link<?= $section === 'tag' ? ' active' : '' ?>" href="<?= e($panelBase) ?>/tag">Tags</a></li>
                        <?php endif; ?>
                    </ul>
        <?php endif;

        /* Render Extensions group only when extension nav items are enabled. */
        if ($showExtensions): ?>
                    <h2 class="h6 <?= e($headingClass) ?>">Extensions</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Render one extension link per configured extension nav item. */
                        foreach ($extensionItems as $extensionItem):
                        ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $section === (string) $extensionItem['section'] ? ' active' : '' ?>" href="<?= e((string) $extensionItem['path']) ?>">
                                    <?= e((string) $extensionItem['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
        <?php endif;

        /* Render System group only when system-level nav items are enabled. */
        if ($showSystem): ?>
                    <!-- System group for app-level settings and account administration. -->
                    <h2 class="h6 <?= e($headingClass) ?>">System</h2>
                    <ul class="nav nav-pills flex-column gap-1 mb-3">
                        <?php
                        /* Render one system link per configured system nav item. */
                        foreach ($systemItems as $systemItem):
                        ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $section === (string) $systemItem['section'] ? ' active' : '' ?>" href="<?= e((string) $systemItem['path']) ?>">
                                    <?= e((string) $systemItem['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
        <?php endif;

        return (string) ob_get_clean();
    }
}
