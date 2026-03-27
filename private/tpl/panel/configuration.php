<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/configuration.php
 * Admin panel configuration view template.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var bool $canManageConfiguration */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */
/** @var array<string, mixed>|null $configSnapshot */
/** @var array<int, array{
 *   path: string,
 *   segments: array<int, string>,
 *   label: string,
 *   type: string,
 *   value: string
 * }>|null $configFields */
/** @var array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}>|null $channelOptions */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}>|null $categorySetOptions */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}>|null $tagSetOptions */
/** @var string|null $activeConfigTab */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$configSnapshot = $configSnapshot ?? null;
$configFields = $configFields ?? [];
$channelOptions = is_array($channelOptions ?? null) ? $channelOptions : [];
$categorySetOptions = is_array($categorySetOptions ?? null) ? $categorySetOptions : [];
$tagSetOptions = is_array($tagSetOptions ?? null) ? $tagSetOptions : [];
$activeConfigTab = strtolower(trim((string) ($activeConfigTab ?? 'basic')));
if (!in_array($activeConfigTab, ['basic', 'content', 'database', 'debug', 'media', 'meta', 'security', 'users'], true)) {
    $activeConfigTab = 'basic';
}
$selectedFeedChannels = $configSnapshot['feed']['channels'] ?? null;
if (!is_array($selectedFeedChannels)) {
    $selectedFeedChannels = ['all'];
}
$selectedFeedChannels = array_values(array_filter(
    array_map(
        static fn (mixed $channel): string => strtolower(trim((string) $channel)),
        $selectedFeedChannels
    ),
    static fn (string $channel): bool => $channel !== ''
));
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
$siteProtocolRaw = strtolower(trim((string) (($configSnapshot['site']['protocol'] ?? 'https'))));
if (!in_array($siteProtocolRaw, ['http', 'https'], true)) {
    $siteProtocolRaw = 'https';
}
$metaUrlPathPrefix = $siteProtocolRaw . '://' . $siteDomainRaw . '/';

// Split configuration fields by top-level section so the editor can present tabbed panes.
$basicSiteConfigFields = [];
$basicPanelConfigFields = [];
$basicOtherConfigFields = [];
$captchaConfigFields = [];
$metaConfigFields = [];
$contentGeneralConfigFields = [];
$contentFeedsConfigFields = [];
$contentCategoriesConfigFields = [];
$contentTagsConfigFields = [];
$databaseConfigFields = [];
$databaseTablePrefixField = null;
$debugConfigFields = [];
$mediaUploadConfigFields = [];
$mediaImageSizeConfigFields = [];
$avatarConfigFields = [];
$sessionConfigFields = [];
$userConfigFields = [];
$groupConfigFields = [];
foreach ($configFields as $field) {
    $path = (string) ($field['path'] ?? '');

    if (str_starts_with($path, 'update.')) {
        continue;
    }

    if (str_starts_with($path, 'meta.')) {
        $metaConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'session.')) {
        $sessionConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'user.')) {
        $userConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'group.')) {
        $groupConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'captcha.')) {
        $captchaConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'content.')) {
        $contentGeneralConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'feed.')) {
        $contentFeedsConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'category.')) {
        $contentCategoriesConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'tag.')) {
        $contentTagsConfigFields[] = $field;
        continue;
    }

    if (str_starts_with($path, 'database.')) {
        if ($path === 'database.prefix') {
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

    if (str_starts_with($path, 'site.')) {
        $basicSiteConfigFields[] = $field;
        continue;
    }

    $basicOtherConfigFields[] = $field;
}

if ($basicSiteConfigFields !== []) {
    $basicSiteOrder = [
        'site.name' => 10,
        'site.domain' => 20,
        'site.protocol' => 30,
        'site.enabled' => 40,
    ];

    usort(
        $basicSiteConfigFields,
        static function (array $left, array $right) use ($basicSiteOrder): int {
            $leftPath = (string) ($left['path'] ?? '');
            $rightPath = (string) ($right['path'] ?? '');
            $leftRank = (int) ($basicSiteOrder[$leftPath] ?? 1000);
            $rightRank = (int) ($basicSiteOrder[$rightPath] ?? 1000);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp($leftPath, $rightPath);
        }
    );
}

if ($debugConfigFields !== []) {
    $debugOrder = [
        'debug.show_public' => 10,
        'debug.show_private' => 20,
        'debug.show_benchmarks' => 30,
        'debug.show_queries' => 40,
        'debug.show_trace' => 50,
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
    if (in_array($debugPath, ['debug.show_public', 'debug.show_private'], true)) {
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

$sessionCookieConfigFields = [];
$sessionLoginConfigFields = [];
$sessionProfileConfigFields = [];
$sessionGroupConfigFields = [];
$sessionBruteForceConfigFields = [];
foreach ($sessionConfigFields as $sessionField) {
    $sessionPath = (string) ($sessionField['path'] ?? '');
    if (in_array($sessionPath, ['session.brute.max', 'session.brute.window', 'session.brute.lock'], true)) {
        $sessionBruteForceConfigFields[] = $sessionField;
        continue;
    }

    $sessionCookieConfigFields[] = $sessionField;
}
foreach ($userConfigFields as $userField) {
    $userPath = (string) ($userField['path'] ?? '');
    if (in_array($userPath, ['user.auth.login', 'user.auth.registration'], true)) {
        $sessionLoginConfigFields[] = $userField;
        continue;
    }

    if (in_array($userPath, ['user.privacy', 'user.prefix'], true)) {
        $sessionProfileConfigFields[] = $userField;
        continue;
    }

    if (str_starts_with($userPath, 'user.contact.')) {
        continue;
    }

    $sessionCookieConfigFields[] = $userField;
}
foreach ($groupConfigFields as $groupField) {
    $groupPath = (string) ($groupField['path'] ?? '');
    if (in_array($groupPath, ['group.privacy', 'group.prefix'], true)) {
        $sessionGroupConfigFields[] = $groupField;
        continue;
    }

    $sessionCookieConfigFields[] = $groupField;
}
if ($sessionLoginConfigFields !== []) {
    $sessionLoginOrder = [
        'user.auth.registration' => 10,
        'user.auth.login' => 20,
    ];
    usort(
        $sessionLoginConfigFields,
        static function (array $left, array $right) use ($sessionLoginOrder): int {
            $leftPath = (string) ($left['path'] ?? '');
            $rightPath = (string) ($right['path'] ?? '');
            $leftRank = (int) ($sessionLoginOrder[$leftPath] ?? 1000);
            $rightRank = (int) ($sessionLoginOrder[$rightPath] ?? 1000);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp($leftPath, $rightPath);
        }
    );
}

$profileContactOptionRows = [];
$protectedProfileContactOptionTypes = ['email', 'phone', 'homepage', 'x'];
$rawProfileContactOptions = $configSnapshot['user']['contact'] ?? null;
if (is_array($rawProfileContactOptions)) {
    foreach ($rawProfileContactOptions as $optionType => $optionConfig) {
        if (!is_string($optionType) && !is_int($optionType)) {
            continue;
        }

        $normalizedType = strtolower(trim((string) $optionType));
        if ($normalizedType === '' || preg_match('/^[a-z0-9-]{1,80}$/', $normalizedType) !== 1) {
            continue;
        }

        $label = '';
        $urlPrefix = '';
        if (is_array($optionConfig)) {
            $label = trim((string) ($optionConfig['label'] ?? ''));
            $urlPrefix = trim((string) ($optionConfig['url_prefix'] ?? ''));
        } else {
            $label = trim((string) $optionConfig);
        }
        if ($label === '') {
            continue;
        }

        $profileContactOptionRows[] = [
            'type' => $normalizedType,
            'label' => $label,
            'url_prefix' => $urlPrefix,
        ];

        if (count($profileContactOptionRows) >= 100) {
            break;
        }
    }
}

if ($sessionCookieConfigFields !== []) {
    $sessionGeneralOrder = [
        'session.cookie.domain' => 10,
        'session.cookie.prefix' => 20,
        'session.cookie.name' => 30,
    ];

    usort(
        $sessionCookieConfigFields,
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
$renderConfigField = static function (array $field) use (
    $metaUrlPathPrefix,
    $channelOptions,
    $selectedFeedChannels,
    $categorySetOptions,
    $tagSetOptions
): void {
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
    $isEditorDefaultField = $path === 'content.editor';
    $isRouteModeDefaultField = $path === 'content.mode';
    $isRouteSeparatorDefaultField = $path === 'content.separator';
    $isFeedsChannelField = $path === 'feed.channels';
    $isCategoryDefaultSetField = $path === 'category.set';
    $isTagDefaultSetField = $path === 'tag.set';
    $isSiteEnabledField = $path === 'site.enabled';
    $isSiteProtocolField = $path === 'site.protocol';
    $isPanelDefaultThemeField = $path === 'panel.theme';
    $isPublicProfilesModeField = $path === 'user.privacy';
    $isShowGroupsField = $path === 'group.privacy';
    $isUserLoginIdentifierField = $path === 'user.auth.login';
    $isUserRegistrationModeField = $path === 'user.auth.registration';
    $isDatabasePasswordField = in_array($path, ['database.mysql.pass', 'database.pgsql.pass'], true);
    $isBooleanCheckboxField = $type === 'bool';
    $isDebugCheckboxField = str_starts_with($path, 'debug.');
    $isImageUploadTargetField = $path === 'media.images.upload_target';
    $isNoLimitField = in_array($path, ['media.images.max_filesize_kb', 'media.images.max_files_per_upload', 'media.avatars.max_filesize_kb'], true);
    $isDomainPrefixedMetaPathField = in_array($path, ['meta.image', 'meta.apple_touch_icon', 'panel.brand_logo'], true);
    $isDbSpecificField = $path === 'database.prefix'
        || str_starts_with($path, 'database.sqlite.')
        || str_starts_with($path, 'database.mysql.')
        || str_starts_with($path, 'database.pgsql.');
    $isCaptchaSpecificField = str_starts_with($path, 'captcha.hcaptcha.')
        || str_starts_with($path, 'captcha.recaptcha2.')
        || str_starts_with($path, 'captcha.recaptcha3.');
    $dbSection = '';
    $captchaSection = '';
    if ($path === 'database.prefix') {
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
    } elseif (str_starts_with($path, 'captcha.recaptcha2.')) {
        $captchaSection = 'recaptcha2';
    } elseif (str_starts_with($path, 'captcha.recaptcha3.')) {
        $captchaSection = 'recaptcha3';
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
    $isRequired = in_array($path, ['site.domain', 'site.protocol', 'panel.path', 'site.enabled', 'database.driver', 'captcha.provider', 'mail.agent', 'content.editor', 'content.mode', 'content.separator', 'panel.theme', 'session.cookie.name', 'user.privacy', 'group.privacy', 'user.auth.login', 'user.auth.registration'], true);
    $disableUriNote = match ($path) {
        'feed.rss' => ' (leave blank to disable RSS feeds)',
        'feed.atom' => ' (leave blank to disable Atom feeds)',
        'category.prefix' => ' (leave blank to disable category URLs)',
        'tag.prefix' => ' (leave blank to disable tag URLs)',
        'user.prefix' => ' (leave blank to disable profile URLs)',
        'group.prefix' => ' (leave blank to disable member list URLs)',
        default => '',
    };
    ?>
    <div
        class="form-group"
        data-rvn-config-row="1"
        data-rvn-config-path="<?= e($path) ?>"
        <?= $isDbSpecificField ? 'data-rvn-db-section="' . e($dbSection) . '"' : '' ?>
        <?= $isCaptchaSpecificField ? 'data-rvn-captcha-section="' . e($captchaSection) . '"' : '' ?>
    >
        <?php if (!$isBooleanCheckboxField): ?>
            <label class="form-label" for="<?= e($isFeedsChannelField ? $inputId . '_all' : $inputId) ?>"><?= e((string) $field['label']) ?></label>
        <?php endif; ?>
        <?php if ($isBooleanCheckboxField): ?>
            <input type="hidden" name="<?= e($fieldName) ?>" value="false">
            <div class="form-check form-switch">
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
                data-rvn-db-driver-select="1"
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
                data-rvn-captcha-provider-select="1"
                required
            >
                <option value="none"<?= (string) $field['value'] === 'none' ? ' selected' : '' ?>>none</option>
                <option value="hcaptcha"<?= (string) $field['value'] === 'hcaptcha' ? ' selected' : '' ?>>hcaptcha</option>
                <option value="recaptcha2"<?= (string) $field['value'] === 'recaptcha2' ? ' selected' : '' ?>>recaptcha2</option>
                <option value="recaptcha3"<?= (string) $field['value'] === 'recaptcha3' ? ' selected' : '' ?>>recaptcha3</option>
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
        <?php elseif ($isEditorDefaultField): ?>
            <!-- Editor default is constrained to installed core editor drivers. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="tinymce"<?= (string) $field['value'] === 'tinymce' ? ' selected' : '' ?>>tinymce</option>
                <option value="plaintext"<?= (string) $field['value'] === 'plaintext' ? ' selected' : '' ?>>plaintext</option>
                <option value="autobr"<?= (string) $field['value'] === 'autobr' ? ' selected' : '' ?>>auto &lt;br&gt;</option>
                <option value="markdown"<?= (string) $field['value'] === 'markdown' ? ' selected' : '' ?>>markdown</option>
            </select>
        <?php elseif ($isRouteModeDefaultField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="slug"<?= (string) $field['value'] === 'slug' ? ' selected' : '' ?>>/{slug}</option>
                <option value="id"<?= (string) $field['value'] === 'id' ? ' selected' : '' ?>>/{id}</option>
            </select>
        <?php elseif ($isRouteSeparatorDefaultField): ?>
            <!-- Route separator controls generated root and channel page route segments. -->
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="-"<?= (string) $field['value'] === '-' ? ' selected' : '' ?>>- (hyphen)</option>
                <option value="_"<?= (string) $field['value'] === '_' ? ' selected' : '' ?>>_ (underscore)</option>
            </select>
        <?php elseif ($isFeedsChannelField): ?>
            <?php $feedChannelFieldName = $fieldName . '[]'; ?>
            <?php $allChannelsSelected = in_array('all', $selectedFeedChannels, true); ?>
            <div class="border rounded p-3" id="<?= e($inputId) ?>" data-rvn-feed-channels="1">
                <div class="form-check mb-2">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        id="<?= e($inputId) ?>_all"
                        name="<?= e($feedChannelFieldName) ?>"
                        value="all"
                        data-rvn-feed-channel-all="1"
                        <?= $allChannelsSelected ? 'checked' : '' ?>
                    >
                    <label class="form-check-label fw-bold" for="<?= e($inputId) ?>_all">All Channels</label>
                </div>
                <?php foreach ($channelOptions as $channelOption): ?>
                    <?php $channelSlug = strtolower(trim((string) ($channelOption['slug'] ?? ''))); ?>
                    <?php if ($channelSlug === ''): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php $channelName = trim((string) ($channelOption['name'] ?? '')); ?>
                    <?php if ($channelSlug === 'root'): ?>
                        <?php $channelName = 'Root'; ?>
                    <?php endif; ?>
                    <?php $channelChecked = $allChannelsSelected || in_array($channelSlug, $selectedFeedChannels, true); ?>
                    <div class="form-check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="<?= e($inputId) ?>_<?= e($channelSlug) ?>"
                            name="<?= e($feedChannelFieldName) ?>"
                            value="<?= e($channelSlug) ?>"
                            data-rvn-feed-channel-item="1"
                            <?= $channelChecked ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="<?= e($inputId) ?>_<?= e($channelSlug) ?>">
                            <?= e($channelName !== '' ? $channelName : $channelSlug) ?> (<?= e($channelSlug) ?>)
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($isCategoryDefaultSetField || $isTagDefaultSetField): ?>
            <?php $setOptions = $isTagDefaultSetField ? $tagSetOptions : $categorySetOptions; ?>
            <?php $selectedSetId = max(1, (int) ($field['value'] ?? '1')); ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <?php foreach ($setOptions as $setOption): ?>
                    <?php $setId = (int) ($setOption['id'] ?? 0); ?>
                    <?php if ($setId < 1): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php $setSlug = trim((string) ($setOption['slug'] ?? '')); ?>
                    <option value="<?= $setId ?>"<?= $selectedSetId === $setId ? ' selected' : '' ?>>
                        <?= e((string) ($setOption['name'] ?? ('Set ' . $setId))) ?><?= $setSlug !== '' ? ' (' . e($setSlug) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
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
        <?php elseif ($isSiteProtocolField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="https"<?= (string) $field['value'] === 'https' ? ' selected' : '' ?>>https</option>
                <option value="http"<?= (string) $field['value'] === 'http' ? ' selected' : '' ?>>http</option>
            </select>
        <?php elseif ($isPanelDefaultThemeField): ?>
            <!-- Default panel theme is constrained to supported panel variants. -->
            <?php
            $panelDefaultTheme = strtolower(trim((string) $field['value']));
            if (in_array($panelDefaultTheme, ['light', 'raven', 'default'], true)) {
                $panelDefaultTheme = 'corp';
            } elseif ($panelDefaultTheme === 'dark') {
                $panelDefaultTheme = 'midnight';
            }
            if (!in_array($panelDefaultTheme, ['corp', 'ice', 'midnight'], true)) {
                $panelDefaultTheme = 'corp';
            }
            ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="corp"<?= $panelDefaultTheme === 'corp' ? ' selected' : '' ?>>Corporate</option>
                <option value="ice"<?= $panelDefaultTheme === 'ice' ? ' selected' : '' ?>>Ice</option>
                <option value="midnight"<?= $panelDefaultTheme === 'midnight' ? ' selected' : '' ?>>Midnight</option>
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
                <option value="public_full"<?= (string) $field['value'] === 'public_full' ? ' selected' : '' ?>>Public Full</option>
                <option value="public_limited"<?= (string) $field['value'] === 'public_limited' ? ' selected' : '' ?>>Public Limited</option>
                <option value="private"<?= (string) $field['value'] === 'private' ? ' selected' : '' ?>>Private</option>
                <option value="disabled"<?= (string) $field['value'] === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            </select>
        <?php elseif ($isUserLoginIdentifierField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="email"<?= (string) $field['value'] === 'email' ? ' selected' : '' ?>>Email</option>
                <option value="username"<?= (string) $field['value'] === 'username' ? ' selected' : '' ?>>Username</option>
            </select>
        <?php elseif ($isUserRegistrationModeField): ?>
            <select
                class="form-select font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                required
            >
                <option value="open"<?= (string) $field['value'] === 'open' ? ' selected' : '' ?>>Open</option>
                <option value="invite"<?= (string) $field['value'] === 'invite' ? ' selected' : '' ?>>Invite</option>
                <option value="closed"<?= (string) $field['value'] === 'closed' ? ' selected' : '' ?>>Closed</option>
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
        <?php elseif ($isDatabasePasswordField): ?>
            <input
                type="password"
                class="form-control font-monospace"
                id="<?= e($inputId) ?>"
                name="<?= e($fieldName) ?>"
                value="<?= e($inputValue) ?>"
                autocomplete="off"
                spellcheck="false"
                <?= $isRequired ? 'required' : '' ?>
            >
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
            <h3><?= e($groupLabel) ?></h3>
            <?php
            $currentGroup = $group;
        }

        $renderConfigField($field);
    }
};
?>

<header class="card">
    <div class="card-body">
        <h1>System Configuration</h1>
        <p class="text-muted mt-2 mb-0">Manage site, database, debug, media, meta, security, and user/session runtime settings.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if (!$canManageConfiguration): ?>
<section class="card">
    <div class="card-body">
        <p class="text-danger mb-0">Manage System Configuration permission is required for this section.</p>
    </div>
</section>
<?php else: ?>
<form method="post" action="<?= e($panelBase) ?>/configuration/save">
        <?= $csrfField ?>
        <input type="hidden" name="_config_tab" id="config-active-tab" value="<?= e($activeConfigTab) ?>">
        <nav class="rvnp-editor-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Configuration</button>
        </nav>

        <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
        <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('basic') ? ' active' : '' ?>"
                    id="config-basic-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-basic"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-basic"
                    aria-selected="<?= $isActiveConfigTab('basic') ? 'true' : 'false' ?>"
                >Basic</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('content') ? ' active' : '' ?>"
                    id="config-content-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-content"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-content"
                    aria-selected="<?= $isActiveConfigTab('content') ? 'true' : 'false' ?>"
                >Content</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('database') ? ' active' : '' ?>"
                    id="config-database-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-database"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-database"
                    aria-selected="<?= $isActiveConfigTab('database') ? 'true' : 'false' ?>"
                >Database</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('debug') ? ' active' : '' ?>"
                    id="config-debug-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-debug"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-debug"
                    aria-selected="<?= $isActiveConfigTab('debug') ? 'true' : 'false' ?>"
                >Debug</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('media') ? ' active' : '' ?>"
                    id="config-media-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-media"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-media"
                    aria-selected="<?= $isActiveConfigTab('media') ? 'true' : 'false' ?>"
                >Media</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('meta') ? ' active' : '' ?>"
                    id="config-meta-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-meta"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-meta"
                    aria-selected="<?= $isActiveConfigTab('meta') ? 'true' : 'false' ?>"
                >Meta</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('security') ? ' active' : '' ?>"
                    id="config-security-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-security"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-security"
                    aria-selected="<?= $isActiveConfigTab('security') ? 'true' : 'false' ?>"
                >Security</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActiveConfigTab('users') ? ' active' : '' ?>"
                    id="config-users-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#rvnp-editor-pane-users"
                    type="button"
                    role="tab"
                    aria-controls="rvnp-editor-pane-users"
                    aria-selected="<?= $isActiveConfigTab('users') ? 'true' : 'false' ?>"
                >Users</button>
            </li>
        </ul>

        <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('basic') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-basic"
                            role="tabpanel"
                            aria-labelledby="config-basic-tab"
                            tabindex="0"
                        >
                            <?php if ($basicSiteConfigFields === [] && $basicPanelConfigFields === [] && $basicOtherConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php $hasBasicSections = false; ?>

                                <?php if ($basicSiteConfigFields !== []): ?>
                                    <h3>Site</h3>
                                    <p class="text-muted small mb-3">
                                        Default Site Theme is managed in Theme Manager or by <code>rvn-theme</code>.
                                    </p>
                                    <?php foreach ($basicSiteConfigFields as $siteField): ?>
                                        <?php $renderConfigField($siteField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasBasicSections = true; ?>
                                <?php endif; ?>

                                <?php if ($basicPanelConfigFields !== []): ?>
                                    <?php if ($hasBasicSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Panel</h3>
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

                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('content') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-content"
                            role="tabpanel"
                            aria-labelledby="config-content-tab"
                            tabindex="0"
                        >
                            <?php if ($contentGeneralConfigFields === [] && $contentFeedsConfigFields === [] && $contentCategoriesConfigFields === [] && $contentTagsConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php $hasContentSections = false; ?>

                                <?php if ($contentGeneralConfigFields !== []): ?>
                                    <h3>Pages</h3>
                                    <?php foreach ($contentGeneralConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasContentSections = true; ?>
                                <?php endif; ?>

                                <?php if ($contentFeedsConfigFields !== []): ?>
                                    <?php if ($hasContentSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Feeds</h3>
                                    <?php foreach ($contentFeedsConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasContentSections = true; ?>
                                <?php endif; ?>

                                <?php if ($contentCategoriesConfigFields !== []): ?>
                                    <?php if ($hasContentSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Categories</h3>
                                    <?php foreach ($contentCategoriesConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                    <?php $hasContentSections = true; ?>
                                <?php endif; ?>

                                <?php if ($contentTagsConfigFields !== []): ?>
                                    <?php if ($hasContentSections): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Tags</h3>
                                    <?php foreach ($contentTagsConfigFields as $contentField): ?>
                                        <?php $renderConfigField($contentField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('database') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-database"
                            role="tabpanel"
                            aria-labelledby="config-database-tab"
                            tabindex="0"
                        >
                            <?php $renderConfigFieldGroup($databaseConfigFields); ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('debug') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-debug"
                            role="tabpanel"
                            aria-labelledby="config-debug-tab"
                            tabindex="0"
                        >
                            <?php if ($debugConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($debugVisibilityConfigFields !== []): ?>
                                    <h3>Toolbar Visibility</h3>
                                    <?php foreach ($debugVisibilityConfigFields as $debugField): ?>
                                        <?php $renderConfigField($debugField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($debugSectionsConfigFields !== []): ?>
                                    <?php if ($debugVisibilityConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Expanded Profiler Sections</h3>
                                    <?php foreach ($debugSectionsConfigFields as $debugField): ?>
                                        <?php $renderConfigField($debugField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('media') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-media"
                            role="tabpanel"
                            aria-labelledby="config-media-tab"
                            tabindex="0"
                        >
                            <?php if ($mediaUploadConfigFields === [] && $mediaImageSizeConfigFields === [] && $avatarConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($mediaUploadConfigFields !== []): ?>
                                    <h3>Upload Settings</h3>
                                    <?php foreach ($mediaUploadConfigFields as $mediaField): ?>
                                        <?php $renderConfigField($mediaField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($mediaImageSizeConfigFields !== []): ?>
                                    <?php if ($mediaUploadConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Image Sizes</h3>
                                    <?php foreach ($mediaImageSizeConfigFields as $imageSizeField): ?>
                                        <?php $renderConfigField($imageSizeField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($avatarConfigFields !== []): ?>
                                    <?php if ($mediaUploadConfigFields !== [] || $mediaImageSizeConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Avatar Settings</h3>
                                    <?php foreach ($avatarConfigFields as $avatarField): ?>
                                        <?php $renderConfigField($avatarField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('meta') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-meta"
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
                                    <h3>Meta Properties</h3>
                                    <?php foreach ($metaGeneralPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($metaOpenGraphPropertyConfigFields !== []): ?>
                                    <?php if ($metaSiteConfigFields !== [] || $metaGeneralPropertyConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>OpenGraph Properties</h3>
                                    <?php foreach ($metaOpenGraphPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($metaTwitterPropertyConfigFields !== []): ?>
                                    <?php if ($metaSiteConfigFields !== [] || $metaGeneralPropertyConfigFields !== [] || $metaOpenGraphPropertyConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Twitter Card Properties</h3>
                                    <?php foreach ($metaTwitterPropertyConfigFields as $metaPropertyField): ?>
                                        <?php $renderConfigField($metaPropertyField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('security') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-security"
                            role="tabpanel"
                            aria-labelledby="config-security-tab"
                            tabindex="0"
                        >
                            <?php if ($captchaConfigFields === [] && $sessionBruteForceConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($captchaConfigFields !== []): ?>
                                    <h3>Captcha</h3>
                                    <?php foreach ($captchaConfigFields as $captchaField): ?>
                                        <?php $renderConfigField($captchaField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionBruteForceConfigFields !== []): ?>
                                    <?php if ($captchaConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Brute Force Protection</h3>
                                    <?php foreach ($sessionBruteForceConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div
                            class="tab-pane fade<?= $isActiveConfigTab('users') ? ' show active' : '' ?>"
                            id="rvnp-editor-pane-users"
                            role="tabpanel"
                            aria-labelledby="config-users-tab"
                            tabindex="0"
                        >
                            <?php if ($sessionCookieConfigFields === [] && $sessionLoginConfigFields === [] && $sessionProfileConfigFields === [] && $sessionGroupConfigFields === []): ?>
                                <p class="text-muted mb-0">No configuration fields available.</p>
                            <?php else: ?>
                                <?php if ($sessionLoginConfigFields !== []): ?>
                                    <h3>Registration Options</h3>
                                    <?php foreach ($sessionLoginConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionCookieConfigFields !== []): ?>
                                    <?php if ($sessionLoginConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Cookie Settings</h3>
                                    <?php foreach ($sessionCookieConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionProfileConfigFields !== []): ?>
                                    <?php if ($sessionLoginConfigFields !== [] || $sessionCookieConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Profile Options</h3>
                                    <?php foreach ($sessionProfileConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($sessionGroupConfigFields !== []): ?>
                                    <?php if ($sessionLoginConfigFields !== [] || $sessionCookieConfigFields !== [] || $sessionProfileConfigFields !== []): ?>
                                        <hr class="my-4">
                                    <?php endif; ?>
                                    <h3>Group Options</h3>
                                    <?php foreach ($sessionGroupConfigFields as $sessionField): ?>
                                        <?php $renderConfigField($sessionField); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($sessionCookieConfigFields !== [] || $sessionLoginConfigFields !== [] || $sessionProfileConfigFields !== [] || $sessionGroupConfigFields !== []): ?>
                                <hr class="my-4">
                            <?php endif; ?>
                            <h3>Contact Options</h3>
                            <div class="form-text mb-2">Configure available contact types for user profiles. Add custom rows as needed.</div>
                            <div class="form-text mb-2"><code>email</code>, <code>phone</code>, <code>homepage</code>, and <code>x</code> are required and cannot be deleted.</div>
                            <div id="config-contact-options-list">
                                <?php foreach ($profileContactOptionRows as $index => $contactOption): ?>
                                    <?php
                                    $rowType = trim((string) ($contactOption['type'] ?? ''));
                                    $rowLabel = trim((string) ($contactOption['label'] ?? ''));
                                    $rowUrlPrefix = trim((string) ($contactOption['url_prefix'] ?? ''));
                                    $isProtectedType = in_array(strtolower($rowType), $protectedProfileContactOptionTypes, true);
                                    ?>
                                    <div class="border rounded p-2 mb-2" data-rvn-contact-option-row="1"<?= $isProtectedType ? ' data-rvn-contact-option-protected="1"' : '' ?>>
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-3">
                                                <label class="form-label">Type Slug</label>
                                                <input
                                                    type="text"
                                                    class="form-control font-monospace"
                                                    data-rvn-contact-option-key="type"
                                                    name="profile_contact_options[<?= (int) $index ?>][type]"
                                                    value="<?= e($rowType) ?>"
                                                    placeholder="x"
                                                    <?= $isProtectedType ? 'readonly' : '' ?>
                                                >
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Label</label>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    data-rvn-contact-option-key="label"
                                                    name="profile_contact_options[<?= (int) $index ?>][label]"
                                                    value="<?= e($rowLabel) ?>"
                                                    placeholder="X"
                                                >
                                            </div>
                                            <div class="col-md pe-md-0">
                                                <label class="form-label">URL Prefix</label>
                                                <input
                                                    type="text"
                                                    class="form-control font-monospace"
                                                    data-rvn-contact-option-key="url_prefix"
                                                    name="profile_contact_options[<?= (int) $index ?>][url_prefix]"
                                                    value="<?= e($rowUrlPrefix) ?>"
                                                    placeholder="https://x.com/"
                                                >
                                            </div>
                                            <div class="col-auto ps-md-0 d-flex align-items-end">
                                                <button type="button" class="btn btn-danger ms-2" data-rvn-contact-option-remove="1" aria-label="Remove contact option" title="<?= $isProtectedType ? 'Required contact option cannot be removed' : 'Remove contact option' ?>"<?= $isProtectedType ? ' disabled' : '' ?>><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="config-contact-option-add">Add Contact Option</button>
                        </div>
        </div>
        </section>

    <nav class="rvnp-editor-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Configuration</button>
    </nav>
</form>
<template id="config-contact-option-template">
    <div class="border rounded p-2 mb-2" data-rvn-contact-option-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Type Slug</label>
                <input
                    type="text"
                    class="form-control font-monospace"
                    data-rvn-contact-option-key="type"
                    placeholder="x"
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Label</label>
                <input
                    type="text"
                    class="form-control"
                    data-rvn-contact-option-key="label"
                    placeholder="X"
                >
            </div>
            <div class="col-md pe-md-0">
                <label class="form-label">URL Prefix</label>
                <input
                    type="text"
                    class="form-control font-monospace"
                    data-rvn-contact-option-key="url_prefix"
                    placeholder="https://x.com/"
                >
            </div>
            <div class="col-auto ps-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-rvn-contact-option-remove="1" aria-label="Remove contact option" title="Remove contact option"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</template>
<script>
  (function () {
    var list = document.getElementById('config-contact-options-list');
    var addButton = document.getElementById('config-contact-option-add');
    var template = document.getElementById('config-contact-option-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function normalizeTypeSlug(value) {
      return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80);
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-rvn-contact-option-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var typeField = row.querySelector('[data-rvn-contact-option-key="type"]');
        var labelField = row.querySelector('[data-rvn-contact-option-key="label"]');
        var prefixField = row.querySelector('[data-rvn-contact-option-key="url_prefix"]');
        if (typeField instanceof HTMLInputElement) {
          typeField.name = 'profile_contact_options[' + index + '][type]';
        }
        if (labelField instanceof HTMLInputElement) {
          labelField.name = 'profile_contact_options[' + index + '][label]';
        }
        if (prefixField instanceof HTMLInputElement) {
          prefixField.name = 'profile_contact_options[' + index + '][url_prefix]';
        }
      });
    }

    addButton.addEventListener('click', function () {
      var fragment = template.content.cloneNode(true);
      list.appendChild(fragment);
      reindexRows();
    });

    list.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLInputElement) || target.getAttribute('data-rvn-contact-option-key') !== 'type') {
        return;
      }
      target.value = normalizeTypeSlug(target.value);
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var removeButton = target.closest('[data-rvn-contact-option-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }
      var row = removeButton.closest('[data-rvn-contact-option-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }
      if (row.getAttribute('data-rvn-contact-option-protected') === '1' || removeButton.hasAttribute('disabled')) {
        return;
      }
      row.remove();
      reindexRows();
    });

    reindexRows();
  })();
</script>
<script>
  // Shows only config fields for the selected DB driver.
  (function () {
    var driverSelect = document.querySelector('[data-rvn-db-driver-select="1"]');
    var captchaProviderSelect = document.querySelector('[data-rvn-captcha-provider-select="1"]');
    var feedsEnabledToggle = document.querySelector('[data-rvn-config-path="feed.enabled"] input.form-check-input[type="checkbox"]');
    var feedChannelsContainer = document.querySelector('[data-rvn-config-path="feed.channels"] [data-rvn-feed-channels="1"]');
    var categoryEnabledToggle = document.querySelector('[data-rvn-config-path="category.enabled"] input.form-check-input[type="checkbox"]');
    var tagEnabledToggle = document.querySelector('[data-rvn-config-path="tag.enabled"] input.form-check-input[type="checkbox"]');
    var activeTabInput = document.getElementById('config-active-tab');
    var configForm = activeTabInput instanceof HTMLInputElement ? activeTabInput.form : null;
    function syncDatabaseRows() {
      if (!(driverSelect instanceof HTMLSelectElement)) {
        return;
      }
      var selected = String(driverSelect.value || '').toLowerCase();
      document.querySelectorAll('[data-rvn-db-section]').forEach(function (row) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var section = String(row.getAttribute('data-rvn-db-section') || '').toLowerCase();
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
      document.querySelectorAll('[data-rvn-captcha-section]').forEach(function (row) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var section = String(row.getAttribute('data-rvn-captcha-section') || '');
        var show = section === selected;
        row.classList.toggle('d-none', !show);
      });
    }
    function syncTaxonomyRows() {
      var feedsEnabled = !(feedsEnabledToggle instanceof HTMLInputElement) || feedsEnabledToggle.checked;
      var categoriesEnabled = !(categoryEnabledToggle instanceof HTMLInputElement) || categoryEnabledToggle.checked;
      var tagsEnabled = !(tagEnabledToggle instanceof HTMLInputElement) || tagEnabledToggle.checked;
      document.querySelectorAll('[data-rvn-config-path]').forEach(function (row) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var path = String(row.getAttribute('data-rvn-config-path') || '').toLowerCase();
        if (path === 'feed.enabled' || path === 'category.enabled' || path === 'tag.enabled') {
          return;
        }
        if (path.indexOf('feed.') === 0) {
          row.classList.toggle('d-none', !feedsEnabled);
          return;
        }
        if (path.indexOf('category.') === 0) {
          row.classList.toggle('d-none', !categoriesEnabled);
          return;
        }
        if (path.indexOf('tag.') === 0) {
          row.classList.toggle('d-none', !tagsEnabled);
        }
      });
    }
    function syncFeedChannelSelectionFromAll() {
      if (!(feedChannelsContainer instanceof HTMLElement)) {
        return;
      }
      var allToggle = feedChannelsContainer.querySelector('[data-rvn-feed-channel-all="1"]');
      if (!(allToggle instanceof HTMLInputElement)) {
        return;
      }
      feedChannelsContainer.querySelectorAll('[data-rvn-feed-channel-item="1"]').forEach(function (checkbox) {
        if (!(checkbox instanceof HTMLInputElement)) {
          return;
        }
        checkbox.checked = allToggle.checked;
      });
      allToggle.indeterminate = false;
    }
    function syncFeedChannelAllState() {
      if (!(feedChannelsContainer instanceof HTMLElement)) {
        return;
      }
      var allToggle = feedChannelsContainer.querySelector('[data-rvn-feed-channel-all="1"]');
      if (!(allToggle instanceof HTMLInputElement)) {
        return;
      }
      var items = Array.prototype.slice.call(
        feedChannelsContainer.querySelectorAll('[data-rvn-feed-channel-item="1"]')
      ).filter(function (checkbox) {
        return checkbox instanceof HTMLInputElement;
      });
      if (items.length === 0) {
        allToggle.indeterminate = false;
        return;
      }
      var checkedCount = items.filter(function (checkbox) {
        return checkbox.checked;
      }).length;
      allToggle.checked = checkedCount === items.length;
      allToggle.indeterminate = checkedCount > 0 && checkedCount < items.length;
    }
    function tabKeyFromButton(button) {
      if (!(button instanceof HTMLElement)) {
        return 'basic';
      }
      var controls = String(button.getAttribute('aria-controls') || '');
      var match = controls.match(/^rvnp-editor-pane-(basic|content|database|debug|media|meta|security|users)$/);
      return match ? String(match[1]) : 'basic';
    }
    function syncActiveTabHiddenFieldFromDom() {
      if (!(activeTabInput instanceof HTMLInputElement)) {
        return;
      }
      var activeButton = document.querySelector('[data-rvn-tab-group="rvnp-editor-tabs"][data-rvn-tab-position="top"] button.nav-link.active[data-bs-toggle="tab"]');
      if (!(activeButton instanceof HTMLElement)) {
        activeButton = document.querySelector('#rvnp-editor-tabs button.nav-link.active[data-bs-toggle="tab"]');
      }
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
    if (feedsEnabledToggle instanceof HTMLInputElement) {
      feedsEnabledToggle.addEventListener('change', syncTaxonomyRows);
    }
    if (feedChannelsContainer instanceof HTMLElement) {
      var feedChannelsAllToggle = feedChannelsContainer.querySelector('[data-rvn-feed-channel-all="1"]');
      if (feedChannelsAllToggle instanceof HTMLInputElement) {
        feedChannelsAllToggle.addEventListener('change', syncFeedChannelSelectionFromAll);
      }
      feedChannelsContainer.querySelectorAll('[data-rvn-feed-channel-item="1"]').forEach(function (checkbox) {
        if (!(checkbox instanceof HTMLInputElement)) {
          return;
        }
        checkbox.addEventListener('change', syncFeedChannelAllState);
      });
      syncFeedChannelAllState();
      if (feedChannelsAllToggle instanceof HTMLInputElement && feedChannelsAllToggle.checked) {
        syncFeedChannelSelectionFromAll();
      }
    }
    if (categoryEnabledToggle instanceof HTMLInputElement) {
      categoryEnabledToggle.addEventListener('change', syncTaxonomyRows);
    }
    if (tagEnabledToggle instanceof HTMLInputElement) {
      tagEnabledToggle.addEventListener('change', syncTaxonomyRows);
    }
    syncTaxonomyRows();
    document.addEventListener('shown.bs.tab', function (event) {
      if (!(activeTabInput instanceof HTMLInputElement)) {
        return;
      }
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target.getAttribute('data-bs-toggle') !== 'tab') {
        return;
      }
      var controls = String(target.getAttribute('aria-controls') || '');
      if (!/^rvnp-editor-pane-(basic|content|database|debug|media|meta|security|users)$/.test(controls)) {
        return;
      }
      activeTabInput.value = tabKeyFromButton(target);
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
