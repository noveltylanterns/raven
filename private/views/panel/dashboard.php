<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/dashboard.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $user */
/** @var bool $canManageUsers */
/** @var bool $canManageGroups */
/** @var bool $canManageConfiguration */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $section */
/** @var string $csrfField */
/** @var array<string, mixed>|null $configSnapshot */
/** @var array<int, array{
 *   path: string,
 *   segments: array<int, string>,
 *   label: string,
 *   type: string,
 *   value: string
 * }>|null $configFields */
/** @var array<string, string>|null $publicThemeOptions */
/** @var string|null $activeConfigTab */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$configSnapshot = $configSnapshot ?? null;
$configFields = $configFields ?? [];
$publicThemeOptions = is_array($publicThemeOptions ?? null) ? $publicThemeOptions : [];
$activeConfigTab = strtolower(trim((string) ($activeConfigTab ?? 'basic')));
if (!in_array($activeConfigTab, ['basic', 'content', 'database', 'debug', 'media', 'meta', 'session'], true)) {
    $activeConfigTab = 'basic';
}
$siteDomainRaw = trim((string) (($configSnapshot['site']['domain'] ?? $site['domain'] ?? 'localhost')));
if (str_contains($siteDomainRaw, '://')) {
    $parsedHost = trim((string) parse_url($siteDomainRaw, PHP_URL_HOST));
    $parsedPort = parse_url($siteDomainRaw, PHP_URL_PORT);
    if ($parsedHost !== '') {
        $siteDomainRaw = $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
    }
}
$siteDomainRaw = preg_replace('/[\/?#].*$/', '', $siteDomainRaw) ?? $siteDomainRaw;
$siteDomainRaw = trim($siteDomainRaw);
if ($siteDomainRaw === '') {
    $siteDomainRaw = 'localhost';
}
$metaUrlPathPrefix = 'https://' . $siteDomainRaw . '/';

// Split configuration fields by top-level section so the editor can present tabbed panes.
$basicSiteConfigFields = [];
$basicPanelConfigFields = [];
$basicOtherConfigFields = [];
$captchaConfigFields = [];
$metaConfigFields = [];
$contentCategoriesConfigFields = [];
$contentTagsConfigFields = [];
$databaseConfigFields = [];
$databaseTablePrefixField = null;
$debugConfigFields = [];
$mediaUploadConfigFields = [];
$mediaImageSizeConfigFields = [];
$avatarConfigFields = [];
$sessionConfigFields = [];
foreach ($configFields as $field) {
    $path = (string) ($field['path'] ?? '');

    if (str_starts_with($path, 'meta.')) {
        $metaConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'session.')) {
        $sessionConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'captcha.')) {
        $captchaConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'categories.')) {
        $contentCategoriesConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'tags.')) {
        $contentTagsConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'database.')) {
        if ($path === 'database.table_prefix') {
            $databaseTablePrefixField = $field;
            continue;
        }

        $databaseConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'debug.')) {
        $debugConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'media.')) {
        if (str_starts_with($path, 'media.avatars.')) {
            $avatarConfigFields[] = $field;
            continue;
        }

        if (
            str_starts_with($path, 'media.images.small.')
            || str_starts_with($path, 'media.images.med.')
            || str_starts_with($path, 'media.images.large.')
        ) {
            $mediaImageSizeConfigFields[] = $field;
            continue;
        }

        $mediaUploadConfigFields[] = $field;
        continue;
    }

    if ($path === 'panel.path' || str_starts_with($path, 'panel.')) {
        $basicPanelConfigFields[] = $field;
        continue;
    }

    if ($path === 'site.default_theme' || str_starts_with($path, 'site.')) {
        $basicSiteConfigFields[] = $field;
        continue;
    }

    $basicOtherConfigFields[] = $field;
}

if ($debugConfigFields !== []) {
    $debugOrder = [
        'debug.show_on_public' => 10,
        'debug.show_on_panel' => 20,
        'debug.show_benchmarks' => 30,
        'debug.show_queries' => 40,
        'debug.show_stack_trace' => 50,
        'debug.show_request' => 60,
        'debug.show_environment' => 70,
    ];

    usort(
        $debugConfigFields,
        static function (array $left, array $right) use ($debugOrder): int {
            $leftPath = (string) ($left['path'] ?? '');
            $rightPath = (string) ($right['path'] ?? '');
            $leftRank = (int) ($debugOrder[$leftPath] ?? 1000);
            $rightRank = (int) ($debugOrder[$rightPath] ?? 1000);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp($leftPath, $rightPath);
        }
    );
}

$debugVisibilityConfigFields = [];
$debugSectionsConfigFields = [];
foreach ($debugConfigFields as $debugField) {
    $debugPath = (string) ($debugField['path'] ?? '');
    if (in_array($debugPath, ['debug.show_on_public', 'debug.show_on_panel'], true)) {
        $debugVisibilityConfigFields[] = $debugField;
        continue;
    }

    $debugSectionsConfigFields[] = $debugField;
}

if (is_array($databaseTablePrefixField)) {
    $databaseDriverIndex = null;
    foreach ($databaseConfigFields as $index => $databaseField) {
        if (((string) ($databaseField['path'] ?? '')) === 'database.driver') {
            $databaseDriverIndex = $index;
            break;
        }
    }

    if (is_int($databaseDriverIndex)) {
        array_splice($databaseConfigFields, $databaseDriverIndex + 1, 0, [$databaseTablePrefixField]);
    } else {
        array_unshift($databaseConfigFields, $databaseTablePrefixField);
    }
}

$metaSiteConfigFields = [];
$metaGeneralPropertyConfigFields = [];
$metaOpenGraphPropertyConfigFields = [];
$metaTwitterPropertyConfigFields = [];
foreach ($metaConfigFields as $metaField) {
    $metaPath = (string) ($metaField['path'] ?? '');
    if (str_starts_with($metaPath, 'meta.')) {
        if (str_starts_with($metaPath, 'meta.opengraph.')) {
            $metaOpenGraphPropertyConfigFields[] = $metaField;
            continue;
        }

        if (str_starts_with($metaPath, 'meta.twitter.')) {
            $metaTwitterPropertyConfigFields[] = $metaField;
            continue;
        }

        $metaGeneralPropertyConfigFields[] = $metaField;
        continue;
    }

    $metaSiteConfigFields[] = $metaField;
}

$sortMetaProperties = static function (array &$fields): void {
    usort(
        $fields,
        static function (array $left, array $right): int {
        $leftPath = strtolower((string) ($left['path'] ?? ''));
        $rightPath = strtolower((string) ($right['path'] ?? ''));
        $leftSegments = explode('.', $leftPath);
        $rightSegments = explode('.', $rightPath);
        $leftFamily = (string) ($leftSegments[1] ?? '');
        $rightFamily = (string) ($rightSegments[1] ?? '');

        if ($leftFamily === $rightFamily && in_array($leftFamily, ['twitter', 'opengraph'], true)) {
            $leftIsImage = ((string) ($leftSegments[2] ?? '')) === 'image';
            $rightIsImage = ((string) ($rightSegments[2] ?? '')) === 'image';
            if ($leftIsImage !== $rightIsImage) {
                return $leftIsImage ? 1 : -1;
            }
        }

        $leftLabel = strtolower((string) ($left['label'] ?? ''));
        $rightLabel = strtolower((string) ($right['label'] ?? ''));
        $labelCompare = $leftLabel <=> $rightLabel;
        if ($labelCompare !== 0) {
            return $labelCompare;
        }

            return $leftPath <=> $rightPath;
        }
    );
};

$sortMetaProperties($metaGeneralPropertyConfigFields);
$sortMetaProperties($metaOpenGraphPropertyConfigFields);
$sortMetaProperties($metaTwitterPropertyConfigFields);

$sessionGeneralConfigFields = [];
$sessionProfileConfigFields = [];
$sessionGroupConfigFields = [];
$sessionBruteForceConfigFields = [];
foreach ($sessionConfigFields as $sessionField) {
    $sessionPath = (string) ($sessionField['path'] ?? '');
    if (in_array($sessionPath, ['session.login_attempt_max', 'session.login_attempt_window_seconds', 'session.login_attempt_lock_seconds'], true)) {
        $sessionBruteForceConfigFields[] = $sessionField;
        continue;
    }

    if (in_array($sessionPath, ['session.profile_mode', 'session.profile_prefix'], true)) {
        $sessionProfileConfigFields[] = $sessionField;
        continue;
    }

    if (in_array($sessionPath, ['session.show_groups', 'session.group_prefix'], true)) {
        $sessionGroupConfigFields[] = $sessionField;
        continue;
    }

    $sessionGeneralConfigFields[] = $sessionField;
}

if ($sessionGeneralConfigFields !== []) {
    $sessionGeneralOrder = [
        'session.cookie_domain' => 10,
        'session.cookie_prefix' => 20,
        'session.name' => 30,
    ];

    usort(
        $sessionGeneralConfigFields,
        static function (array $left, array $right) use ($sessionGeneralOrder): int {
            $leftPath = (string) ($left['path'] ?? '');
            $rightPath = (string) ($right['path'] ?? '');
            $leftRank = (int) ($sessionGeneralOrder[$leftPath] ?? 1000);
            $rightRank = (int) ($sessionGeneralOrder[$rightPath] ?? 1000);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp($leftPath, $rightPath);
        }
    );
}

$isActiveConfigTab = static function (string $tabKey) use ($activeConfigTab): bool {
    return $activeConfigTab === $tabKey;
};

/**
 * Renders one configuration field row.
 *
 * @param array{
 *   path: string,
 *   segments: array<int, string>,
 *   label: string,
 *   type: string,
 *   value: string
 * } $field
 */
$renderConfigField = static function (array $field) use ($publicThemeOptions, $metaUrlPathPrefix): void {
    $path = (string) $field['path'];
    $segments = (array) $field['segments'];
    $type = (string) $field['type'];
    $fieldName = 'config_values';
    foreach ($segments as $segment) {
        $fieldName .= '[' . (string) $segment . ']';
    }
    $inputId = 'cfg_' . str_replace('.', '_', $path);
    $isDatabaseDriverField = $path === 'database.driver';
    $isCaptchaProviderField = $path === 'captcha.provider';
    $isMailAgentField = $path === 'mail.agent';
    $isSiteEnabledField = $path === 'site.enabled';
    $isPanelDefaultThemeField = $path === 'panel.default_theme';
    $isPublicDefaultThemeField = $path === 'site.default_theme';
    $isPublicProfilesModeField = $path === 'session.profile_mode';
    $isShowGroupsField = $path === 'session.show_groups';
    $isDebugCheckboxField = str_starts_with($path, 'debug.');
    $isImageUploadTargetField = $path === 'media.images.upload_target';
    $isNoLimitField = in_array($path, ['media.images.max_filesize_kb', 'media.images.max_files_per_upload', 'media.avatars.max_filesize_kb'], true);
    $isDomainPrefixedMetaPathField = in_array($path, ['meta.twitter.image', 'meta.apple_touch_icon', 'meta.opengraph.image', 'panel.brand_logo'], true);
    $isDbSpecificField = $path === 'database.table_prefix'
        || str_starts_with($path, 'database.sqlite.')
        || str_starts_with($path, 'database.mysql.')
        || str_starts_with($path, 'database.pgsql.');
    $isCaptchaSpecificField = str_starts_with($path, 'captcha.hcaptcha.')
        || str_starts_with($path, 'captcha.recaptcha.');
    $dbSection = '';
    $captchaSection = '';
    if ($path === 'database.table_prefix') {
        $dbSection = 'mysql,pgsql';
    } elseif (str_starts_with($path, 'database.sqlite.')) {
        $dbSection = 'sqlite';
    } elseif (str_starts_with($path, 'database.mysql.')) {
        $dbSection = 'mysql';
    } elseif (str_starts_with($path, 'database.pgsql.')) {
        $dbSection = 'pgsql';
    }
    if (str_starts_with($path, 'captcha.hcaptcha.')) {
        $captchaSection = 'hcaptcha';
    } elseif (str_starts_with($path, 'captcha.recaptcha.')) {
        $captchaSection = 'recaptcha';
    }
    $inputValue = (string) ($field['value'] ?? '');
    if ($isDomainPrefixedMetaPathField) {
        if (preg_match('/^https?:\/\//i', $inputValue) === 1) {
            $parsedPath = (string) parse_url($inputValue, PHP_URL_PATH);
            $parsedQuery = (string) parse_url($inputValue, PHP_URL_QUERY);
            $parsedFragment = (string) parse_url($inputValue, PHP_URL_FRAGMENT);
            $inputValue = ltrim($parsedPath, '/');
            if ($parsedQuery !== '') {
                $inputValue .= '?' . $parsedQuery;
            }
            if ($parsedFragment !== '') {
                $inputValue .= '#' . $parsedFragment;
            }
        } else {
            $inputValue = ltrim($inputValue, '/');
        }
    }
    $isRequired = in_array($path, ['site.domain', 'panel.path', 'site.enabled', 'database.driver', 'captcha.provider', 'mail.agent', 'panel.default_theme', 'site.default_theme', 'session.name', 'session.profile_mode', 'session.show_groups'], true);
    $disableUriNote = match ($path) {
        'categories.prefix' => ' (leave blank to disable category URIs)',
        'tags.prefix' => ' (leave blank to disable tag URIs)',
        'session.profile_prefix' => ' (leave blank to disable profile URIs)',
        'session.group_prefix' => ' (leave blank to disable member list URIs)',
        default => '',
    };
    ?>
    <div
        class="mb-3"
        data-raven-config-row="1"
        data-raven-config-path="<?= e($path) ?>"
        <?= $isDbSpecificField ? 'data-raven-db-section="' . e($dbSection) . '"' : '' ?>
        <?= $isCaptchaSpecificField ? 'data-raven-captcha-section="' . e($captchaSection) . '"' : '' ?>
    >
        <?php if (!$isDebugCheckboxField): ?>
            <label class="form-label" for="<?= e($inputId) ?>"><?= e((string) $field['label']) ?></label>
        <?php endif; ?>
        <?php if ($isDebugCheckboxField): ?>
            <input type="hidden" name="<?= e($fieldName) ?>" value="false">
            <div class="form-check">
                <input
                    type="checkbox"
                    class="form-check-input"
                    id="<?= e($inputId) ?>"
                    name="<?= e($fieldName) ?>"
                    value="true"
                    <?= (string) ($field['value'] ?? '') === 'true' ? 'checked' : '' ?>
                >
                <label class="form-check-label" for="<?= e($inputId) ?>"><?= e((string) $field['label']) ?></label>
            </div>
        <?php elseif ($isDatabaseDriverField): ?>
            <!-- Driver selector controls visible DB-specific config inputs below. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                data-raven-db-driver-select="1"
                required
            >
                <option value="sqlite"<?= (string) $field['value'] === 'sqlite' ? ' selected' : '' ?>>sqlite</option>
                <option value="mysql"<?= (string) $field['value'] === 'mysql' ? ' selected' : '' ?>>mysql</option>
                <option value="pgsql"<?= (string) $field['value'] === 'pgsql' ? ' selected' : '' ?>>pgsql</option>
            </select>
        <?php elseif ($isCaptchaProviderField): ?>
            <!-- Captcha provider selector controls visible provider-specific key fields below. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                data-raven-captcha-provider-select="1"
                required
            >
                <option value="none"<?= (string) $field['value'] === 'none' ? ' selected' : '' ?>>none</option>
                <option value="hcaptcha"<?= (string) $field['value'] === 'hcaptcha' ? ' selected' : '' ?>>hcaptcha</option>
                <option value="recaptcha"<?= (string) $field['value'] === 'recaptcha' ? ' selected' : '' ?>>recaptcha</option>
            </select>
        <?php elseif ($isMailAgentField): ?>
            <!-- Mail agent is an explicit dropdown so supported drivers stay constrained. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="php_mail"<?= (string) $field['value'] === 'php_mail' ? ' selected' : '' ?>>php_mail</option>
            </select>
        <?php elseif ($isSiteEnabledField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="public"<?= (string) $field['value'] === 'public' ? ' selected' : '' ?>>Public</option>
                <option value="private"<?= (string) $field['value'] === 'private' ? ' selected' : '' ?>>Private</option>
                <option value="disabled"<?= (string) $field['value'] === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
        <?php elseif ($isPanelDefaultThemeField): ?>
            <!-- Default panel theme is constrained to the supported light/dark modes. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="light"<?= (string) $field['value'] === 'light' ? ' selected' : '' ?>>light</option>
                <option value="dark"<?= (string) $field['value'] === 'dark' ? ' selected' : '' ?>>dark</option>
            </select>
        <?php elseif ($isPublicDefaultThemeField): ?>
            <!-- Public theme options are generated from theme manifest folders on disk. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <?php foreach ($publicThemeOptions as $themeSlug => $themeName): ?>
                    <option value="<?= e((string) $themeSlug) ?>"<?= (string) $field['value'] === (string) $themeSlug ? ' selected' : '' ?>>
                        <?= e((string) $themeName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($isImageUploadTargetField): ?>
            <!-- Keep upload-target as explicit dropdown for forward-compatible storage backends. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="local"<?= (string) $field['value'] === 'local' ? ' selected' : '' ?>>local</option>
            </select>
        <?php elseif ($isPublicProfilesModeField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="public_full"<?= (string) $field['value'] === 'public_full' ? ' selected' : '' ?>>Public Full</option>
                <option value="public_limited"<?= (string) $field['value'] === 'public_limited' ? ' selected' : '' ?>>Public Limited</option>
                <option value="private"<?= (string) $field['value'] === 'private' ? ' selected' : '' ?>>Private</option>
                <option value="disabled"<?= (string) $field['value'] === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
        <?php elseif ($isShowGroupsField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="public"<?= (string) $field['value'] === 'public' ? ' selected' : '' ?>>Public</option>
                <option value="private"<?= (string) $field['value'] === 'private' ? ' selected' : '' ?>>Private</option>
                <option value="disabled"<?= (string) $field['value'] === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
        <?php elseif ($isDomainPrefixedMetaPathField): ?>
            <div class="input-group">
                <span class="input-group-text font-monospace"><?= e($metaUrlPathPrefix) ?></span>
                <input
                    type="text"
                    class="form-control font-monospace"
                    id="<?= e($inputId) ?>"
                    name="<?= e($fieldName) ?>"
                    value="<?= e($inputValue) ?>"
                    <?= $isRequired ? 'required' : '' ?>
                >
            </div>
        <?php elseif ($type === 'bool'): ?>
            <!-- Boolean fields use an explicit true/false selector for safer editing. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                <?= $isRequired ? 'required' : '' ?>
            >
                <option value="true"<?= (string) $field['value'] === 'true' ? ' selected' : '' ?>>true</option>
                <option value="false"<?= (string) $field['value'] === 'false' ? ' selected' : '' ?>>false</option>
            </select>
        <?php else: ?>
            <input
                type="text"
                class="form-control font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                value="<?= e($inputValue) ?>"
                <?= $isRequired ? 'required' : '' ?>
            >
        <?php endif; ?>
        <div class="form-text">
            <code><?= e($path) ?></code> (<?= e($type) ?>)<?= $isNoLimitField ? ' (0 for no limit)' : '' ?><?= $disableUriNote ?>
        </div>
    </div>
    <?php
};

/**
 * Renders one tab's field list grouped by top-level key.
 *
 * @param array<int, array{
 *   path: string,
 *   segments: array<int, string>,
 *   label: string,
 *   type: string,
 *   value: string
 * }> $fields
 */
$renderConfigFieldGroup = static function (array $fields) use ($renderConfigField): void {
    if ($fields === []) {
        ?>
        <p class="text-muted mb-0">No configuration fields available.</p>
        <?php
        return;
    }

    $currentGroup = null;
    foreach ($fields as $field) {
        $path = (string) ($field['path'] ?? '');
        $group = (string) (explode('.', $path)[0] ?? 'general');
        $groupLabel = $group === 'media' ? 'Upload Settings' : $group;

        if ($group !== $currentGroup) {
            if ($currentGroup !== null) {
                ?>
                <hr class="my-4">
                <?php
            }
            ?>
            <h2 class="h6 text-uppercase mb-3"><?= e($groupLabel) ?></h2>
            <?php
            $currentGroup = $group;
        }

        $renderConfigField($field);
    }
};
?>
<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if ($section === 'dashboard'): ?>
    <div class="card">
        <div class="card-body">
            <h1>Dashboard</h1>
            <p class="mb-1">Logged in as: <strong><?= e((string) ($user['email'] ?? 'unknown')) ?></strong></p>
            <p class="text-muted">Welcome to <b>Raven CMS</b>. Use the navigation to browse your system. Full dashboard coming soon.</p>
        </div>
    </div>
<?php elseif ($section === 'configuration'): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h1 class="mb-0">System Configuration</h1>
            <p class="text-muted mt-2 mb-0">Manage site, database, debug, media, meta, and user/session runtime settings.</p>
        </div>
    </div>

    <?php if (!$canManageConfiguration): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-danger mb-0">Manage System Configuration permission is required for this section.</p>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e($panelBase) ?>/configuration/save">
            <?= $csrfField ?>
            <input type="hidden" name="_config_tab" id="config-active-tab" value="<?= e($activeConfigTab) ?>">

            <div class="d-flex justify-content-end mb-3">
                <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Configuration</button>
            </div>

            <ul class="nav nav-tabs" id="configEditorTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('basic') ? ' active' : '' ?>"
                                id="config-basic-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-basic-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-basic-pane"
                                aria-selected="<?= $isActiveConfigTab('basic') ? 'true' : 'false' ?>"
                            >
                                Basic
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('content') ? ' active' : '' ?>"
                                id="config-content-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-content-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-content-pane"
                                aria-selected="<?= $isActiveConfigTab('content') ? 'true' : 'false' ?>"
                            >
                                Content
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('database') ? ' active' : '' ?>"
                                id="config-database-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-database-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-database-pane"
                                aria-selected="<?= $isActiveConfigTab('database') ? 'true' : 'false' ?>"
                            >
                                Database
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('debug') ? ' active' : '' ?>"
                                id="config-debug-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-debug-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-debug-pane"
                                aria-selected="<?= $isActiveConfigTab('debug') ? 'true' : 'false' ?>"
                            >
                                Debug
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('media') ? ' active' : '' ?>"
                                id="config-media-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-media-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-media-pane"
                                aria-selected="<?= $isActiveConfigTab('media') ? 'true' : 'false' ?>"
                            >
                                Media
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('meta') ? ' active' : '' ?>"
                                id="config-meta-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-meta-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-meta-pane"
                                aria-selected="<?= $isActiveConfigTab('meta') ? 'true' : 'false' ?>"
                            >
                                Meta
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link<?= $isActiveConfigTab('session') ? ' active' : '' ?>"
                                id="config-session-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#config-session-pane"
                                type="button"
                                role="tab"
                                aria-controls="config-session-pane"
                                aria-selected="<?= $isActiveConfigTab('session') ? 'true' : 'false' ?>"
                            >
                                Users
                            </button>
                        </li>
                    </ul>

            <div class="tab-content raven-tab-content-surface border border-top-0 rounded-bottom p-3" id="configEditorTabsContent">
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('basic') ? ' show active' : '' ?>"
                            id="config-basic-pane"
                            role="tabpanel"
                            aria-labelledby="config-basic-tab"
                            tabindex="0"
                        >
                            <?php if ($basicSiteConfigFields === [] && $basicPanelConfigFields === [] && $basicOtherConfigFields === [] && $captchaConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php $hasBasicSections = false; ?>

                                <?php if ($basicSiteConfigFields !== []): ?>
                                    <h2 class="h6 text-uppercase mb-3">Site</h2>
                                    <?php foreach ($basicSiteConfigFields as $siteField): ?>
                                        <?php $renderConfigField($siteField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasBasicSections = true; ?>
                                <?php endif; ?>

                                <?php if ($basicPanelConfigFields !== []): ?>
                                    <?php if ($hasBasicSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Panel</h2>
                                    <?php foreach ($basicPanelConfigFields as $panelField): ?>
                                        <?php $renderConfigField($panelField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasBasicSections = true; ?>
                                <?php endif; ?>

                                <?php if ($basicOtherConfigFields !== []): ?>
                                    <?php if ($hasBasicSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <?php $renderConfigFieldGroup($basicOtherConfigFields); ?>
                                    <?php $hasBasicSections = true; ?>
                                <?php endif; ?>

                                <?php if ($captchaConfigFields !== []): ?>
                                    <?php if ($hasBasicSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Captcha</h2>
                                    <?php foreach ($captchaConfigFields as $captchaField): ?>
                                        <?php $renderConfigField($captchaField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('content') ? ' show active' : '' ?>"
                            id="config-content-pane"
                            role="tabpanel"
                            aria-labelledby="config-content-tab"
                            tabindex="0"
                        >
                            <?php if ($contentCategoriesConfigFields === [] && $contentTagsConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($contentCategoriesConfigFields !== []): ?>
                                    <h2 class="h6 text-uppercase mb-3">Categories</h2>
                                    <?php foreach ($contentCategoriesConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($contentTagsConfigFields !== []): ?>
                                    <?php if ($contentCategoriesConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Tags</h2>
                                    <?php foreach ($contentTagsConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('database') ? ' show active' : '' ?>"
                            id="config-database-pane"
                            role="tabpanel"
                            aria-labelledby="config-database-tab"
                            tabindex="0"
                        >
                            <?php $renderConfigFieldGroup($databaseConfigFields); ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('debug') ? ' show active' : '' ?>"
                            id="config-debug-pane"
                            role="tabpanel"
                            aria-labelledby="config-debug-tab"
                            tabindex="0"
                        >
                            <?php if ($debugConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($debugVisibilityConfigFields !== []): ?>
                                    <h2 class="h6 text-uppercase mb-3">Toolbar Visibility</h2>
                                    <?php foreach ($debugVisibilityConfigFields as $debugField): ?>
                                        <?php $renderConfigField($debugField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($debugSectionsConfigFields !== []): ?>
                                    <?php if ($debugVisibilityConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Expanded Profiler Sections</h2>
                                    <?php foreach ($debugSectionsConfigFields as $debugField): ?>
                                        <?php $renderConfigField($debugField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('media') ? ' show active' : '' ?>"
                            id="config-media-pane"
                            role="tabpanel"
                            aria-labelledby="config-media-tab"
                            tabindex="0"
                        >
                            <?php if ($mediaUploadConfigFields === [] && $mediaImageSizeConfigFields === [] && $avatarConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($mediaUploadConfigFields !== []): ?>
                                    <h2 class="h6 text-uppercase mb-3">Upload Settings</h2>
                                    <?php foreach ($mediaUploadConfigFields as $mediaField): ?>
                                        <?php $renderConfigField($mediaField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($mediaImageSizeConfigFields !== []): ?>
                                    <?php if ($mediaUploadConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Image Sizes</h2>
                                    <?php foreach ($mediaImageSizeConfigFields as $imageSizeField): ?>
                                        <?php $renderConfigField($imageSizeField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($avatarConfigFields !== []): ?>
                                    <?php if ($mediaUploadConfigFields !== [] || $mediaImageSizeConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Avatar Settings</h2>
                                    <?php foreach ($avatarConfigFields as $avatarField): ?>
                                        <?php $renderConfigField($avatarField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('meta') ? ' show active' : '' ?>"
                            id="config-meta-pane"
                            role="tabpanel"
                            aria-labelledby="config-meta-tab"
                            tabindex="0"
                        >
                            <?php if ($metaSiteConfigFields === [] && $metaGeneralPropertyConfigFields === [] && $metaOpenGraphPropertyConfigFields === [] && $metaTwitterPropertyConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($metaSiteConfigFields !== []): ?>
                                    <?php $renderConfigFieldGroup($metaSiteConfigFields); ?>
                                <?php endif; ?>

                                <?php if ($metaGeneralPropertyConfigFields !== []): ?>
                                    <?php if ($metaSiteConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Meta Properties</h2>
                                    <?php foreach ($metaGeneralPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($metaOpenGraphPropertyConfigFields !== []): ?>
                                    <?php if ($metaSiteConfigFields !== [] || $metaGeneralPropertyConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">OpenGraph Properties</h2>
                                    <?php foreach ($metaOpenGraphPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($metaTwitterPropertyConfigFields !== []): ?>
                                    <?php if ($metaSiteConfigFields !== [] || $metaGeneralPropertyConfigFields !== [] || $metaOpenGraphPropertyConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Twitter Card Properties</h2>
                                    <?php foreach ($metaTwitterPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('session') ? ' show active' : '' ?>"
                            id="config-session-pane"
                            role="tabpanel"
                            aria-labelledby="config-session-tab"
                            tabindex="0"
                        >
                            <?php if ($sessionGeneralConfigFields === [] && $sessionProfileConfigFields === [] && $sessionGroupConfigFields === [] && $sessionBruteForceConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($sessionGeneralConfigFields !== []): ?>
                                    <h2 class="h6 text-uppercase mb-3">Cookie Settings</h2>
                                    <?php foreach ($sessionGeneralConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionProfileConfigFields !== []): ?>
                                    <?php if ($sessionGeneralConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Profile Options</h2>
                                    <?php foreach ($sessionProfileConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionGroupConfigFields !== []): ?>
                                    <?php if ($sessionGeneralConfigFields !== [] || $sessionProfileConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Group Options</h2>
                                    <?php foreach ($sessionGroupConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionBruteForceConfigFields !== []): ?>
                                    <?php if ($sessionGeneralConfigFields !== [] || $sessionProfileConfigFields !== [] || $sessionGroupConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h2 class="h6 text-uppercase mb-3">Brute Force Protection</h2>
                                    <?php foreach ($sessionBruteForceConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Configuration</button>
            </div>
        </form>
        <script>
          // Shows only config fields for the selected DB driver.
          (function () {
            var driverSelect = document.querySelector('[data-raven-db-driver-select="1"]');
            var captchaProviderSelect = document.querySelector('[data-raven-captcha-provider-select="1"]');
            var activeTabInput = document.getElementById('config-active-tab');
            var configForm = activeTabInput instanceof HTMLInputElement ? activeTabInput.form : null;

            function syncDatabaseRows() {
              if (!(driverSelect instanceof HTMLSelectElement)) {
                return;
              }

              var selected = String(driverSelect.value || '').toLowerCase();

              document.querySelectorAll('[data-raven-db-section]').forEach(function (row) {
                if (!(row instanceof HTMLElement)) {
                  return;
                }

                var section = String(row.getAttribute('data-raven-db-section') || '').toLowerCase();
                var allowed = section.split(',').map(function (part) {
                  return String(part || '').trim();
                }).filter(function (part) {
                  return part !== '';
                });
                var show = allowed.indexOf(selected) !== -1;
                row.classList.toggle('d-none', !show);
              });
            }

            function syncCaptchaRows() {
              if (!(captchaProviderSelect instanceof HTMLSelectElement)) {
                return;
              }

              var selected = String(captchaProviderSelect.value || '').toLowerCase();

              document.querySelectorAll('[data-raven-captcha-section]').forEach(function (row) {
                if (!(row instanceof HTMLElement)) {
                  return;
                }

                var section = String(row.getAttribute('data-raven-captcha-section') || '');
                var show = section === selected;
                row.classList.toggle('d-none', !show);
              });
            }

            function tabKeyFromButton(button) {
              if (!(button instanceof HTMLElement)) {
                return 'basic';
              }

              var controls = String(button.getAttribute('aria-controls') || '');
              var match = controls.match(/^config-(basic|content|database|debug|media|meta|session)-pane$/);
              return match ? String(match[1]) : 'basic';
            }

            function syncActiveTabHiddenFieldFromDom() {
              if (!(activeTabInput instanceof HTMLInputElement)) {
                return;
              }

              var activeButton = document.querySelector('#configEditorTabs button.nav-link.active[data-bs-toggle="tab"]');
              if (!(activeButton instanceof HTMLElement)) {
                return;
              }

              activeTabInput.value = tabKeyFromButton(activeButton);
            }

            if (driverSelect instanceof HTMLSelectElement) {
              driverSelect.addEventListener('change', syncDatabaseRows);
            }
            syncDatabaseRows();

            if (captchaProviderSelect instanceof HTMLSelectElement) {
              captchaProviderSelect.addEventListener('change', syncCaptchaRows);
            }
            syncCaptchaRows();

            document.querySelectorAll('#configEditorTabs button[data-bs-toggle="tab"]').forEach(function (button) {
              button.addEventListener('shown.bs.tab', function (event) {
                if (!(activeTabInput instanceof HTMLInputElement)) {
                  return;
                }

                var target = event.target;
                if (!(target instanceof HTMLElement)) {
                  return;
                }

                activeTabInput.value = tabKeyFromButton(target);
              });
            });

            if (configForm instanceof HTMLFormElement) {
              configForm.addEventListener('submit', function () {
                syncActiveTabHiddenFieldFromDom();
              });
            }

            syncActiveTabHiddenFieldFromDom();
          })();
        </script>
    <?php endif; ?>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <h1 class="text-capitalize"><?= e($section) ?></h1>
            <p class="text-muted mb-0">This section is scaffolded and will be implemented in the next pass.</p>
            <?php if (($section === 'users' && !$canManageUsers) || ($section === 'groups' && !$canManageGroups)): ?>
                <p class="text-danger mt-2 mb-0">Manage Users or Manage Groups permission is required for this section.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
