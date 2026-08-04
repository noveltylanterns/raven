<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/channel/edit.php
 * Admin panel channel edit/create template.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $channel */
/** @var bool $feedsEnabled */
/** @var bool $categoryEnabled */
/** @var bool $tagEnabled */
/** @var array<int, array{id: int, name: string, slug: string, parent_id: int, depth: int}> $parentOptions */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $categorySetOptions */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $tagSetOptions */
/** @var array<string, string> $themeOptions */
/** @var string $rssFeedRoute */
/** @var string $atomFeedRoute */
/** @var string $imageAllowedExtensions */
/** @var int|null $imageMaxFilesizeKb */
/** @var array<string, array{width: int, height: int}> $imageVariantSpecs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$channelName = trim((string) ($channel['name'] ?? ''));
$channelId = (int) ($channel['id'] ?? 0);
$hasPersistedChannel = $channelId > 0;
$parentId = (int) ($channel['parent_id'] ?? 0);
$channelSlug = trim((string) ($channel['slug'] ?? ''));
$channelPath = trim((string) ($channel['path'] ?? $channelSlug), '/');
$feedEnabled = (bool) ($channel['feed_enabled'] ?? false);
$selectedCategorySets = is_array($channel['category_sets'] ?? null) ? $channel['category_sets'] : [];
$selectedTagSets = is_array($channel['tag_sets'] ?? null) ? $channel['tag_sets'] : [];
$editorOverride = (string) ($channel['editor_override'] ?? 'inherit');
$themeOverride = (string) ($channel['theme_override'] ?? 'inherit');
$routeMode = (string) ($channel['route_mode'] ?? 'inherit');
$routeSeparator = (string) ($channel['route_separator'] ?? 'inherit');
$indexRouteMode = (string) ($channel['index'] ?? 'auto');
$activeTab = (string) ($activeTab ?? 'basic');
$taxonomyAssignmentsEnabled = $categoryEnabled || $tagEnabled;
$deleteFormId = 'delete-channel-form';
$coverPath = trim((string) ($channel['cover_image_path'] ?? ''));
$previewPath = trim((string) ($channel['preview_image_path'] ?? ''));
$coverUrl = $coverPath !== '' ? '/' . ltrim($coverPath, '/') : '';
$previewUrl = $previewPath !== '' ? '/' . ltrim($previewPath, '/') : '';
$maxFilesizeLabel = $imageMaxFilesizeKb === null
    ? 'No limit'
    : number_format((int) $imageMaxFilesizeKb) . ' KB';
$smallSpec = $imageVariantSpecs['sm'] ?? ['width' => 0, 'height' => 0];
$mediumSpec = $imageVariantSpecs['md'] ?? ['width' => 0, 'height' => 0];
$largeSpec = $imageVariantSpecs['lg'] ?? ['width' => 0, 'height' => 0];
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$coverCopyUrl = $coverUrl;
if ($coverCopyUrl !== '' && $publicBase !== '') {
    $coverCopyUrl = $publicBase . $coverCopyUrl;
}
$previewCopyUrl = $previewUrl;
if ($previewCopyUrl !== '' && $publicBase !== '') {
    $previewCopyUrl = $publicBase . $previewCopyUrl;
}
$channelPublicUrl = null;
if ($channel !== null && $publicBase !== '' && $channelPath !== '') {
    // Encode each slug segment independently so nested channel paths keep their route separators.
    $channelPathSegments = array_values(array_filter(
        explode('/', $channelPath),
        static fn (string $segment): bool => $segment !== ''
    ));
    $encodedChannelPath = implode('/', array_map('rawurlencode', $channelPathSegments));
    $channelPublicUrl = $publicBase . '/' . $encodedChannelPath;
}
$rssFeedRoute = trim((string) ($rssFeedRoute ?? ''));
$atomFeedRoute = trim((string) ($atomFeedRoute ?? ''));
$channelFeedRoutes = [];
$channelFeedSlug = $channelSlug !== '' ? $channelSlug : '{channel-slug}';
if ($rssFeedRoute !== '') {
    $channelFeedRoutes[] = '/' . $rssFeedRoute . '/' . $channelFeedSlug;
}
if ($atomFeedRoute !== '') {
    $channelFeedRoutes[] = '/' . $atomFeedRoute . '/' . $channelFeedSlug;
}
$channelHeaderBodyHtml = '';
if ($channel !== null && $channelPublicUrl !== null) {
    $channelPublicUrlEscaped = e($channelPublicUrl);
    $channelHeaderBodyHtml = <<<HTML
            <p class="mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="{$channelPublicUrlEscaped}"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="{$channelPublicUrlEscaped}"
                    aria-label="Open channel URL"
                    style="font-size: 0.88em;"
                >
                    {$channelPublicUrlEscaped}
                </a>
            </p>
HTML;
}
$channelEditorToolbarItems = [
    '<button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Channel</button>',
    '<a href="' . e($panelBase) . '/channel" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Channels</a>',
];
if ($hasPersistedChannel) {
    $channelEditorToolbarItems[] = '<button type="submit" class="btn btn-danger" form="' . e($deleteFormId) . '" onclick="return confirm(\'Delete this channel? Linked pages will be detached.\');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Channel</button>';
}
?>
<?= Header::render([
    'title_html' => $channel === null
        ? 'New Channel'
        : 'Edit Channel: <span class="text-primary">\'' . e($channelName !== '' ? $channelName : 'Untitled') . '\'</span>',
    'summary' => $channel === null ? 'Create or update a channel and manage its preview/cover media.' : '',
    'body_html' => $channelHeaderBodyHtml,
    'help_url' => $panelBase . '/docs/channels',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedChannel): ?>
<!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/channel/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $channelId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/channel/save" enctype="multipart/form-data">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $channelId ?>">
    <?= Toolbar::render([
        'items' => $channelEditorToolbarItems,
        'class' => 'rvnp-editor-actions',
    ]) ?>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'basic' ? ' active' : '' ?>"
                id="channel-basic-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-basic"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-basic"
                aria-selected="<?= $activeTab === 'basic' ? 'true' : 'false' ?>"
            >Basic</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'content' ? ' active' : '' ?>"
                id="channel-content-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-content"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-content"
                aria-selected="<?= $activeTab === 'content' ? 'true' : 'false' ?>"
            >Content</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'media' ? ' active' : '' ?>"
                id="channel-media-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-media"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-media"
                aria-selected="<?= $activeTab === 'media' ? 'true' : 'false' ?>"
            >Media</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'routing' ? ' active' : '' ?>"
                id="channel-routing-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-routing"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-routing"
                aria-selected="<?= $activeTab === 'routing' ? 'true' : 'false' ?>"
            >Routing</button>
        </li>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
        <div
            class="tab-pane fade<?= $activeTab === 'basic' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-basic"
            role="tabpanel"
            aria-labelledby="channel-basic-tab"
            tabindex="0"
        >
            <h3>Basic Settings</h3>

            <div class="form-group">
                <label for="name" class="form-label">Name</label>
                <!-- Channel names are display-facing labels shown in panel/public listings. -->
                <input id="name" name="name" class="form-control" required value="<?= e((string) ($channel['name'] ?? '')) ?>">
                <div class="form-text">Display name used in channel labels.</div>
            </div>

            <div class="form-group">
                <label for="slug" class="form-label">Slug</label>
                <!-- Slug is used as the channel route segment. -->
                <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($channel['slug'] ?? '')) ?>">
                <div class="form-text">Simple name used for routing and URI segments.</div>
            </div>

            <div class="form-group">
                <label for="parent_id" class="form-label">Parent</label>
                <select id="parent_id" name="parent_id" class="form-select">
                    <?php foreach ($parentOptions as $parentOption): ?>
                        <?php
                        $parentOptionId = (int) ($parentOption['id'] ?? 0);
                        $parentDepth = max(0, (int) ($parentOption['depth'] ?? 0));
                        $parentIndent = str_repeat("\u{00A0}\u{00A0}", max(0, $parentDepth - 1));
                        $parentPath = trim((string) ($parentOption['path'] ?? ''), '/');
                        ?>
                        <option value="<?= $parentOptionId ?>"<?= $parentOptionId === 0 ? ' class="fw-bold"' : '' ?><?= $parentId === $parentOptionId ? ' selected' : '' ?>><?= e($parentIndent . (string) ($parentOption['name'] ?? '')) ?><?= $parentPath !== '' ? ' (' . e($parentPath) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Places this channel within another channel.</div>
            </div>

            <div class="form-group mb-0">
                <label for="description" class="form-label">Description</label>
                <!-- Optional description is editorial/context metadata for this channel. -->
                <textarea id="description" name="description" class="form-control" rows="4"><?= e((string) ($channel['description'] ?? '')) ?></textarea>
                <div class="form-text">Optional editorial description for this channel.</div>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'media' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-media"
            role="tabpanel"
            aria-labelledby="channel-media-tab"
            tabindex="0"
        >

            <h3>Media Settings</h3>

            <div class="form-group text-muted">
                Allowed extensions: <code><?= e($imageAllowedExtensions) ?></code>.
                Max filesize: <code><?= e($maxFilesizeLabel) ?></code>.
                <br>
                Variants use configured contain sizes: <code>sm <?= e((string) $smallSpec['width']) ?>x<?= e((string) $smallSpec['height']) ?></code>,
                <code>md <?= e((string) $mediumSpec['width']) ?>x<?= e((string) $mediumSpec['height']) ?></code>,
                <code>lg <?= e((string) $largeSpec['width']) ?>x<?= e((string) $largeSpec['height']) ?></code>.
            </div>

            <div class="form-group">
                <label for="cover_image" class="form-label">Cover Image</label>
                <input id="cover_image" name="cover_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                <div class="form-text">Optional wide image shown with this channel's public presentation.</div>
                <?php if ($coverPath !== ''): ?>
                    <div class="mt-2">
                        <img src="<?= e($coverUrl) ?>" alt="Current channel cover image" class="img-thumbnail" style="max-width: 240px;">
                    </div>
                    <div class="small text-muted mt-1">
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                            data-rvn-copy-url="1"
                            data-copy-text="<?= e($coverCopyUrl) ?>"
                            data-copy-label="<?= e($coverPath) ?>"
                            title="Click to copy full URL"
                            aria-label="Copy full URL for cover image"
                        >
                            <code><?= e($coverPath) ?></code>
                        </button>
                    </div>
                    <div class="form-check mt-2">
                        <input id="remove_cover_image" name="remove_cover_image" value="1" type="checkbox" class="form-check-input">
                        <label for="remove_cover_image" class="form-check-label">Remove current cover image</label>
                        <div class="form-text">Delete the stored cover image when this form is saved.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="preview_image" class="form-label">Preview Image</label>
                <input id="preview_image" name="preview_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                <div class="form-text">Optional image used for channel previews and panel listings.</div>
                <?php if ($previewPath !== ''): ?>
                    <div class="mt-2">
                        <img src="<?= e($previewUrl) ?>" alt="Current channel preview image" class="img-thumbnail" style="max-width: 240px;">
                    </div>
                    <div class="small text-muted mt-1">
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                            data-rvn-copy-url="1"
                            data-copy-text="<?= e($previewCopyUrl) ?>"
                            data-copy-label="<?= e($previewPath) ?>"
                            title="Click to copy full URL"
                            aria-label="Copy full URL for preview image"
                        >
                            <code><?= e($previewPath) ?></code>
                        </button>
                    </div>
                    <div class="form-check mt-2">
                        <input id="remove_preview_image" name="remove_preview_image" value="1" type="checkbox" class="form-check-input">
                        <label for="remove_preview_image" class="form-check-label">Remove current preview image</label>
                        <div class="form-text">Delete the stored preview image when this form is saved.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label for="theme_override" class="form-label">Theme</label>
                <select id="theme_override" name="theme_override" class="form-select">
                    <option value="inherit" class="fw-bold"<?= $themeOverride === 'inherit' ? ' selected' : '' ?>>Inherit</option>
                    <?php foreach ($themeOptions as $themeSlug => $themeName): ?>
                        <option value="<?= e((string) $themeSlug) ?>"<?= $themeOverride === $themeSlug ? ' selected' : '' ?>><?= e((string) $themeName) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Overrides the site theme for this channel and its pages.</div>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'content' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-content"
            role="tabpanel"
            aria-labelledby="channel-content-tab"
            tabindex="0"
        >
            <?php
            // Buffer taxonomy controls here so they can remain grouped while
            // rendering last in the Content tab after the content options section.
            ob_start();
            ?>
            <?php if ($taxonomyAssignmentsEnabled): ?>
            <h3>Taxonomy Assignments</h3>
            <?php endif; ?>
            <?php if ($categoryEnabled): ?>
                <?php $useCategorySystemDefault = $selectedCategorySets === []; ?>
                <?php $allCategorySetsSelected = in_array(0, array_map('intval', $selectedCategorySets), true); ?>
                <div class="form-group mb-3">
                    <label class="form-label">Category Sets</label>
                    <div class="border rounded p-3" data-rvn-set-selection="category">
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="category_sets_default"
                                name="category_sets[]"
                                value="default"
                                data-rvn-set-default="1"
                                <?= $useCategorySystemDefault ? 'checked' : '' ?>
                            >
                            <label class="form-check-label fw-bold" for="category_sets_default">Use System Default</label>
                        </div>
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="category_sets_all"
                                name="category_sets[]"
                                value="0"
                                data-rvn-set-all="1"
                                <?= $allCategorySetsSelected ? 'checked' : '' ?>
                            >
                            <label class="form-check-label fw-bold" for="category_sets_all">All Sets</label>
                        </div>
                        <?php foreach ($categorySetOptions as $setOption): ?>
                            <?php $setId = (int) ($setOption['id'] ?? 0); ?>
                            <?php $setSlug = (string) ($setOption['slug'] ?? ''); ?>
                            <?php $setChecked = $allCategorySetsSelected || in_array($setId, $selectedCategorySets, true); ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="category_set_<?= $setId ?>"
                                    name="category_sets[]"
                                    value="<?= $setId ?>"
                                    data-rvn-set-item="1"
                                    <?= $setChecked ? 'checked' : '' ?>
                                    <?= $allCategorySetsSelected ? ' disabled' : '' ?>
                                >
                                <label class="form-check-label" for="category_set_<?= $setId ?>">
                                    <?= e((string) ($setOption['name'] ?? 'Set')) ?><?= $setSlug !== '' ? ' (' . e($setSlug) . ')' : '' ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Choose which category sets are available to pages assigned to this channel.</div>
                </div>
            <?php endif; ?>

            <?php if ($tagEnabled): ?>
                <?php $useTagSystemDefault = $selectedTagSets === []; ?>
                <?php $allTagSetsSelected = in_array(0, array_map('intval', $selectedTagSets), true); ?>
                <div class="form-group mb-3">
                    <label class="form-label">Tag Sets</label>
                    <div class="border rounded p-3" data-rvn-set-selection="tag">
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="tag_sets_default"
                                name="tag_sets[]"
                                value="default"
                                data-rvn-set-default="1"
                                <?= $useTagSystemDefault ? 'checked' : '' ?>
                            >
                            <label class="form-check-label fw-bold" for="tag_sets_default">Use System Default</label>
                        </div>
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="tag_sets_all"
                                name="tag_sets[]"
                                value="0"
                                data-rvn-set-all="1"
                                <?= $allTagSetsSelected ? 'checked' : '' ?>
                            >
                            <label class="form-check-label fw-bold" for="tag_sets_all">All Sets</label>
                        </div>
                        <?php foreach ($tagSetOptions as $setOption): ?>
                            <?php $setId = (int) ($setOption['id'] ?? 0); ?>
                            <?php $setSlug = (string) ($setOption['slug'] ?? ''); ?>
                            <?php $setChecked = $allTagSetsSelected || in_array($setId, $selectedTagSets, true); ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="tag_set_<?= $setId ?>"
                                    name="tag_sets[]"
                                    value="<?= $setId ?>"
                                    data-rvn-set-item="1"
                                    <?= $setChecked ? 'checked' : '' ?>
                                    <?= $allTagSetsSelected ? ' disabled' : '' ?>
                                >
                                <label class="form-check-label" for="tag_set_<?= $setId ?>">
                                    <?= e((string) ($setOption['name'] ?? 'Set')) ?><?= $setSlug !== '' ? ' (' . e($setSlug) . ')' : '' ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Choose which tag sets are available to pages assigned to this channel.</div>
                </div>
            <?php endif; ?>

            <?php $taxonomyAssignmentsHtml = (string) ob_get_clean(); ?>

            <h3>Content Options</h3>
            <div class="form-group">
                <label for="editor_override" class="form-label">Editor Override</label>
                <select id="editor_override" name="editor_override" class="form-select">
                    <option value="inherit"<?= $editorOverride === 'inherit' ? ' selected' : '' ?>>Use Global Default</option>
                    <option value="tinymce"<?= $editorOverride === 'tinymce' ? ' selected' : '' ?>>Rich Text (TinyMCE)</option>
                    <option value="plaintext"<?= $editorOverride === 'plaintext' ? ' selected' : '' ?>>Code</option>
                    <option value="autobr"<?= $editorOverride === 'autobr' ? ' selected' : '' ?>>Plaintext</option>
                    <option value="markdown"<?= $editorOverride === 'markdown' ? ' selected' : '' ?>>Markdown</option>
                </select>
                <div class="form-text">Controls which block type the Page Editor inserts when using <strong>Add Text Block</strong> for pages in this channel.</div>
            </div>

            <?php if ($feedsEnabled): ?>
                <div class="form-group mb-0">
                    <label class="form-label d-block">Syndication</label>
                    <div class="border rounded p-3" data-rvn-set-selection="syndication">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="feed_enabled"
                            id="feed_enabled"
                            value="1"
                            <?= $feedEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="feed_enabled">Enable dedicated sub-feeds for this channel.</label>
                        <div class="form-text">
                        <?php if ($channelFeedRoutes !== []): ?>
                            Routes:
                            <?php foreach ($channelFeedRoutes as $index => $channelFeedRoute): ?>
                                <?php if ($index > 0): ?>,<?php endif; ?>
                                <code><?= e($channelFeedRoute) ?></code>
                            <?php endforeach; ?>.
                        <?php else: ?>
                            Configure `feed.rss` and/or `feed.atom` globally to activate channel feed URLs.
                        <?php endif; ?>
                        </div>
                    </div>
                    </div>
                    <div class="form-text">Enable or disable dedicated RSS and Atom feeds for this channel.</div>
                </div>
            <?php endif; ?>

            <?php if ($taxonomyAssignmentsEnabled): ?>
            <hr class="my-4">
            <?= $taxonomyAssignmentsHtml ?>
            <?php endif; ?>

        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'routing' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-routing"
            role="tabpanel"
            aria-labelledby="channel-routing-tab"
            tabindex="0"
        >
            <h3>Routing Settings</h3>

            <div class="form-group mb-3">
                <label for="index" class="form-label">Channel Index Route</label>
                <select id="index" name="index" class="form-select">
                    <option value="auto"<?= $indexRouteMode === 'auto' ? ' selected' : '' ?>>Automatic</option>
                    <option value="no_trailing_slash"<?= $indexRouteMode === 'no_trailing_slash' ? ' selected' : '' ?>>No Trailing Slash</option>
                    <option value="trailing_slash"<?= $indexRouteMode === 'trailing_slash' ? ' selected' : '' ?>>Use Trailing Slash</option>
                    <option value="redirect"<?= $indexRouteMode === 'redirect' ? ' selected' : '' ?>>Redirect</option>
                </select>
                <div class="form-text">Defines canonical channel index URI for routing enforcement.</div>
            </div>

            <div class="form-group mb-3">
                <label for="route_mode" class="form-label">Route Mode</label>
                <select id="route_mode" name="route_mode" class="form-select">
                    <option value="inherit"<?= $routeMode === 'inherit' ? ' selected' : '' ?>>Use System Default</option>
                    <option value="slug"<?= $routeMode === 'slug' ? ' selected' : '' ?>>/{channel}/{page-slug}</option>
                    <option value="date_slug"<?= $routeMode === 'date_slug' ? ' selected' : '' ?>>/{channel}/{YYYY-MM-DD}-{page-slug}</option>
                    <option value="month_slug"<?= $routeMode === 'month_slug' ? ' selected' : '' ?>>/{channel}/{YYYY-MM}-{page-slug}</option>
                    <option value="id"<?= $routeMode === 'id' ? ' selected' : '' ?>>/{channel}/{page-id}</option>
                    <option value="date_id"<?= $routeMode === 'date_id' ? ' selected' : '' ?>>/{channel}/{YYYY-MM-DD}-{page-id}</option>
                    <option value="month_id"<?= $routeMode === 'month_id' ? ' selected' : '' ?>>/{channel}/{YYYY-MM}-{page-id}</option>
                </select>
                <div class="form-text">Defines URI structure for pages within this channel.</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label d-block">Route Separator</label>
                <div class="border rounded p-3" data-rvn-set-selection="separator">
                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="radio"
                        name="route_separator"
                        id="route_separator_inherit"
                        value="inherit"
                        <?= $routeSeparator === 'inherit' ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="route_separator_inherit">Use Global Default</label>
                </div>
                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="radio"
                        name="route_separator"
                        id="route_separator_dash"
                        value="-"
                        <?= $routeSeparator === '-' ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="route_separator_dash">- (Hyphen)</label>
                </div>
                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="radio"
                        name="route_separator"
                        id="route_separator_underscore"
                        value="_"
                        <?= $routeSeparator === '_' ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="route_separator_underscore">_ (Underscore)</label>
                </div>
                </div>
                <div class="form-text">Select the separator used between words in the URI.</div>
            </div>

        </div>
    </div>
    </section>

    <?= Toolbar::render([
        'items' => $channelEditorToolbarItems,
        'class' => 'rvnp-editor-actions',
    ]) ?>
</form>

<?php ob_start(); ?>
  (function () {
    function initSetSelection(container) {
      if (!(container instanceof HTMLElement)) {
        return;
      }

      var defaultToggle = container.querySelector('[data-rvn-set-default="1"]');
      var allToggle = container.querySelector('[data-rvn-set-all="1"]');
      if (!(allToggle instanceof HTMLInputElement) && !(defaultToggle instanceof HTMLInputElement)) {
        return;
      }

      var items = Array.prototype.slice.call(container.querySelectorAll('[data-rvn-set-item="1"]')).filter(function (checkbox) {
        return checkbox instanceof HTMLInputElement;
      });

      function checkedItemCount() {
        return items.filter(function (checkbox) {
          return checkbox.checked;
        }).length;
      }

      function syncDisabledState() {
        items.forEach(function (checkbox) {
          checkbox.disabled = allToggle instanceof HTMLInputElement && allToggle.checked;
        });
      }

      function activateSystemDefault() {
        if (!(defaultToggle instanceof HTMLInputElement) || !defaultToggle.checked) {
          return;
        }

        if (allToggle instanceof HTMLInputElement) {
          allToggle.checked = false;
          allToggle.indeterminate = false;
        }
        items.forEach(function (checkbox) {
          checkbox.checked = false;
        });
        syncDisabledState();
      }

      function activateAllSets() {
        if (!(allToggle instanceof HTMLInputElement)) {
          return;
        }

        if (allToggle.checked && defaultToggle instanceof HTMLInputElement) {
          defaultToggle.checked = false;
        }

        if (allToggle.checked) {
          items.forEach(function (checkbox) {
            checkbox.checked = true;
          });
        }

        allToggle.indeterminate = false;
        syncDisabledState();
      }

      function syncSelectionState() {
        var checkedCount = checkedItemCount();

        if (defaultToggle instanceof HTMLInputElement) {
          if (checkedCount > 0 || (allToggle instanceof HTMLInputElement && allToggle.checked)) {
            defaultToggle.checked = false;
          } else if (!defaultToggle.checked) {
            defaultToggle.checked = true;
          }
        }

        if (allToggle instanceof HTMLInputElement) {
          if (allToggle.checked) {
            allToggle.indeterminate = false;
          } else {
            allToggle.indeterminate = checkedCount > 0 && checkedCount < items.length;
          }
        }

        syncDisabledState();
      }

      if (defaultToggle instanceof HTMLInputElement) {
        defaultToggle.addEventListener('change', function () {
          if (!defaultToggle.checked && checkedItemCount() === 0 && (!(allToggle instanceof HTMLInputElement) || !allToggle.checked)) {
            defaultToggle.checked = true;
            return;
          }

          activateSystemDefault();
          syncSelectionState();
        });
      }

      if (allToggle instanceof HTMLInputElement) {
        allToggle.addEventListener('change', function () {
          activateAllSets();
          syncSelectionState();
        });
      }

      items.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          if (defaultToggle instanceof HTMLInputElement && checkbox.checked) {
            defaultToggle.checked = false;
          }

          if (allToggle instanceof HTMLInputElement && allToggle.checked && !checkbox.checked) {
            allToggle.checked = false;
          }

          syncSelectionState();
        });
      });

      if (defaultToggle instanceof HTMLInputElement && defaultToggle.checked) {
        activateSystemDefault();
      } else if (allToggle instanceof HTMLInputElement && allToggle.checked) {
        activateAllSets();
      }

      syncSelectionState();
    }

    document.querySelectorAll('[data-rvn-set-selection]').forEach(function (container) {
      initSetSelection(container);
    });

    function copyViaLegacyCommand(value) {
      var textArea = document.createElement('textarea');
      textArea.value = String(value || '');
      textArea.setAttribute('readonly', 'readonly');
      textArea.style.position = 'fixed';
      textArea.style.opacity = '0';
      textArea.style.pointerEvents = 'none';
      document.body.appendChild(textArea);
      textArea.select();
      textArea.setSelectionRange(0, textArea.value.length);
      var copied = false;
      try {
        copied = document.execCommand('copy');
      } catch (error) {
        copied = false;
      }
      document.body.removeChild(textArea);
      return copied;
    }

    function copyText(value, onDone) {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(function () {
          onDone(true);
        }).catch(function () {
          onDone(copyViaLegacyCommand(value));
        });
        return;
      }

      onDone(copyViaLegacyCommand(value));
    }

    function absoluteUrl(value) {
      var text = String(value || '').trim();
      if (text === '') {
        return '';
      }

      if (/^https?:\/\//i.test(text)) {
        return text;
      }

      if (text.charAt(0) === '/') {
        return window.location.origin + text;
      }

      return window.location.origin + '/' + text.replace(/^\/+/, '');
    }

    function showCopyFeedback(button, success) {
      if (!(button instanceof HTMLElement)) {
        return;
      }

      var originalTitle = String(button.getAttribute('data-copy-title') || button.getAttribute('title') || 'Click to copy full URL');
      button.setAttribute('data-copy-title', originalTitle);
      button.setAttribute('title', success ? 'Copied full URL' : 'Copy failed');
      button.classList.remove('text-muted', 'text-success', 'text-danger');
      button.classList.add(success ? 'text-success' : 'text-danger');
      window.setTimeout(function () {
        button.setAttribute('title', originalTitle);
        button.classList.remove('text-success', 'text-danger');
        button.classList.add('text-muted');
      }, 1200);
    }

    document.querySelectorAll('button[data-rvn-copy-url="1"][data-copy-text]').forEach(function (button) {
      button.addEventListener('click', function () {
        var value = absoluteUrl(button.getAttribute('data-copy-text'));
        if (value === '') {
          showCopyFeedback(button, false);
          return;
        }

        copyText(value, function (copied) {
          showCopyFeedback(button, copied);
        });
      });
    });
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>
