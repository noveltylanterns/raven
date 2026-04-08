<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/channel/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $channel */
/** @var bool $feedsEnabled */
/** @var bool $categoryEnabled */
/** @var bool $tagEnabled */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $categorySetOptions */
/** @var array<int, array{id: int, name: string, slug: string, is_root: bool}> $tagSetOptions */
/** @var string $rssFeedRoute */
/** @var string $atomFeedRoute */
/** @var string $imageAllowedExtensions */
/** @var int|null $imageMaxFilesizeKb */
/** @var array<string, array{width: int, height: int}> $imageVariantSpecs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$channelName = trim((string) ($channel['name'] ?? ''));
$channelId = (int) ($channel['id'] ?? 0);
$hasPersistedChannel = $channelId > 0;
$channelSlug = trim((string) ($channel['slug'] ?? ''));
$feedEnabled = (bool) ($channel['feed_enabled'] ?? false);
$selectedCategorySets = is_array($channel['category_sets'] ?? null) ? $channel['category_sets'] : [];
$selectedTagSets = is_array($channel['tag_sets'] ?? null) ? $channel['tag_sets'] : [];
$editorOverride = strtolower(trim((string) ($channel['editor_override'] ?? 'inherit')));
if (!in_array($editorOverride, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)) {
    $editorOverride = 'inherit';
}
$routeMode = strtolower(trim((string) ($channel['route_mode'] ?? 'inherit')));
if (!in_array($routeMode, ['inherit', 'slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'], true)) {
    $routeMode = 'inherit';
}
$routeSeparator = trim((string) ($channel['route_separator'] ?? 'inherit'));
if (!in_array($routeSeparator, ['inherit', '-', '_'], true)) {
    $routeSeparator = 'inherit';
}
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['basic', 'meta', 'media'], true) ? $requestedTab : 'basic';
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
if ($channel !== null && $publicBase !== '' && $channelSlug !== '') {
    $channelPublicUrl = $publicBase . '/' . rawurlencode($channelSlug);
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
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $channel === null ? 'New Channel' : 'Edit Channel: <span class="text-primary">\'' . e($channelName !== '' ? $channelName : 'Untitled') . '\'</span>' ?>
        </h1>
        <?php if ($channel === null): ?>
            <p class="text-muted mb-0">Create or update a channel and manage its preview/cover media.</p>
        <?php elseif ($channelPublicUrl !== null): ?>
            <p class="mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($channelPublicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($channelPublicUrl) ?>"
                    aria-label="Open channel URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($channelPublicUrl) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</header>

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
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Channel</button>
        <a href="<?= e($panelBase) ?>/channel" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Channels</a>
        <?php if ($hasPersistedChannel): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this channel? Linked pages will be detached.');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Channel</button>
        <?php endif; ?>
    </nav>

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
                class="nav-link<?= $activeTab === 'meta' ? ' active' : '' ?>"
                id="channel-meta-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-meta"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-meta"
                aria-selected="<?= $activeTab === 'meta' ? 'true' : 'false' ?>"
            >Meta</button>
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
            <div class="form-group">
                <label for="name" class="form-label">Name</label>
                <!-- Channel names are display-facing labels shown in panel/public listings. -->
                <input id="name" name="name" class="form-control" required value="<?= e((string) ($channel['name'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="slug" class="form-label">Slug</label>
                <!-- Slug is used as the channel route segment. -->
                <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($channel['slug'] ?? '')) ?>">
            </div>

            <div class="form-group mb-0">
                <label for="description" class="form-label">Description</label>
                <!-- Optional description is editorial/context metadata for this channel. -->
                <textarea id="description" name="description" class="form-control" rows="4"><?= e((string) ($channel['description'] ?? '')) ?></textarea>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'media' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-media"
            role="tabpanel"
            aria-labelledby="channel-media-tab"
            tabindex="0"
        >
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
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label for="preview_image" class="form-label">Preview Image</label>
                <input id="preview_image" name="preview_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
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
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'meta' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-meta"
            role="tabpanel"
            aria-labelledby="channel-meta-tab"
            tabindex="0"
        >
            <?php if ($taxonomyAssignmentsEnabled): ?>
            <h3>Assignments</h3>
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
                </div>
            <?php endif; ?>

            <?php if ($taxonomyAssignmentsEnabled): ?>
            <hr class="my-4">
            <?php endif; ?>

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
                <div class="form-text">
                    Controls which block type the Page Editor inserts when using <strong>Add Text Block</strong> for pages in this channel.
                </div>
            </div>

            <hr class="my-4">

            <h3>Routing</h3>
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
                <div class="form-text">
                    Applies to page routes under this channel only. Channel landing routes stay at <code>/<?= e($channelSlug !== '' ? $channelSlug : 'channel') ?></code>.
                </div>
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
            </div>

            <?php if ($feedsEnabled): ?>
                <div class="form-group mb-3">
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
                </div>
            <?php endif; ?>

        </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Channel</button>
        <a href="<?= e($panelBase) ?>/channel" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Channels</a>
        <?php if ($hasPersistedChannel): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this channel? Linked pages will be detached.');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Channel</button>
        <?php endif; ?>
    </nav>
</form>

<script>
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
</script>
