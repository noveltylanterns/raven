<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/page/edit.php
 * Admin panel page editor with content/meta/media tabs.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This view keeps editor UX logic in-template while controller handles all persistence.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $page */
/** @var int $currentUserId */
/** @var array<int, array{id: int, username: string, display_name: string}> $authorOptions */
/** @var array<int, array<string, mixed>> $channelOptions */
/** @var array<int, int|string> $defaultCategorySetSelection */
/** @var array<int, int|string> $defaultTagSetSelection */
/** @var array<int, array<string, mixed>> $categoryOptionsAll */
/** @var array<int, array<string, mixed>> $tagOptionsAll */
/** @var array<int, array{id: int, name: string, slug: string}> $categoryOptionsSelected */
/** @var array<int, array{id: int, name: string, slug: string}> $tagOptionsSelected */
/** @var bool $categoryEnabled */
/** @var bool $tagEnabled */
/** @var array<int, array<string, mixed>> $galleryImages */
/** @var string $imageUploadTarget */
/** @var int $imageMaxFilesPerUpload */
/** @var string $editorDefault */
/** @var string $routeModeDefault */
/** @var string $routeSeparatorDefault */
/** @var array<string, array{label?: string, editor?: string}> $bodyBlockTypeDefinitions */
/** @var array<int, array{extension: string, label: string, shortcode: string}> $shortcodeInsertItems */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$pageId = (int) ($page['id'] ?? 0);
$hasPersistedPage = $pageId > 0;
$currentUserId = max(0, (int) ($currentUserId ?? 0));
$selectedAuthorUserId = (int) ($page['author_user_id'] ?? 0);
if ($selectedAuthorUserId < 1) {
    $selectedAuthorUserId = $currentUserId;
}
$deleteFormId = 'delete-page-form';
$selectedChannelSlug = (string) ($page['channel_slug'] ?? '');
$selectedStatus = (int) ($page['is_published'] ?? 1) === 1 ? 'published' : 'draft';
$displayTitle = !array_key_exists('display_title', (array) ($page ?? []))
    || (int) ($page['display_title'] ?? 1) === 1;
$galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1;
$editorDefault = in_array($editorDefault ?? '', ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
    ? (string) $editorDefault
    : 'tinymce';
$routeModeDefault = in_array($routeModeDefault ?? 'slug', ['slug', 'id'], true)
    ? (string) $routeModeDefault
    : 'slug';
$defaultCategorySetSelection = is_array($defaultCategorySetSelection ?? null) ? $defaultCategorySetSelection : [1];
$defaultTagSetSelection = is_array($defaultTagSetSelection ?? null) ? $defaultTagSetSelection : [1];
$categoryEnabled = (bool) ($categoryEnabled ?? true);
$tagEnabled = (bool) ($tagEnabled ?? true);
$routeSeparatorDefault = in_array($routeSeparatorDefault ?? '-', ['-', '_'], true)
    ? (string) $routeSeparatorDefault
    : '-';
$coreBodyBlockTypeDefinitions = [
    'tinymce' => ['label' => 'Rich Text', 'editor' => 'tinymce'],
    'plaintext' => ['label' => 'Plaintext', 'editor' => 'plaintext'],
    'autobr' => ['label' => 'Auto <br>', 'editor' => 'autobr'],
    'markdown' => ['label' => 'Markdown', 'editor' => 'markdown'],
    'markdown_file' => ['label' => 'Markdown File', 'editor' => 'markdown_file'],
    'image_gallery' => ['label' => 'Image Gallery', 'editor' => 'gallery'],
];
$rawBodyBlockTypeDefinitions = is_array($bodyBlockTypeDefinitions ?? null) ? $bodyBlockTypeDefinitions : [];
$bodyBlockTypeDefinitions = $coreBodyBlockTypeDefinitions;
foreach ($rawBodyBlockTypeDefinitions as $typeKey => $definition) {
    if (!is_string($typeKey)) {
        continue;
    }
    if (!is_array($definition)) {
        continue;
    }

    $normalizedType = strtolower(trim($typeKey));
    if ($normalizedType === '' || preg_match('/^[a-z0-9_]{1,120}$/', $normalizedType) !== 1) {
        continue;
    }

    $label = trim((string) ($definition['label'] ?? ''));
    if ($label === '') {
        continue;
    }

    $editor = strtolower(trim((string) ($definition['editor'] ?? 'tinymce')));
    if (!in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'gallery'], true)) {
        $editor = 'tinymce';
    }

    $bodyBlockTypeDefinitions[$normalizedType] = [
        'label' => $label,
        'editor' => $editor,
    ];
}
$customAddBodyItems = [];
foreach ($bodyBlockTypeDefinitions as $typeKey => $definition) {
    if (in_array($typeKey, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'image_gallery'], true)) {
        continue;
    }

    $customAddBodyItems[] = [
        'type' => $typeKey,
        'label' => (string) ($definition['label'] ?? ucwords(str_replace('_', ' ', $typeKey))),
    ];
}
usort($customAddBodyItems, static function (array $left, array $right): int {
    return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});
$bodyBlockTypeJson = json_encode(
    $bodyBlockTypeDefinitions,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);
if (!is_string($bodyBlockTypeJson) || trim($bodyBlockTypeJson) === '') {
    $bodyBlockTypeJson = '{}';
}
$channelEditorOverrides = [];
$channelRouteModes = [];
$channelUrlSeparators = [];
foreach ($channelOptions as $channelOption) {
    if (!is_array($channelOption)) {
        continue;
    }

    $optionSlug = trim((string) ($channelOption['slug'] ?? ''));
    if ($optionSlug === '') {
        continue;
    }

    $override = strtolower(trim((string) ($channelOption['editor_override'] ?? 'inherit')));
    if (!in_array($override, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)) {
        $override = 'inherit';
    }
    $channelEditorOverrides[$optionSlug] = $override;

    $routeMode = strtolower(trim((string) ($channelOption['route_mode'] ?? 'inherit')));
    if (!in_array($routeMode, ['inherit', 'slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'], true)) {
        $routeMode = 'inherit';
    }
    $channelRouteModes[$optionSlug] = $routeMode;

    $urlSeparator = trim((string) ($channelOption['route_separator'] ?? 'inherit'));
    if (!in_array($urlSeparator, ['inherit', '-', '_'], true)) {
        $urlSeparator = 'inherit';
    }
    $channelUrlSeparators[$optionSlug] = $urlSeparator;
}
$selectedChannelEditorOverride = (string) ($channelEditorOverrides[$selectedChannelSlug] ?? 'inherit');
$effectiveTextEditor = $selectedChannelEditorOverride === 'inherit'
    ? $editorDefault
    : $selectedChannelEditorOverride;
$selectedChannelRouteMode = (string) ($channelRouteModes[$selectedChannelSlug] ?? 'inherit');
$effectivePageRouteMode = $selectedChannelSlug === '' || $selectedChannelRouteMode === 'inherit'
    ? $routeModeDefault
    : $selectedChannelRouteMode;
$selectedChannelUrlSeparator = (string) ($channelUrlSeparators[$selectedChannelSlug] ?? 'inherit');
$effectiveChannelUrlSeparator = $selectedChannelUrlSeparator === 'inherit'
    ? $routeSeparatorDefault
    : $selectedChannelUrlSeparator;
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['content', 'meta', 'media'], true) ? $requestedTab : 'content';
$maxFilesPerUploadNote = $imageMaxFilesPerUpload > 0
    ? 'max ' . $imageMaxFilesPerUpload . ' per upload'
    : 'no limit per upload';

// Build a permalink preview for published pages shown in the editor header area.
$pageSlug = trim((string) ($page['slug'] ?? ''));
$pageId = (int) ($page['id'] ?? 0);
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$permalinkBase = $normalizedDomain;
if ($permalinkBase !== '' && !preg_match('#^https?://#i', $permalinkBase)) {
    $permalinkBase = 'https://' . $permalinkBase;
}
$permalinkBase = rtrim($permalinkBase, '/');
$permalinkPathParts = [];
$routeSegment = trim(
    \Raven\Lib\Routing\ChannelRoutePolicy::buildRouteSegment(
        new \Raven\Lib\Security\InputSanitizer(),
        $pageSlug,
        $pageId,
        (string) ($page['created_at'] ?? ''),
        $effectivePageRouteMode,
        $selectedChannelUrlSeparator,
        $routeSeparatorDefault
    ),
    '/'
);
if ($selectedChannelSlug !== '') {
    $permalinkPathParts[] = trim($selectedChannelSlug, '/');
}
if ($routeSegment !== '') {
    $permalinkPathParts[] = $routeSegment;
}
$publishedPermalink = null;
if ($selectedStatus === 'published' && $permalinkBase !== '' && $routeSegment !== '') {
    $publishedPermalink = $permalinkBase . '/' . implode('/', $permalinkPathParts);
}

// Prepare a compact JSON payload used by TinyMCE custom gallery button.
$tinyMceGalleryItems = [];
foreach ($galleryImages as $galleryImage) {
    if ((string) ($galleryImage['status'] ?? '') !== 'ready') {
        continue;
    }
    if (array_key_exists('include_in_gallery', $galleryImage) && empty($galleryImage['include_in_gallery'])) {
        continue;
    }

    $variants = is_array($galleryImage['variants'] ?? null) ? $galleryImage['variants'] : [];

    $tinyMceGalleryItems[] = [
        'id' => (int) ($galleryImage['id'] ?? 0),
        'label' => (string) (($galleryImage['title_text'] ?? '') !== '' ? $galleryImage['title_text'] : ($galleryImage['original_filename'] ?? 'Image')),
        'alt_text' => (string) ($galleryImage['alt_text'] ?? ''),
        'caption' => (string) ($galleryImage['caption'] ?? ''),
        'variants' => [
            'original' => (string) ($galleryImage['url'] ?? ''),
            'sm' => (string) (($variants['sm']['url'] ?? '') ?: ''),
            'md' => (string) (($variants['md']['url'] ?? '') ?: ''),
            'lg' => (string) (($variants['lg']['url'] ?? '') ?: ''),
        ],
    ];
}
$bodyBlocks = [];
$rawContentBlocks = $page['content_blocks'] ?? null;
if (is_array($rawContentBlocks)) {
    foreach ($rawContentBlocks as $entry) {
        $type = 'tinymce';
        $content = '';
        $cssId = '';
        $cssClass = '';

        if (is_array($entry)) {
            $type = strtolower(trim((string) ($entry['type'] ?? 'tinymce')));
            if (!array_key_exists($type, $bodyBlockTypeDefinitions)) {
                $type = 'tinymce';
            }

            $rawContent = $entry['content'] ?? '';
            if (!is_scalar($rawContent) && $rawContent !== null) {
                continue;
            }
            $content = (string) ($rawContent ?? '');
            $cssId = trim((string) ($entry['css_id'] ?? ''));
            $cssClass = trim((string) ($entry['css_class'] ?? ''));
        } else {
            if (!is_scalar($entry) && $entry !== null) {
                continue;
            }
            $content = (string) ($entry ?? '');
        }

        if ($type === 'image_gallery') {
            $bodyBlocks[] = [
                'type' => 'image_gallery',
                'content' => '',
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
            continue;
        }

        if (trim($content) === '') {
            continue;
        }

        $bodyBlocks[] = [
            'type' => $type,
            'content' => $content,
            'css_id' => $cssId,
            'css_class' => $cssClass,
        ];
    }
}
$pageTitle = trim((string) ($page['title'] ?? ''));
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $page === null ? 'Create New Page' : 'Edit Page: <span class="text-primary">\'' . e($pageTitle !== '' ? $pageTitle : 'Untitled') . '\'</span>' ?>
        </h1>
        <?php if ($page === null): ?>
            <p class="text-muted mb-0">Create or update page content, metadata, and gallery media.</p>
        <?php endif; ?>

        <?php if ($publishedPermalink !== null): ?>
            <!-- Published pages show a direct public permalink for quick verification. -->
            <p class="mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($publishedPermalink) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($publishedPermalink) ?>"
                    aria-label="Open published URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($publishedPermalink) ?>
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

<?php if ($hasPersistedPage): ?>
<!-- Standalone delete form avoids nesting forms and keeps CSRF enforcement intact. -->
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/page/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $pageId ?>">
</form>
<?php endif; ?>

<form id="page-edit-form" method="post" action="<?= e($panelBase) ?>/page/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $pageId ?>">
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Page</button>
        <a href="<?= e($panelBase) ?>/page" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Pages</a>
        <?php if ($hasPersistedPage): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this page?');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Page</button>
        <?php endif; ?>
    </nav>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'content' ? ' active' : '' ?>"
                id="page-content-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-content"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-content"
                aria-selected="<?= $activeTab === 'content' ? 'true' : 'false' ?>"
            >Content</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'media' ? ' active' : '' ?>"
                id="page-media-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-media"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-media"
                aria-selected="<?= $activeTab === 'media' ? 'true' : 'false' ?>"
            >Media</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'meta' ? ' active' : '' ?>"
                id="page-meta-tab"
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
                    class="tab-pane fade<?= $activeTab === 'content' ? ' show active' : '' ?>"
                    id="rvnp-editor-pane-content"
                    role="tabpanel"
                    aria-labelledby="page-content-tab"
                    tabindex="0"
                >
                    <div class="form-group">
                        <label for="title" class="form-label">Title</label>
                        <div class="input-group">
                            <input id="title" name="title" class="form-control" required value="<?= e((string) ($page['title'] ?? '')) ?>">
                            <span class="input-group-text">
                                <input
                                    id="display_title"
                                    name="display_title"
                                    class="form-check-input mt-0 me-2"
                                    type="checkbox"
                                    value="1"
                                    aria-label="Display title on page"
                                    <?= $displayTitle ? 'checked' : '' ?>
                                >
                                <label class="mb-0" for="display_title">Display title?</label>
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="slug" class="form-label">Slug</label>
                        <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($page['slug'] ?? '')) ?>">
                    </div>

                    <div class="mb-0">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <label class="form-label mb-0">Body Blocks</label>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    id="page-text-block-add"
                                    data-rvn-default-text-editor="<?= e($effectiveTextEditor) ?>"
                                    data-rvn-site-editor-default="<?= e($editorDefault) ?>"
                                >Add Text Block</button>
                                <div class="dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm dropdown-toggle"
                                        id="page-body-block-add"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >Add Body Block</button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="page-body-block-add">
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="image_gallery">Image Gallery</button></li>
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="markdown">Markdown Field</button></li>
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="markdown_file">Markdown File</button></li>
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="plaintext">Plain Text Field</button></li>
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="autobr">Plain Text Auto &lt;br&gt;</button></li>
                                        <li><button type="button" class="dropdown-item" data-rvn-add-body-type="tinymce">Rich Text Area</button></li>
                                        <?php if ($customAddBodyItems !== []): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php foreach ($customAddBodyItems as $customItem): ?>
                                                <li>
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        data-rvn-add-body-type="<?= e((string) ($customItem['type'] ?? '')) ?>"
                                                    ><?= e((string) ($customItem['label'] ?? 'Custom')) ?> Block</button>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="form-text mb-2">Build page bodies from modular blocks.</div>
                        <div id="page-body-blocks-list">
                            <?php foreach ($bodyBlocks as $bodyIndex => $bodyBlock): ?>
                                <?php
                                $blockType = strtolower(trim((string) ($bodyBlock['type'] ?? 'tinymce')));
                                if (!array_key_exists($blockType, $bodyBlockTypeDefinitions)) {
                                    $blockType = 'tinymce';
                                }
                                $blockContent = (string) ($bodyBlock['content'] ?? '');
                                $blockCssId = trim((string) ($bodyBlock['css_id'] ?? ''));
                                $blockCssClass = trim((string) ($bodyBlock['css_class'] ?? ''));
                                $blockEditor = strtolower(trim((string) ($bodyBlockTypeDefinitions[$blockType]['editor'] ?? 'tinymce')));
                                $blockTypeLabel = (string) ($bodyBlockTypeDefinitions[$blockType]['label'] ?? 'Rich Text');
                                $isPathBlock = $blockEditor === 'markdown_file';
                                $isGalleryBlock = $blockEditor === 'gallery';
                                ?>
                                <div class="border rounded p-3 mb-3" data-rvn-body-row="1">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="text-muted drag" title="Drag to reorder" aria-hidden="true" data-rvn-body-drag-handle="1" draggable="true"><i class="bi bi-grip-vertical"></i></span>
                                            <input
                                                type="text"
                                                class="form-control form-control-sm"
                                                style="width: 7rem;"
                                                placeholder="#id"
                                                value="<?= e($blockCssId) ?>"
                                                data-rvn-body-css-id="1"
                                                name="content_blocks[<?= (int) $bodyIndex ?>][css_id]"
                                            >
                                            <small class="text-danger d-none" data-rvn-body-css-id-error="1">Can only enter one id!</small>
                                            <input
                                                type="text"
                                                class="form-control form-control-sm"
                                                style="width: 7rem;"
                                                placeholder=".class1 .class2"
                                                value="<?= e($blockCssClass) ?>"
                                                data-rvn-body-css-class="1"
                                                name="content_blocks[<?= (int) $bodyIndex ?>][css_class]"
                                            >
                                            <span class="badge text-bg-secondary" data-rvn-body-type-label="1"><?= $blockTypeLabel ?></span>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-sm" data-rvn-body-remove="1" aria-label="Remove block" title="Remove block"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                    </div>
                                    <input type="hidden" data-rvn-body-type-input="1" name="content_blocks[<?= (int) $bodyIndex ?>][type]" value="<?= e($blockType) ?>">
                                    <textarea
                                        id="body_block_<?= (int) $bodyIndex ?>"
                                        name="<?= $isGalleryBlock || $isPathBlock ? '' : 'content_blocks[' . (int) $bodyIndex . '][content]' ?>"
                                        class="form-control"
                                        rows="10"
                                        data-rvn-body-content="1"
                                        <?= $isGalleryBlock || $isPathBlock ? 'style="display:none;"' : '' ?>
                                    ><?= e($isGalleryBlock || $isPathBlock ? '' : $blockContent) ?></textarea>
                                    <input
                                        type="text"
                                        class="form-control mt-2"
                                        id="body_block_<?= (int) $bodyIndex ?>_path"
                                        name="<?= $isPathBlock ? 'content_blocks[' . (int) $bodyIndex . '][content]' : '' ?>"
                                        value="<?= e($isPathBlock ? $blockContent : '') ?>"
                                        placeholder="/notes/example.md"
                                        data-rvn-body-content-path="1"
                                        <?= $isPathBlock ? '' : 'style="display:none;"' ?>
                                    >
                                    <div class="form-text mt-2" data-rvn-body-gallery-help="1"<?= $isGalleryBlock ? '' : ' style="display:none;"' ?>>
                                        Inserts this page's gallery in public output. Manage images from the Media tab.
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div
                    class="tab-pane fade<?= $activeTab === 'meta' ? ' show active' : '' ?>"
                    id="rvnp-editor-pane-meta"
                    role="tabpanel"
                    aria-labelledby="page-meta-tab"
                    tabindex="0"
                >
                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="published"<?= $selectedStatus === 'published' ? ' selected' : '' ?>>Published</option>
                            <option value="draft"<?= $selectedStatus === 'draft' ? ' selected' : '' ?>>Draft</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"><?= e((string) ($page['description'] ?? '')) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="author_user_id" class="form-label">Author</label>
                        <select id="author_user_id" name="author_user_id" class="form-select">
                            <?php foreach ($authorOptions as $authorOption): ?>
                                <?php
                                $authorId = (int) ($authorOption['id'] ?? 0);
                                if ($authorId < 1) {
                                    continue;
                                }
                                $authorUsername = trim((string) ($authorOption['username'] ?? ''));
                                if ($authorUsername === '') {
                                    continue;
                                }
                                $authorDisplayName = trim((string) ($authorOption['display_name'] ?? ''));
                                ?>
                                <option value="<?= $authorId ?>"<?= $selectedAuthorUserId === $authorId ? ' selected' : '' ?>>
                                    <?= e($authorDisplayName !== '' ? $authorDisplayName : $authorUsername) ?> (<?= e($authorUsername) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Defaults to the creator account for new pages. Override here to change public author metadata.</div>
                    </div>

                    <div class="form-group">
                        <label for="channel_slug" class="form-label">Channel</label>
                        <select id="channel_slug" name="channel_slug" class="form-select">
                            <option
                                value=""
                                data-rvn-channel-category-sets="<?= e(implode(',', array_map('strval', $defaultCategorySetSelection))) ?>"
                                data-rvn-channel-tag-sets="<?= e(implode(',', array_map('strval', $defaultTagSetSelection))) ?>"
                                <?= $selectedChannelSlug === '' ? ' selected' : '' ?>
                            >&lt;none&gt;</option>
                            <?php foreach ($channelOptions as $channel): ?>
                                <?php $slug = (string) ($channel['slug'] ?? ''); ?>
                                <?php
                                $channelEditorOverride = strtolower(trim((string) ($channel['editor_override'] ?? 'inherit')));
                                if (!in_array($channelEditorOverride, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)) {
                                    $channelEditorOverride = 'inherit';
                                }
                                $channelRouteMode = strtolower(trim((string) ($channel['route_mode'] ?? 'inherit')));
                                if (!in_array($channelRouteMode, ['inherit', 'slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'], true)) {
                                    $channelRouteMode = 'inherit';
                                }
                                $channelUrlSeparator = trim((string) ($channel['route_separator'] ?? 'inherit'));
                                if (!in_array($channelUrlSeparator, ['inherit', '-', '_'], true)) {
                                    $channelUrlSeparator = 'inherit';
                                }
                                ?>
                                <option
                                    value="<?= e($slug) ?>"
                                    data-rvn-channel-editor-override="<?= e($channelEditorOverride) ?>"
                                    data-rvn-channel-route-mode="<?= e($channelRouteMode) ?>"
                                    data-rvn-channel-route-separator="<?= e($channelUrlSeparator) ?>"
                                    data-rvn-channel-category-sets="<?= e(implode(',', array_map('strval', is_array($channel['category_sets'] ?? null) ? $channel['category_sets'] : $defaultCategorySetSelection))) ?>"
                                    data-rvn-channel-tag-sets="<?= e(implode(',', array_map('strval', is_array($channel['tag_sets'] ?? null) ? $channel['tag_sets'] : $defaultTagSetSelection))) ?>"
                                    <?= $selectedChannelSlug === $slug ? ' selected' : '' ?>
                                >
                                    <?= e((string) ($channel['name'] ?? $slug)) ?> (<?= e($slug) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($categoryEnabled): ?>
                    <div class="form-group" data-rvn-chip-picker="category">
                        <label class="form-label" for="add-category-button">Categories</label>
                        <div class="d-flex flex-wrap align-items-center gap-2" data-rvn-chip-list="category">
                            <?php foreach ($categoryOptionsSelected as $category): ?>
                                <span class="badge text-bg-primary d-inline-flex align-items-center gap-2" data-rvn-chip-id="<?= (int) $category['id'] ?>" data-rvn-chip-set-id="<?= (int) ($category['set_id'] ?? 0) ?>">
                                    <span><?= e((string) $category['name']) ?></span>
                                    <button type="button" class="btn btn-sm p-0 border-0 text-white" data-rvn-chip-remove="category" aria-label="Remove category">&times;</button>
                                    <input type="hidden" name="category_ids[]" value="<?= (int) $category['id'] ?>">
                                </span>
                            <?php endforeach; ?>

                            <div class="dropdown">
                                <button id="add-category-button" class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Add Category
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($categoryOptionsAll === []): ?>
                                        <li><span class="dropdown-item-text text-muted">No categories available</span></li>
                                    <?php else: ?>
                                        <?php foreach ($categoryOptionsAll as $categoryOption): ?>
                                            <?php
                                            $categoryId = (int) ($categoryOption['id'] ?? 0);
                                            $categoryName = (string) ($categoryOption['name'] ?? '');
                                            $categorySlug = (string) ($categoryOption['slug'] ?? '');
                                            ?>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-rvn-add-chip="category"
                                                    data-rvn-option-id="<?= $categoryId ?>"
                                                    data-rvn-option-label="<?= e($categoryName) ?>"
                                                    data-rvn-option-set-id="<?= (int) ($categoryOption['set_id'] ?? 0) ?>"
                                                >
                                                    <?= e($categoryName) ?><?= $categorySlug !== '' ? ' (' . e($categorySlug) . ')' : '' ?>
                                                </button>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="form-text">Assign zero or more categories to this page.</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tagEnabled): ?>
                    <div class="form-group" data-rvn-chip-picker="tag">
                        <label class="form-label" for="add-tag-button">Tags</label>
                        <div class="d-flex flex-wrap align-items-center gap-2" data-rvn-chip-list="tag">
                            <?php foreach ($tagOptionsSelected as $tag): ?>
                                <span class="badge text-bg-secondary d-inline-flex align-items-center gap-2" data-rvn-chip-id="<?= (int) $tag['id'] ?>" data-rvn-chip-set-id="<?= (int) ($tag['set_id'] ?? 0) ?>">
                                    <span><?= e((string) $tag['name']) ?></span>
                                    <button type="button" class="btn btn-sm p-0 border-0 text-white" data-rvn-chip-remove="tag" aria-label="Remove tag">&times;</button>
                                    <input type="hidden" name="tag_ids[]" value="<?= (int) $tag['id'] ?>">
                                </span>
                            <?php endforeach; ?>

                            <div class="dropdown">
                                <button id="add-tag-button" class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Add Tag
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($tagOptionsAll === []): ?>
                                        <li><span class="dropdown-item-text text-muted">No tags available</span></li>
                                    <?php else: ?>
                                        <?php foreach ($tagOptionsAll as $tagOption): ?>
                                            <?php
                                            $tagId = (int) ($tagOption['id'] ?? 0);
                                            $tagName = (string) ($tagOption['name'] ?? '');
                                            $tagSlug = (string) ($tagOption['slug'] ?? '');
                                            ?>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-rvn-add-chip="tag"
                                                    data-rvn-option-id="<?= $tagId ?>"
                                                    data-rvn-option-label="<?= e($tagName) ?>"
                                                    data-rvn-option-set-id="<?= (int) ($tagOption['set_id'] ?? 0) ?>"
                                                >
                                                    <?= e($tagName) ?><?= $tagSlug !== '' ? ' (' . e($tagSlug) . ')' : '' ?>
                                                </button>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="form-text">Assign zero or more tags to this page.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div
                    class="tab-pane fade<?= $activeTab === 'media' ? ' show active' : '' ?>"
                    id="rvnp-editor-pane-media"
                    role="tabpanel"
                    aria-labelledby="page-media-tab"
                    tabindex="0"
                >
                    <?php if (!$hasPersistedPage): ?>
                        <div class="alert alert-info mb-0" role="alert">
                            Save this page first, then use the Media tab to upload and manage gallery images.
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div class="text-muted small">
                                Storage target: <code><?= e($imageUploadTarget) ?></code><br>
                                Add an <strong>Image Gallery</strong> block from the Content tab to display this gallery publicly.
                            </div>
                        </div>

                        <div class="form-group border rounded p-3">
                            <label class="form-label" for="gallery_upload_image">Upload Image</label>
                            <div
                                id="gallery_drop_zone"
                                class="border rounded p-3 mb-2 bg-light position-relative overflow-hidden"
                                style="border-style: dashed;"
                                aria-label="Drag images here or click to browse files"
                            >
                                <input
                                    id="gallery_upload_image"
                                    type="file"
                                    name="gallery_upload_image[]"
                                    class="position-absolute top-0 start-0 w-100 h-100 opacity-0"
                                    style="cursor: pointer; z-index: 2;"
                                    multiple
                                    data-rvn-max-files="<?= (int) $imageMaxFilesPerUpload ?>"
                                    accept=".gif,.jpg,.jpeg,.png,image/gif,image/jpeg,image/png"
                                >
                                <div class="small text-muted fw-semibold text-center">Drag and drop images here, or click to browse</div>
                            </div>
                            <div id="gallery_upload_queue" class="d-none"></div>
                            <div id="gallery_upload_selection" class="form-text fw-semibold mb-2">No files selected.</div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button
                                    id="gallery_upload_submit"
                                    type="submit"
                                    class="btn btn-primary btn-sm"
                                    formaction="<?= e($panelBase) ?>/page/gallery/upload"
                                    formmethod="post"
                                    formenctype="multipart/form-data"
                                    formnovalidate
                                >
                                    Upload Image(s)
                                </button>
                                <button id="gallery_upload_clear" type="button" class="btn btn-secondary btn-sm">Clear Queue</button>
                            </div>
                            <div id="gallery_upload_client_error" class="form-text text-danger d-none"></div>
                            <div class="form-text">
                                You can select multiple files (<?= e($maxFilesPerUploadNote) ?>).<br>
                                Uploaded images are processed with ImageMagick and thumbnails are generated automatically.<br>
                                Drag-and-drop doesn't work on all browsers, so you may have to click on it.
                            </div>
                        </div>

                        <?php if ($galleryImages === []): ?>
                            <p class="text-muted mb-0">No gallery images uploaded yet.</p>
                        <?php else: ?>
                            <div class="d-flex justify-content-end gap-2 mb-3">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-rvn-gallery-select-all
                                >
                                    Select All
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-rvn-gallery-clear-all
                                >
                                    Clear All
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-danger"
                                    name="gallery_delete_selected"
                                    value="1"
                                    data-rvn-gallery-delete-selected
                                    formaction="<?= e($panelBase) ?>/page/gallery/delete"
                                    formmethod="post"
                                    formnovalidate
                                >
                                    <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
                                </button>
                            </div>

                            <div id="page-gallery-images-list">
                                <?php foreach ($galleryImages as $galleryImage): ?>
                                    <?php
                                    $imageId = (int) ($galleryImage['id'] ?? 0);
                                    $variants = is_array($galleryImage['variants'] ?? null) ? $galleryImage['variants'] : [];
                                    $previewUrl = (string) (($variants['sm']['url'] ?? '') ?: ($galleryImage['url'] ?? ''));
                                    $caption = (string) ($galleryImage['caption'] ?? '');
                                    $isCover = !empty($galleryImage['is_cover']);
                                    $includeInGallery = array_key_exists('include_in_gallery', $galleryImage)
                                        ? !empty($galleryImage['include_in_gallery'])
                                        : true;
                                    $sharedAltTitle = (string) (($galleryImage['alt_text'] ?? '') !== ''
                                        ? $galleryImage['alt_text']
                                        : ($galleryImage['title_text'] ?? ''));
                                    ?>
                                    <div class="border rounded p-3 mb-3" data-rvn-gallery-row>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="d-flex align-items-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-link btn-sm text-muted p-0 border-0 lh-1"
                                                    data-rvn-gallery-drag-handle
                                                    title="Drag to reorder"
                                                    aria-label="Drag to reorder"
                                                >
                                                    <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                            <div class="form-check mb-0">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    id="gallery_select_<?= $imageId ?>"
                                                    name="gallery_delete_image_ids[]"
                                                    value="<?= $imageId ?>"
                                                    data-rvn-gallery-select
                                                >
                                                <label class="form-check-label small" for="gallery_select_<?= $imageId ?>">Select</label>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-12 col-lg-3">
                                                <?php if ($previewUrl !== ''): ?>
                                                    <img src="<?= e($previewUrl) ?>" class="img-fluid rounded border" alt="<?= e((string) ($galleryImage['alt_text'] ?? '')) ?>">
                                                <?php else: ?>
                                                    <div class="bg-light border rounded p-4 text-muted small">No preview</div>
                                                <?php endif; ?>
                                                <div class="form-text mt-2">
                                                    <div><strong><?= e((string) ($galleryImage['original_filename'] ?? 'image')) ?></strong></div>
                                                    <div><?= (int) ($galleryImage['width'] ?? 0) ?>x<?= (int) ($galleryImage['height'] ?? 0) ?> px</div>
                                                    <div>Status: <?= e((string) ($galleryImage['status'] ?? 'unknown')) ?></div>
                                                </div>
                                            </div>

                                            <div class="col-12 col-lg-9">
                                                <div class="row g-2 mb-2">
                                                    <div class="col-12">
                                                        <label class="form-label" for="gallery_alt_<?= $imageId ?>">Alt / Title</label>
                                                        <input
                                                            id="gallery_alt_<?= $imageId ?>"
                                                            type="text"
                                                            class="form-control"
                                                            name="gallery_images[<?= $imageId ?>][alt_text]"
                                                            value="<?= e($sharedAltTitle) ?>"
                                                        >
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label" for="gallery_caption_<?= $imageId ?>">Caption</label>
                                                        <textarea id="gallery_caption_<?= $imageId ?>" class="form-control" name="gallery_images[<?= $imageId ?>][caption]" rows="2"><?= e($caption) ?></textarea>
                                                    </div>
                                                </div>
                                                <input
                                                    id="gallery_sort_<?= $imageId ?>"
                                                    type="hidden"
                                                    name="gallery_images[<?= $imageId ?>][sort_order]"
                                                    value="<?= (int) ($galleryImage['sort_order'] ?? 1) ?>"
                                                >

                                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                                    <div class="d-flex flex-wrap gap-3">
                                                        <div class="form-check mb-0">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                name="gallery_images[<?= $imageId ?>][is_cover]"
                                                                id="gallery_cover_<?= $imageId ?>"
                                                                data-rvn-gallery-single="cover"
                                                                value="1"
                                                                <?= $isCover ? 'checked' : '' ?>
                                                            >
                                                            <label class="form-check-label" for="gallery_cover_<?= $imageId ?>">Use as cover image</label>
                                                        </div>
                                                        <div class="form-check mb-0">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                name="gallery_images[<?= $imageId ?>][include_in_gallery]"
                                                                id="gallery_include_<?= $imageId ?>"
                                                                value="1"
                                                                <?= $includeInGallery ? 'checked' : '' ?>
                                                            >
                                                            <label class="form-check-label" for="gallery_include_<?= $imageId ?>">Include in gallery</label>
                                                        </div>
                                                    </div>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger btn-sm"
                                                        name="gallery_delete_image_id"
                                                        value="<?= $imageId ?>"
                                                        formaction="<?= e($panelBase) ?>/page/gallery/delete"
                                                        formmethod="post"
                                                        formnovalidate
                                                        onclick="return confirm('Delete this image and all generated variants?');"
                                                    >
                                                        <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Image
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-rvn-gallery-select-all
                                >
                                    Select All
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-rvn-gallery-clear-all
                                >
                                    Clear All
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-danger"
                                    name="gallery_delete_selected"
                                    value="1"
                                    data-rvn-gallery-delete-selected
                                    formaction="<?= e($panelBase) ?>/page/gallery/delete"
                                    formmethod="post"
                                    formnovalidate
                                >
                                    <i class="bi bi-x-square me-2" aria-hidden="true"></i>Delete Selected
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Page</button>
        <a href="<?= e($panelBase) ?>/page" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Pages</a>
        <?php if ($hasPersistedPage): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this page?');"
            ><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Page</button>
        <?php endif; ?>
    </nav>
</form>

<template id="page-body-block-template">
    <div class="border rounded p-3 mb-3" data-rvn-body-row="1">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted drag" title="Drag to reorder" aria-hidden="true" data-rvn-body-drag-handle="1" draggable="true"><i class="bi bi-grip-vertical"></i></span>
                <input
                    type="text"
                    class="form-control form-control-sm"
                    style="width: 7rem;"
                    placeholder="#id"
                    data-rvn-body-css-id="1"
                >
                <small class="text-danger d-none" data-rvn-body-css-id-error="1">Can only enter one id!</small>
                <input
                    type="text"
                    class="form-control form-control-sm"
                    style="width: 7rem;"
                    placeholder=".class1 .class2"
                    data-rvn-body-css-class="1"
                >
                <span class="badge text-bg-secondary" data-rvn-body-type-label="1">Rich Text</span>
            </div>
            <button type="button" class="btn btn-danger btn-sm" data-rvn-body-remove="1" aria-label="Remove block" title="Remove block"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
        </div>
        <input type="hidden" value="tinymce" data-rvn-body-type-input="1">
        <textarea
            class="form-control"
            rows="10"
            data-rvn-body-content="1"
        ></textarea>
        <input
            type="text"
            class="form-control mt-2"
            placeholder="/notes/example.md"
            data-rvn-body-content-path="1"
            style="display:none;"
        >
        <div class="form-text mt-2" data-rvn-body-gallery-help="1" style="display:none;">
            Inserts this page's gallery in public output. Manage images from the Media tab.
        </div>
    </div>
</template>

<style>
  /* Match body-block row styling to Media file rows. */
  body#rvnp #rvnp-editor-pane-content [data-rvn-body-row] {
    border-color: var(--raven-border) !important;
    background: var(--raven-surface-soft);
    padding: 0.9rem !important;
  }

  /* Match TinyMCE frame border/radius with Bootstrap form-control styling. */
  .tox.tox-tinymce {
    border: var(--bs-border-width, 1px) solid var(--raven-border) !important;
    border-radius: var(--bs-border-radius, 0.375rem) !important;
    background-color: var(--bs-body-bg, #fff);
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    box-shadow: none !important;
    --tox-border-color: var(--bs-border-color, #dee2e6);
  }

  .tox.tox-tinymce.tox-tinymce--focused,
  .tox.tox-tinymce:focus-within,
  .tox.tox-tinymce.tox-edit-focus {
    color: var(--bs-body-color);
    background-color: var(--bs-body-bg);
    border-color: var(--raven-accent) !important;
    box-shadow: 0 0 0 0.2rem var(--raven-accent-soft) !important;
    outline: 0 !important;
  }

  /* Disable TinyMCE inner edit-area focus outline so focus matches form-control intensity. */
  .tox .tox-edit-area::before {
    border: 0 !important;
    box-shadow: none !important;
    opacity: 0 !important;
  }

  /* Disable TinyMCE nav-object focus overlay to avoid a second brighter blue border. */
  .tox .tox-navobj-bordered-focus.tox-navobj-bordered::before {
    border-color: transparent !important;
    box-shadow: none !important;
    opacity: 0 !important;
  }

  /* Neutralize TinyMCE's internal bright-blue focus styles inside the edit area. */
  .tox.tox-tinymce .tox-custom-editor:focus-within,
  .tox.tox-tinymce .tox-textarea-wrap:focus-within,
  .tox.tox-tinymce .tox-textarea:focus,
  .tox.tox-tinymce .tox-focusable-wrapper:focus {
    border-color: transparent !important;
    box-shadow: none !important;
    outline: none !important;
  }

  /* Keep TinyMCE dark chrome aligned with Raven midnight palette. */
  body#rvnp.theme-dark .tox.tox-tinymce {
    background-color: var(--raven-surface);
    color: var(--raven-ink);
    box-shadow: none !important;
  }

  body#rvnp.theme-dark .tox .tox-editor-header {
    box-shadow: none !important;
  }

  body#rvnp.theme-dark .tox .tox-editor-header,
  body#rvnp.theme-dark .tox .tox-toolbar,
  body#rvnp.theme-dark .tox .tox-toolbar__overflow,
  body#rvnp.theme-dark .tox .tox-toolbar-overlord,
  body#rvnp.theme-dark .tox .tox-toolbar__primary,
  body#rvnp.theme-dark .tox .tox-toolbar__group {
    background: var(--raven-surface-softer) !important;
    background-image: none !important;
    border-color: var(--raven-border) !important;
    color: var(--raven-ink);
  }

  body#rvnp.theme-dark .tox .tox-tbtn,
  body#rvnp.theme-dark .tox .tox-mbtn,
  body#rvnp.theme-dark .tox .tox-tbtn__select-label {
    background: transparent !important;
    background-image: none !important;
    border-color: transparent !important;
    box-shadow: none !important;
    color: var(--raven-ink) !important;
  }

  body#rvnp.theme-dark .tox .tox-tbtn svg,
  body#rvnp.theme-dark .tox .tox-mbtn svg,
  body#rvnp.theme-dark .tox .tox-tbtn .tox-icon svg {
    fill: currentColor !important;
  }

  body#rvnp.theme-dark .tox .tox-tbtn:hover,
  body#rvnp.theme-dark .tox .tox-mbtn:hover,
  body#rvnp.theme-dark .tox .tox-mbtn:focus-visible,
  body#rvnp.theme-dark .tox .tox-tbtn:focus-visible,
  body#rvnp.theme-dark .tox .tox-tbtn--enabled:hover {
    background: var(--raven-accent-soft) !important;
    background-image: none !important;
    border-color: transparent !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-tbtn--enabled,
  body#rvnp.theme-dark .tox .tox-tbtn--enabled:focus-visible {
    background: #46628d !important;
    background-image: none !important;
    border-color: #46628d !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-split-button,
  body#rvnp.theme-dark .tox .tox-tbtn.tox-split-button__chevron {
    border-color: var(--raven-border) !important;
  }

  body#rvnp.theme-dark .tox .tox-edit-area,
  body#rvnp.theme-dark .tox .tox-edit-area__iframe {
    background: var(--raven-surface) !important;
    border-color: var(--raven-border) !important;
  }

  body#rvnp.theme-dark .tox .tox-statusbar {
    background: var(--raven-surface-soft) !important;
    border-top-color: var(--raven-border) !important;
    color: var(--raven-muted) !important;
  }

  body#rvnp.theme-dark .tox .tox-statusbar a,
  body#rvnp.theme-dark .tox .tox-statusbar__path-item,
  body#rvnp.theme-dark .tox .tox-statusbar__wordcount {
    color: var(--raven-muted) !important;
  }

  body#rvnp.theme-dark .tox .tox-menubar,
  body#rvnp.theme-dark .tox .tox-collection,
  body#rvnp.theme-dark .tox .tox-menu,
  body#rvnp.theme-dark .tox .tox-collection--list,
  body#rvnp.theme-dark .tox .tox-dialog,
  body#rvnp.theme-dark .tox .tox-dialog__header,
  body#rvnp.theme-dark .tox .tox-dialog__body-content,
  body#rvnp.theme-dark .tox .tox-dialog__footer {
    background: var(--raven-surface) !important;
    border-color: var(--raven-border) !important;
    color: var(--raven-ink) !important;
  }

  body#rvnp.theme-dark .tox .tox-collection__item,
  body#rvnp.theme-dark .tox .tox-collection__item-label,
  body#rvnp.theme-dark .tox .tox-label {
    color: var(--raven-ink) !important;
  }

  body#rvnp.theme-dark .tox .tox-dialog input,
  body#rvnp.theme-dark .tox .tox-dialog textarea,
  body#rvnp.theme-dark .tox .tox-dialog select,
  body#rvnp.theme-dark .tox .tox-textfield,
  body#rvnp.theme-dark .tox .tox-textarea,
  body#rvnp.theme-dark .tox .tox-listboxfield .tox-listbox--select {
    background: var(--raven-surface-softer) !important;
    border-color: var(--raven-border) !important;
    color: var(--raven-ink) !important;
  }

  body#rvnp.theme-dark .tox .tox-dialog input:focus,
  body#rvnp.theme-dark .tox .tox-dialog textarea:focus,
  body#rvnp.theme-dark .tox .tox-dialog select:focus,
  body#rvnp.theme-dark .tox .tox-textfield:focus,
  body#rvnp.theme-dark .tox .tox-textarea:focus,
  body#rvnp.theme-dark .tox .tox-listboxfield .tox-listbox--select:focus {
    border-color: #46628d !important;
    box-shadow: 0 0 0 0.2rem rgba(70, 98, 141, 0.24) !important;
    outline: none !important;
  }

  body#rvnp.theme-dark .tox .tox-button {
    background: #46628d !important;
    background-image: none !important;
    border-color: #46628d !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-button:hover,
  body#rvnp.theme-dark .tox .tox-button:focus-visible {
    background: #5374a2 !important;
    background-image: none !important;
    border-color: #5374a2 !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-button:active {
    background: #3b547b !important;
    background-image: none !important;
    border-color: #3b547b !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-button--secondary,
  body#rvnp.theme-dark .tox .tox-button.tox-button--secondary {
    background: var(--raven-surface-softer) !important;
    background-image: none !important;
    border-color: var(--raven-border) !important;
    box-shadow: none !important;
    color: var(--raven-ink) !important;
  }

  body#rvnp.theme-dark .tox .tox-button--secondary:hover,
  body#rvnp.theme-dark .tox .tox-button.tox-button--secondary:hover,
  body#rvnp.theme-dark .tox .tox-button--secondary:focus-visible,
  body#rvnp.theme-dark .tox .tox-button.tox-button--secondary:focus-visible {
    background: var(--raven-accent-soft) !important;
    background-image: none !important;
    border-color: #46628d !important;
    box-shadow: none !important;
    color: #f0f6fc !important;
  }

  body#rvnp.theme-dark .tox .tox-collection__item:hover,
  body#rvnp.theme-dark .tox .tox-collection__item--active,
  body#rvnp.theme-dark .tox .tox-collection__item--enabled {
    background: var(--raven-accent-soft) !important;
    color: #f0f6fc !important;
  }

  .raven-editor-color-dropdown {
    position: absolute;
    z-index: 2147483647;
    width: 320px;
    padding: 0.75rem;
    border-radius: 0.5rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #212529);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.16);
  }

  .raven-editor-color-dropdown[hidden] {
    display: none !important;
  }

  .raven-editor-color-picker {
    display: flex;
    gap: 0.5rem;
    align-items: stretch;
    margin-bottom: 0.5rem;
  }

  .raven-editor-color-sv,
  .raven-editor-color-hue {
    display: block;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.375rem;
    touch-action: none;
  }

  .raven-editor-color-sv {
    width: 240px;
    height: 146px;
    flex: 1 1 auto;
    cursor: crosshair;
  }

  .raven-editor-color-hue {
    width: 20px;
    height: 146px;
    flex: 0 0 20px;
    cursor: ns-resize;
  }

  .raven-editor-color-preview {
    display: inline-block;
    width: 1.75rem;
    height: 1.75rem;
    min-width: 1.75rem;
    flex: 0 0 1.75rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.25rem;
    background: #000000;
  }

  .raven-editor-color-hex-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.25rem;
  }

  .raven-editor-color-hex-row input[data-rvn-color-hex] {
    flex: 1 1 auto;
  }

  .raven-editor-color-dropdown button[data-rvn-color-apply],
  .raven-editor-color-dropdown button[data-rvn-color-clear] {
    flex: 1 1 0;
  }

  .raven-editor-color-dropdown .raven-picker-help {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    color: var(--bs-secondary-color, #6c757d);
  }

  .raven-editor-color-dropdown .raven-picker-hairline {
    height: 1px;
    background: var(--bs-border-color, #dee2e6);
    margin: 0.5rem 0;
  }

  .raven-editor-color-dropdown .raven-picker-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
  }

  .raven-editor-color-dropdown .raven-picker-title {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.375rem;
  }

  .raven-editor-color-dropdown canvas {
    background: #ffffff;
    cursor: pointer;
  }

  .raven-editor-color-dropdown input[data-rvn-color-hex] {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    text-transform: uppercase;
  }
</style>

<!-- TinyMCE loaded locally from Nginx /mce/ mapping (no CDN). -->
<script src="/mce/tinymce.min.js"></script>
<!-- Markdown editor assets are loaded locally from Composer install path (no CDN). -->
<link rel="stylesheet" href="/mde/easymde.min.css">
<script src="/mde/easymde.min.js"></script>
<script>
  // If browser validation fails in a hidden tab, switch to that tab automatically.
  (function () {
    var form = document.getElementById('page-edit-form');
    if (!form) {
      return;
    }

    form.addEventListener('invalid', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      var pane = target.closest('.tab-pane');
      if (!pane || !pane.id) {
        return;
      }

      var tabButton = document.querySelector('[data-bs-target="#' + pane.id + '"]');
      if (!(tabButton instanceof HTMLElement)) {
        return;
      }

      if (!window.bootstrap) {
        return;
      }

      window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
    }, true);
  })();

  // Preserve active editor tab across post-redirect flows (e.g. media upload/delete).
  (function () {
    if (!window.URLSearchParams) {
      return;
    }

    var params = new URLSearchParams(window.location.search);
    var tab = String(params.get('tab') || '').toLowerCase();
    if (tab !== 'media') {
      return;
    }

    function activateMediaTab() {
      var mediaTabButton = document.getElementById('page-media-tab');
      if (!(mediaTabButton instanceof HTMLElement) || !window.bootstrap) {
        return;
      }

      window.bootstrap.Tab.getOrCreateInstance(mediaTabButton).show();
    }

    // Bootstrap bundle is loaded by layout after this view, so run again on load.
    activateMediaTab();
    window.addEventListener('load', activateMediaTab);
  })();

  // Adds drag-and-drop UX for gallery uploads without requiring extra dependencies.
  (function () {
    var input = document.getElementById('gallery_upload_image');
    var inputQueue = document.getElementById('gallery_upload_queue');
    var dropZone = document.getElementById('gallery_drop_zone');
    var uploadButton = document.getElementById('gallery_upload_submit');
    var clearButton = document.getElementById('gallery_upload_clear');
    var selection = document.getElementById('gallery_upload_selection');
    var clientError = document.getElementById('gallery_upload_client_error');

    if (!(input instanceof HTMLInputElement) || !(inputQueue instanceof HTMLElement) || !(dropZone instanceof HTMLElement)) {
      return;
    }

    var inputTemplate = input.cloneNode(false);
    var activeInput = input;

    var maxFiles = parseInt(String(input.getAttribute('data-rvn-max-files') || '10'), 10);
    if (!Number.isFinite(maxFiles) || maxFiles < 0) {
      maxFiles = 10;
    }

    function setClientError(message) {
      if (!(clientError instanceof HTMLElement)) {
        return;
      }

      var text = String(message || '').trim();
      clientError.textContent = text;
      clientError.classList.toggle('d-none', text === '');
    }

    function queuedInputs() {
      // Hidden queue preserves prior selections when user opens chooser multiple times.
      return Array.from(inputQueue.querySelectorAll('input[type="file"][name="gallery_upload_image[]"]'));
    }

    function filesFromInput(fileInput) {
      if (!(fileInput instanceof HTMLInputElement)) {
        return [];
      }

      return Array.from(fileInput.files || []).filter(function (file) {
        return file instanceof File && String(file.name || '') !== '';
      });
    }

    function selectedFiles() {
      // Aggregates queued and active chooser files into one logical upload batch.
      var files = [];

      // First include queued selections from earlier chooser interactions.
      queuedInputs().forEach(function (queued) {
        files = files.concat(filesFromInput(queued));
      });

      // Then include current active chooser state.
      files = files.concat(filesFromInput(activeInput));

      return files;
    }

    function updateSelectionLabel() {
      // Surfaces concrete filenames so users can verify multi-select queue state.
      if (!(selection instanceof HTMLElement)) {
        return;
      }

      var files = selectedFiles();
      if (!Array.isArray(files) || files.length === 0) {
        selection.textContent = 'No files selected.';
        return;
      }

      var names = files.map(function (file) {
        return String(file && file.name ? file.name : '');
      }).filter(function (name) {
        return name !== '';
      });

      if (names.length === 0) {
        selection.textContent = 'No files selected.';
        return;
      }

      selection.textContent = 'Selected: ' + names.join(', ');
    }

    function refreshValidationMessage() {
      // Client-side guard mirrors server max-files rule for faster feedback.
      var selectedCount = selectedFiles().length;
      if (maxFiles === 0) {
        setClientError('');
        return;
      }

      if (selectedCount > maxFiles) {
        setClientError('You selected ' + selectedCount + ' files, but the max per upload is ' + maxFiles + '.');
      } else {
        setClientError('');
      }
    }

    function bindActiveInput(fileInput) {
      fileInput.addEventListener('change', function () {
        var chosenFiles = filesFromInput(fileInput);
        if (chosenFiles.length === 0) {
          updateSelectionLabel();
          refreshValidationMessage();
          return;
        }

        // Move chosen input into hidden queue so future selections append.
        fileInput.removeAttribute('id');
        fileInput.classList.add('d-none');
        fileInput.style.pointerEvents = 'none';
        inputQueue.appendChild(fileInput);

        // Create a new live file input for the next selection cycle.
        // Cloning retains accept/multiple/max-file attributes from the original input.
        activeInput = inputTemplate.cloneNode(false);
        activeInput.id = 'gallery_upload_image';
        dropZone.insertBefore(activeInput, dropZone.firstChild);
        bindActiveInput(activeInput);

        updateSelectionLabel();
        refreshValidationMessage();
      });
    }

    bindActiveInput(activeInput);

    if (clearButton instanceof HTMLButtonElement) {
      clearButton.addEventListener('click', function () {
        inputQueue.innerHTML = '';
        if (activeInput instanceof HTMLInputElement) {
          activeInput.value = '';
        }

        updateSelectionLabel();
        setClientError('');
      });
    }

    // Visual highlight only; browser handles dropped file binding natively.
    ['dragenter', 'dragover'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.add('border-primary', 'bg-primary-subtle');
      });
    });

    ['dragleave', 'dragend', 'drop'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.remove('border-primary', 'bg-primary-subtle');
      });
    });

    // Guard against submitting an oversized queued selection.
    if (uploadButton instanceof HTMLButtonElement) {
      uploadButton.addEventListener('click', function (event) {
        var selectedCount = selectedFiles().length;
        if (selectedCount > maxFiles) {
          event.preventDefault();
          setClientError('Please reduce selection to ' + maxFiles + ' files or fewer.');
          return;
        }

        if (selectedCount === 0) {
          event.preventDefault();
          setClientError('Please select at least one image to upload.');
        }
      });
    }
  })();

  // Cover selection is single-choice; selecting it clears other rows in that group.
  (function () {
    var groupCheckboxes = Array.from(document.querySelectorAll('input[data-rvn-gallery-single]'));
    if (groupCheckboxes.length === 0) {
      return;
    }

    function checkboxesForGroup(groupName) {
      return groupCheckboxes.filter(function (checkbox) {
        return checkbox instanceof HTMLInputElement
          && checkbox.getAttribute('data-rvn-gallery-single') === groupName;
      });
    }

    function enforceSingleSelection(groupName, sourceCheckbox) {
      checkboxesForGroup(groupName).forEach(function (checkbox) {
        if (!(checkbox instanceof HTMLInputElement) || checkbox === sourceCheckbox) {
          return;
        }

        checkbox.checked = false;
      });
    }

    // Normalize stale multi-checked states when older data has duplicates.
    ['cover'].forEach(function (groupName) {
      var selected = checkboxesForGroup(groupName).filter(function (checkbox) {
        return checkbox instanceof HTMLInputElement && checkbox.checked;
      });

      if (selected.length <= 1) {
        return;
      }

      selected.slice(1).forEach(function (checkbox) {
        if (checkbox instanceof HTMLInputElement) {
          checkbox.checked = false;
        }
      });
    });

    groupCheckboxes.forEach(function (checkbox) {
      if (!(checkbox instanceof HTMLInputElement)) {
        return;
      }

      checkbox.addEventListener('change', function () {
        if (!checkbox.checked) {
          return;
        }

        var groupName = String(checkbox.getAttribute('data-rvn-gallery-single') || '');
        if (groupName === '') {
          return;
        }

        enforceSingleSelection(groupName, checkbox);
      });
    });
  })();

  // Mirrors list-view behavior: checking gallery items highlights rows for bulk actions.
  (function () {
    var checkboxes = Array.from(document.querySelectorAll('input[data-rvn-gallery-select]'));
    if (checkboxes.length === 0) {
      return;
    }

    var bulkButtons = Array.from(document.querySelectorAll('[data-rvn-gallery-delete-selected]'));
    var selectAllButtons = Array.from(document.querySelectorAll('[data-rvn-gallery-select-all]'));
    var clearAllButtons = Array.from(document.querySelectorAll('[data-rvn-gallery-clear-all]'));

    function syncGallerySelectionState() {
      // Keeps row highlights + bulk button state in sync with checkbox state.
      var selectedCount = 0;

      checkboxes.forEach(function (checkbox) {
        if (!(checkbox instanceof HTMLInputElement)) {
          return;
        }

        var row = checkbox.closest('[data-rvn-gallery-row]');
        if (checkbox.checked) {
          selectedCount += 1;
        }

        if (row instanceof HTMLElement) {
          // Highlight selected image cards so multi-delete intent is visually obvious.
          row.classList.toggle('border-warning', checkbox.checked);
          row.classList.toggle('bg-warning-subtle', checkbox.checked);
        }
      });

      bulkButtons.forEach(function (button) {
        if (button instanceof HTMLButtonElement) {
          button.disabled = selectedCount === 0;
        }
      });
    }

    function setAllGallerySelections(checked) {
      // Shared helper powers both "Select All" and "Clear All" toolbar actions.
      checkboxes.forEach(function (checkbox) {
        if (checkbox instanceof HTMLInputElement) {
          checkbox.checked = checked;
        }
      });

      syncGallerySelectionState();
    }

    checkboxes.forEach(function (checkbox) {
      if (checkbox instanceof HTMLInputElement) {
        checkbox.addEventListener('change', syncGallerySelectionState);
      }
    });

    selectAllButtons.forEach(function (button) {
      if (button instanceof HTMLButtonElement) {
        button.addEventListener('click', function () {
          setAllGallerySelections(true);
        });
      }
    });

    clearAllButtons.forEach(function (button) {
      if (button instanceof HTMLButtonElement) {
        button.addEventListener('click', function () {
          setAllGallerySelections(false);
        });
      }
    });

    bulkButtons.forEach(function (button) {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      button.addEventListener('click', function (event) {
        var selectedCount = checkboxes.filter(function (checkbox) {
          return checkbox instanceof HTMLInputElement && checkbox.checked;
        }).length;

        if (selectedCount === 0) {
          event.preventDefault();
          return;
        }

        if (!window.confirm('Delete selected images and all generated variants?')) {
          event.preventDefault();
        }
      });
    });

    syncGallerySelectionState();
  })();

  // Allows drag-and-drop reordering of existing gallery rows via the grip handle.
  (function () {
    var list = document.getElementById('page-gallery-images-list');
    if (!(list instanceof HTMLElement)) {
      return;
    }

    var rows = Array.from(list.querySelectorAll('[data-rvn-gallery-row]'));
    if (rows.length === 0) {
      return;
    }

    function syncSortOrderWithDom() {
      var galleryRows = Array.from(list.querySelectorAll('[data-rvn-gallery-row]'));
      galleryRows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var sortOrderInput = row.querySelector('input[name^="gallery_images["][name$="[sort_order]"]');
        if (sortOrderInput instanceof HTMLInputElement) {
          sortOrderInput.value = String(index + 1);
        }
      });
    }

    rows.forEach(function (row) {
      if (row instanceof HTMLElement) {
        row.setAttribute('draggable', 'true');
      }
    });

    var draggingRow = null;

    list.addEventListener('dragstart', function (event) {
      var source = event.target;
      if (!(source instanceof HTMLElement)) {
        return;
      }

      var row = source.closest('[data-rvn-gallery-row]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      draggingRow = row;
      draggingRow.classList.add('opacity-75');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'gallery-row');
      }
    });

    list.addEventListener('dragover', function (event) {
      if (!(draggingRow instanceof HTMLElement)) {
        return;
      }

      event.preventDefault();
      var targetNode = event.target;
      if (!(targetNode instanceof HTMLElement)) {
        return;
      }

      var targetRow = targetNode.closest('[data-rvn-gallery-row]');
      if (!(targetRow instanceof HTMLElement) || targetRow === draggingRow) {
        return;
      }

      var rect = targetRow.getBoundingClientRect();
      var insertBeforeTarget = (event.clientY - rect.top) < (rect.height / 2);
      if (insertBeforeTarget) {
        list.insertBefore(draggingRow, targetRow);
      } else {
        list.insertBefore(draggingRow, targetRow.nextSibling);
      }
    });

    list.addEventListener('drop', function (event) {
      if (draggingRow instanceof HTMLElement) {
        event.preventDefault();
      }
    });

    list.addEventListener('dragend', function () {
      if (!(draggingRow instanceof HTMLElement)) {
        return;
      }

      draggingRow.classList.remove('opacity-75');
      draggingRow = null;
      syncSortOrderWithDom();
    });
  })();

  // Manages badge-cloud category/tag pickers inside the page editor form.
  function initRavenChipPicker(kind, inputName, badgeClass, removeLabel) {
    var picker = document.querySelector('[data-rvn-chip-picker="' + kind + '"]');
    if (!picker) {
      return;
    }

    var chipList = picker.querySelector('[data-rvn-chip-list="' + kind + '"]');
    if (!chipList) {
      return;
    }

    function selectedIds() {
      // Hidden inputs are the single source of truth for what will be posted.
      var ids = new Set();
      chipList.querySelectorAll('input[name="' + inputName + '"]').forEach(function (input) {
        ids.add(String(input.value));
      });
      return ids;
    }

    function syncDropdown() {
      // Disable already-selected options to prevent duplicate chips in form payload.
      var ids = selectedIds();
      picker.querySelectorAll('[data-rvn-add-chip="' + kind + '"]').forEach(function (button) {
        var id = String(button.getAttribute('data-rvn-option-id') || '');
        button.disabled = ids.has(id);
      });
    }

    function createChip(id, label, setId) {
      // Chip markup includes remove button + hidden input for form persistence.
      var chip = document.createElement('span');
      chip.className = 'badge ' + badgeClass + ' d-inline-flex align-items-center gap-2';
      chip.setAttribute('data-rvn-chip-id', id);
      chip.setAttribute('data-rvn-chip-set-id', String(setId || '0'));

      var labelNode = document.createElement('span');
      labelNode.textContent = label;

      var removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'btn btn-sm p-0 border-0 text-white';
      removeButton.setAttribute('data-rvn-chip-remove', kind);
      removeButton.setAttribute('aria-label', 'Remove ' + removeLabel);
      removeButton.innerHTML = '&times;';

      var hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = inputName;
      hiddenInput.value = id;

      chip.appendChild(labelNode);
      chip.appendChild(removeButton);
      chip.appendChild(hiddenInput);
      return chip;
    }

    chipList.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-rvn-chip-remove="' + kind + '"]');
      if (!remove) {
        return;
      }

      var chip = remove.closest('[data-rvn-chip-id]');
      if (chip) {
        chip.remove();
        syncDropdown();
      }
    });

    picker.querySelectorAll('[data-rvn-add-chip="' + kind + '"]').forEach(function (button) {
      button.addEventListener('click', function () {
        var id = String(button.getAttribute('data-rvn-option-id') || '');
        var label = String(button.getAttribute('data-rvn-option-label') || '');
        var setId = String(button.getAttribute('data-rvn-option-set-id') || '0');
        if (id === '' || label === '') {
          return;
        }

        if (selectedIds().has(id)) {
          return;
        }

        chipList.insertBefore(createChip(id, label, setId), chipList.querySelector('.dropdown'));
        syncDropdown();
      });
    });

    syncDropdown();

    return {
      syncAllowedSets: function (selectionValue) {
        var normalized = String(selectionValue || '').trim().toLowerCase();
        var allowAll = false;
        var allowed = new Set();
        normalized.split(',').forEach(function (part) {
          var value = String(part || '').trim();
          if (value === '') {
            return;
          }
          if (value === '0') {
            allowAll = true;
            return;
          }
          allowed.add(value);
        });

        picker.querySelectorAll('[data-rvn-add-chip="' + kind + '"]').forEach(function (button) {
          if (!(button instanceof HTMLElement) || !(button.parentElement instanceof HTMLElement)) {
            return;
          }

          var optionSetId = String(button.getAttribute('data-rvn-option-set-id') || '0');
          button.parentElement.classList.toggle('d-none', !(allowAll || allowed.has(optionSetId)));
        });

        chipList.querySelectorAll('[data-rvn-chip-id]').forEach(function (chip) {
          if (!(chip instanceof HTMLElement)) {
            return;
          }

          var chipSetId = String(chip.getAttribute('data-rvn-chip-set-id') || '0');
          if (!allowAll && !allowed.has(chipSetId)) {
            chip.remove();
          }
        });

        syncDropdown();
      }
    };
  }

  <?php if ($categoryEnabled): ?>
  var ravenCategoryChipPicker = initRavenChipPicker('category', 'category_ids[]', 'text-bg-primary', 'category');
  <?php endif; ?>
  <?php if ($tagEnabled): ?>
  var ravenTagChipPicker = initRavenChipPicker('tag', 'tag_ids[]', 'text-bg-secondary', 'tag');
  <?php endif; ?>

  var ravenGalleryItems = <?= json_encode($tinyMceGalleryItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
  var ravenShortcodeItems = <?= json_encode($shortcodeInsertItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
  var ravenBodyBlockTypes = <?= $bodyBlockTypeJson ?>;
  var ravenColorDropdownStates = Object.create(null);
  var ravenColorDropdownGlobalBound = false;

  // Encodes untrusted text for safe HTML insertion inside editor content.
  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function variantUrl(item, size) {
    if (!item || !item.variants) {
      return '';
    }

    // Fallback chain keeps insertion resilient if a specific variant is missing.
    var variants = item.variants;
    return String(variants[size] || variants.lg || variants.md || variants.sm || variants.original || '');
  }

  // Normalizes free-form color input to #RRGGBB.
  function normalizeHexColor(value) {
    var normalized = String(value || '').trim();
    if (normalized === '') {
      return '';
    }

    if (normalized.charAt(0) !== '#') {
      normalized = '#' + normalized;
    }

    if (/^#[0-9a-fA-F]{3}$/.test(normalized)) {
      normalized = '#'
        + normalized.charAt(1) + normalized.charAt(1)
        + normalized.charAt(2) + normalized.charAt(2)
        + normalized.charAt(3) + normalized.charAt(3);
    }

    if (!/^#[0-9a-fA-F]{6}$/.test(normalized)) {
      return '';
    }

    return normalized.toUpperCase();
  }

  function clamp01(value) {
    var numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return 0;
    }

    return Math.max(0, Math.min(1, numeric));
  }

  function clamp255(value) {
    var numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return 0;
    }

    return Math.max(0, Math.min(255, Math.round(numeric)));
  }

  function rgbToHex(red, green, blue) {
    var r = clamp255(red).toString(16).toUpperCase().padStart(2, '0');
    var g = clamp255(green).toString(16).toUpperCase().padStart(2, '0');
    var b = clamp255(blue).toString(16).toUpperCase().padStart(2, '0');
    return '#' + r + g + b;
  }

  function hexToRgb(hexValue) {
    var normalized = normalizeHexColor(hexValue);
    if (normalized === '') {
      return null;
    }

    return {
      r: parseInt(normalized.slice(1, 3), 16),
      g: parseInt(normalized.slice(3, 5), 16),
      b: parseInt(normalized.slice(5, 7), 16)
    };
  }

  function hsvToRgb(hue, saturation, value) {
    var h = Number(hue);
    if (!Number.isFinite(h)) {
      h = 0;
    }
    h = ((h % 360) + 360) % 360;

    var s = clamp01(saturation);
    var v = clamp01(value);

    var chroma = v * s;
    var x = chroma * (1 - Math.abs(((h / 60) % 2) - 1));
    var m = v - chroma;
    var rPrime = 0;
    var gPrime = 0;
    var bPrime = 0;

    if (h < 60) {
      rPrime = chroma; gPrime = x; bPrime = 0;
    } else if (h < 120) {
      rPrime = x; gPrime = chroma; bPrime = 0;
    } else if (h < 180) {
      rPrime = 0; gPrime = chroma; bPrime = x;
    } else if (h < 240) {
      rPrime = 0; gPrime = x; bPrime = chroma;
    } else if (h < 300) {
      rPrime = x; gPrime = 0; bPrime = chroma;
    } else {
      rPrime = chroma; gPrime = 0; bPrime = x;
    }

    return {
      r: clamp255((rPrime + m) * 255),
      g: clamp255((gPrime + m) * 255),
      b: clamp255((bPrime + m) * 255)
    };
  }

  function rgbToHsv(red, green, blue) {
    var r = clamp255(red) / 255;
    var g = clamp255(green) / 255;
    var b = clamp255(blue) / 255;

    var max = Math.max(r, g, b);
    var min = Math.min(r, g, b);
    var delta = max - min;
    var hue = 0;

    if (delta !== 0) {
      if (max === r) {
        hue = 60 * (((g - b) / delta) % 6);
      } else if (max === g) {
        hue = 60 * (((b - r) / delta) + 2);
      } else {
        hue = 60 * (((r - g) / delta) + 4);
      }
    }

    if (hue < 0) {
      hue += 360;
    }

    var saturation = max === 0 ? 0 : delta / max;
    var value = max;

    return {
      h: hue,
      s: saturation,
      v: value
    };
  }

  // Converts CSS rgb/rgba() values into #RRGGBB when possible.
  function rgbCssToHex(value) {
    var match = String(value || '').trim().match(/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})(?:\s*,\s*(?:0|0?\.\d+|1(?:\.0+)?))?\s*\)$/i);
    if (!match) {
      return '';
    }

    var red = Math.max(0, Math.min(255, parseInt(match[1], 10)));
    var green = Math.max(0, Math.min(255, parseInt(match[2], 10)));
    var blue = Math.max(0, Math.min(255, parseInt(match[3], 10)));
    var toHex = function (channel) {
      return channel.toString(16).toUpperCase().padStart(2, '0');
    };

    return '#' + toHex(red) + toHex(green) + toHex(blue);
  }

  function clearEditorTextColor(editor) {
    try {
      editor.execCommand('mceRemoveTextcolor');
    } catch (error) {
      editor.formatter.remove('forecolor');
    }
  }

  function resolveEditorSelectionHexColor(editor) {
    var node = editor.selection ? editor.selection.getNode() : null;
    if (!(node instanceof Element)) {
      return '';
    }

    var inlineColor = normalizeHexColor(node.style ? node.style.color : '');
    if (inlineColor !== '') {
      return inlineColor;
    }

    // Use computed color when direct inline style is absent.
    var docView = node.ownerDocument && node.ownerDocument.defaultView ? node.ownerDocument.defaultView : null;
    if (!docView || typeof docView.getComputedStyle !== 'function') {
      return '';
    }

    return rgbCssToHex(docView.getComputedStyle(node).color);
  }

  function positionColorDropdown(state) {
    if (!state || !state.panel) {
      return;
    }

    var panel = state.panel;
    var anchor = state.button instanceof HTMLElement ? state.button : null;
    if (!(anchor instanceof HTMLElement) && state.toolbar instanceof HTMLElement) {
      anchor = state.toolbar;
    }
    if (!(anchor instanceof HTMLElement) && state.editorContainer instanceof HTMLElement) {
      anchor = state.editorContainer;
    }
    if (!(anchor instanceof HTMLElement)) {
      return;
    }

    var anchorRect = anchor.getBoundingClientRect();

    panel.style.visibility = 'hidden';
    panel.hidden = false;

    var panelRect = panel.getBoundingClientRect();
    var viewportLeft = window.scrollX + 8;
    var viewportRight = window.scrollX + window.innerWidth - 8;
    var viewportTop = window.scrollY + 8;
    var viewportBottom = window.scrollY + window.innerHeight - 8;

    var left = anchorRect.left + window.scrollX;
    var top = anchorRect.bottom + window.scrollY + 6;

    if (left + panelRect.width > viewportRight) {
      left = viewportRight - panelRect.width;
    }
    if (left < viewportLeft) {
      left = viewportLeft;
    }

    if (top + panelRect.height > viewportBottom) {
      top = anchorRect.top + window.scrollY - panelRect.height - 6;
    }
    if (top < viewportTop) {
      top = viewportTop;
    }

    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
    panel.style.visibility = 'visible';
  }

  function closeColorDropdown(state) {
    if (!state || !state.panel) {
      return;
    }

    state.isOpen = false;
    state.panel.hidden = true;
    if (state.button instanceof HTMLElement) {
      state.button.setAttribute('aria-expanded', 'false');
    }
  }

  function closeAllColorDropdowns(exceptEditorId) {
    Object.keys(ravenColorDropdownStates).forEach(function (editorId) {
      if (exceptEditorId && editorId === String(exceptEditorId)) {
        return;
      }

      closeColorDropdown(ravenColorDropdownStates[editorId]);
    });
  }

  function bindColorDropdownGlobalHandlers() {
    if (ravenColorDropdownGlobalBound) {
      return;
    }
    ravenColorDropdownGlobalBound = true;

    function handlePointerAway(event) {
      Object.keys(ravenColorDropdownStates).forEach(function (editorId) {
        var state = ravenColorDropdownStates[editorId];
        if (!state || !state.isOpen || !(state.panel instanceof HTMLElement)) {
          return;
        }

        var target = event.target;
        if (!(target instanceof Node)) {
          return;
        }

        if (state.panel.contains(target)) {
          return;
        }

        if (state.button instanceof HTMLElement && state.button.contains(target)) {
          return;
        }

        closeColorDropdown(state);
      });
    }

    document.addEventListener('pointerdown', handlePointerAway, true);
    document.addEventListener('mousedown', handlePointerAway, true);
    document.addEventListener('touchstart', handlePointerAway, true);

    window.addEventListener('resize', function () {
      closeAllColorDropdowns('');
    });
  }

  function destroyColorDropdownForEditor(editorId) {
    var key = String(editorId || '');
    if (key === '' || !ravenColorDropdownStates[key]) {
      return;
    }

    var state = ravenColorDropdownStates[key];
    if (state.panel instanceof HTMLElement && state.panel.parentNode) {
      state.panel.parentNode.removeChild(state.panel);
    }
    delete ravenColorDropdownStates[key];
  }

  function ensureColorDropdownState(editor) {
    var editorId = String(editor.id || '');
    if (editorId === '') {
      return null;
    }

    if (ravenColorDropdownStates[editorId] && ravenColorDropdownStates[editorId].panel instanceof HTMLElement) {
      return ravenColorDropdownStates[editorId];
    }

    var safeEditorId = editorId.replace(/[^a-zA-Z0-9_-]/g, '_');
    var panel = document.createElement('div');
    panel.className = 'raven-editor-color-dropdown';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Text color picker');
    panel.innerHTML = ''
      + '<div class="raven-picker-title">Text Color</div>'
      + '<div class="raven-editor-color-picker">'
      + '  <canvas width="240" height="146" class="raven-editor-color-sv" data-rvn-color-sv aria-label="Saturation and value"></canvas>'
      + '  <canvas width="20" height="146" class="raven-editor-color-hue" data-rvn-color-hue aria-label="Hue"></canvas>'
      + '</div>'
      + '<div class="raven-picker-help">Drag inside the square for shade and brightness. Use the side bar for hue.</div>'
      + '<div class="raven-editor-color-hex-row">'
      + '  <span class="raven-editor-color-preview" data-rvn-color-preview aria-hidden="true"></span>'
      + '  <input id="raven-color-hex-' + safeEditorId + '" type="text" class="form-control form-control-sm" data-rvn-color-hex maxlength="7" placeholder="#000000">'
      + '</div>'
      + '<div class="text-danger small mt-1 d-none" data-rvn-color-error></div>'
      + '<div class="raven-picker-row">'
      + '  <button type="button" class="btn btn-primary btn-sm" data-rvn-color-apply>Apply Color</button>'
      + '  <button type="button" class="btn btn-outline-secondary btn-sm" data-rvn-color-clear>Clear Formatting</button>'
      + '</div>';

    document.body.appendChild(panel);

    var editorContainer = editor.getContainer();
    var toolbar = editorContainer instanceof HTMLElement
      ? editorContainer.querySelector('.tox-toolbar, .tox-toolbar-overlord, .tox-editor-header')
      : null;

    var state = {
      editorId: editorId,
      panel: panel,
      button: null,
      editorContainer: editorContainer instanceof HTMLElement ? editorContainer : null,
      toolbar: toolbar instanceof HTMLElement ? toolbar : null,
      isOpen: false,
      lastColor: '#000000',
      hue: 0,
      saturation: 0,
      value: 0,
      svCanvas: panel.querySelector('[data-rvn-color-sv]'),
      hueCanvas: panel.querySelector('[data-rvn-color-hue]'),
      previewNode: panel.querySelector('[data-rvn-color-preview]'),
      hexInput: panel.querySelector('[data-rvn-color-hex]'),
      errorNode: panel.querySelector('[data-rvn-color-error]'),
      applyButton: panel.querySelector('[data-rvn-color-apply]'),
      clearButton: panel.querySelector('[data-rvn-color-clear]')
    };

    function setError(message) {
      if (!(state.errorNode instanceof HTMLElement)) {
        return;
      }

      var text = String(message || '').trim();
      state.errorNode.textContent = text;
      state.errorNode.classList.toggle('d-none', text === '');
    }

    function currentHexFromState() {
      var rgb = hsvToRgb(state.hue, state.saturation, state.value);
      return rgbToHex(rgb.r, rgb.g, rgb.b);
    }

    function syncPreviewAndHex(updateHexField) {
      var hexColor = currentHexFromState();
      if (state.previewNode instanceof HTMLElement) {
        state.previewNode.style.backgroundColor = hexColor;
      }

      if (updateHexField !== false && state.hexInput instanceof HTMLInputElement) {
        state.hexInput.value = hexColor;
      }
    }

    function drawHueCanvas() {
      if (!(state.hueCanvas instanceof HTMLCanvasElement)) {
        return;
      }

      var context = state.hueCanvas.getContext('2d');
      if (!context) {
        return;
      }

      var width = state.hueCanvas.width;
      var height = state.hueCanvas.height;
      var gradient = context.createLinearGradient(0, 0, 0, height);
      gradient.addColorStop(0, '#FF0000');
      gradient.addColorStop(1 / 6, '#FFFF00');
      gradient.addColorStop(2 / 6, '#00FF00');
      gradient.addColorStop(3 / 6, '#00FFFF');
      gradient.addColorStop(4 / 6, '#0000FF');
      gradient.addColorStop(5 / 6, '#FF00FF');
      gradient.addColorStop(1, '#FF0000');
      context.clearRect(0, 0, width, height);
      context.fillStyle = gradient;
      context.fillRect(0, 0, width, height);

      var markerY = Math.round((state.hue / 360) * (height - 1));
      context.strokeStyle = '#FFFFFF';
      context.lineWidth = 2;
      context.beginPath();
      context.moveTo(0, markerY + 0.5);
      context.lineTo(width, markerY + 0.5);
      context.stroke();
      context.strokeStyle = 'rgba(0, 0, 0, 0.65)';
      context.lineWidth = 1;
      context.beginPath();
      context.moveTo(0, markerY + 0.5);
      context.lineTo(width, markerY + 0.5);
      context.stroke();
    }

    function drawSvCanvas() {
      if (!(state.svCanvas instanceof HTMLCanvasElement)) {
        return;
      }

      var context = state.svCanvas.getContext('2d');
      if (!context) {
        return;
      }

      var width = state.svCanvas.width;
      var height = state.svCanvas.height;
      var hueRgb = hsvToRgb(state.hue, 1, 1);
      var hueHex = rgbToHex(hueRgb.r, hueRgb.g, hueRgb.b);

      context.clearRect(0, 0, width, height);
      context.fillStyle = hueHex;
      context.fillRect(0, 0, width, height);

      var whiteGradient = context.createLinearGradient(0, 0, width, 0);
      whiteGradient.addColorStop(0, '#FFFFFF');
      whiteGradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
      context.fillStyle = whiteGradient;
      context.fillRect(0, 0, width, height);

      var blackGradient = context.createLinearGradient(0, 0, 0, height);
      blackGradient.addColorStop(0, 'rgba(0, 0, 0, 0)');
      blackGradient.addColorStop(1, '#000000');
      context.fillStyle = blackGradient;
      context.fillRect(0, 0, width, height);

      var markerX = Math.round(state.saturation * width);
      var markerY = Math.round((1 - state.value) * height);
      context.beginPath();
      context.arc(markerX, markerY, 6, 0, Math.PI * 2);
      context.lineWidth = 2;
      context.strokeStyle = '#FFFFFF';
      context.stroke();
      context.lineWidth = 1;
      context.strokeStyle = 'rgba(0, 0, 0, 0.75)';
      context.stroke();
    }

    function renderPicker(updateHexField) {
      drawSvCanvas();
      drawHueCanvas();
      syncPreviewAndHex(updateHexField);
    }

    function setColorFromHex(hexColor, updateHexField) {
      var rgb = hexToRgb(hexColor);
      if (!rgb) {
        return false;
      }

      var hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
      state.hue = hsv.h;
      state.saturation = hsv.s;
      state.value = hsv.v;
      state.lastColor = rgbToHex(rgb.r, rgb.g, rgb.b);
      renderPicker(updateHexField);
      return true;
    }

    function updateSaturationValueFromPointer(event) {
      if (!(state.svCanvas instanceof HTMLCanvasElement)) {
        return;
      }

      var rect = state.svCanvas.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        return;
      }

      var x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
      var y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
      state.saturation = clamp01(x / rect.width);
      state.value = clamp01(1 - (y / rect.height));
      renderPicker();
      setError('');
    }

    function updateHueFromPointer(event) {
      if (!(state.hueCanvas instanceof HTMLCanvasElement)) {
        return;
      }

      var rect = state.hueCanvas.getBoundingClientRect();
      if (rect.height <= 0) {
        return;
      }

      var y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
      state.hue = clamp01(y / rect.height) * 360;
      renderPicker();
      setError('');
    }

    function bindCanvasDrag(canvas, moveHandler) {
      if (!(canvas instanceof HTMLCanvasElement)) {
        return;
      }

      canvas.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        moveHandler(event);

        if (typeof canvas.setPointerCapture === 'function') {
          canvas.setPointerCapture(event.pointerId);
        }

        function onMove(moveEvent) {
          if (moveEvent.pointerId !== event.pointerId) {
            return;
          }

          moveEvent.preventDefault();
          moveHandler(moveEvent);
        }

        function onUp(upEvent) {
          if (upEvent.pointerId !== event.pointerId) {
            return;
          }

          canvas.removeEventListener('pointermove', onMove);
          canvas.removeEventListener('pointerup', onUp);
          canvas.removeEventListener('pointercancel', onUp);
        }

        canvas.addEventListener('pointermove', onMove);
        canvas.addEventListener('pointerup', onUp);
        canvas.addEventListener('pointercancel', onUp);
      });
    }

    bindCanvasDrag(state.svCanvas, updateSaturationValueFromPointer);
    bindCanvasDrag(state.hueCanvas, updateHueFromPointer);

    if (state.hexInput instanceof HTMLInputElement) {
      state.hexInput.addEventListener('input', function () {
        var normalized = normalizeHexColor(state.hexInput.value);
        if (normalized === '') {
          return;
        }

        setColorFromHex(normalized, true);
        setError('');
      });

      state.hexInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
          return;
        }

        event.preventDefault();
        if (state.applyButton instanceof HTMLButtonElement) {
          state.applyButton.click();
        }
      });
    }

    if (state.applyButton instanceof HTMLButtonElement) {
      state.applyButton.addEventListener('click', function () {
        if (!(state.hexInput instanceof HTMLInputElement)) {
          return;
        }

        var normalized = normalizeHexColor(state.hexInput.value);
        if (normalized === '') {
          setError('Enter a valid hex color like #336699.');
          return;
        }

        setColorFromHex(normalized, true);
        editor.focus();
        editor.execCommand('ForeColor', false, normalized);
        state.lastColor = normalized;
        setError('');
        closeColorDropdown(state);
      });
    }

    if (state.clearButton instanceof HTMLButtonElement) {
      state.clearButton.addEventListener('click', function () {
        editor.focus();
        clearEditorTextColor(editor);
        setError('');
        closeColorDropdown(state);
      });
    }

    // Keep interactions inside the panel from triggering global outside-click close handlers.
    panel.addEventListener('mousedown', function (event) {
      event.stopPropagation();
    });

    setColorFromHex(state.lastColor, true);
    state.renderPicker = renderPicker;
    state.setColorFromHex = setColorFromHex;
    state.currentHex = currentHexFromState;

    ravenColorDropdownStates[editorId] = state;
    bindColorDropdownGlobalHandlers();
    return state;
  }

  function findColorDropdownButton(editor) {
    var container = editor.getContainer();
    if (!(container instanceof HTMLElement)) {
      return null;
    }

    var strictButton = container.querySelector('.tox-tbtn[data-mce-name="ravenTextColor"]');
    if (strictButton instanceof HTMLElement) {
      return strictButton;
    }

    var active = document.activeElement;
    if (active instanceof HTMLElement && container.contains(active) && active.classList.contains('tox-tbtn')) {
      return active;
    }

    var byLabel = Array.from(container.querySelectorAll('.tox-tbtn')).find(function (button) {
      if (!(button instanceof HTMLElement)) {
        return false;
      }

      var label = String(button.getAttribute('aria-label') || button.getAttribute('title') || '').toLowerCase();
      return label.indexOf('text color') !== -1;
    });

    return byLabel instanceof HTMLElement ? byLabel : null;
  }

  function toggleColorDropdown(editor) {
    var editorId = String(editor.id || '');
    if (editorId === '') {
      return;
    }

    var state = ensureColorDropdownState(editor);
    if (!state) {
      return;
    }

    var container = editor.getContainer();
    if (container instanceof HTMLElement) {
      state.editorContainer = container;
      var toolbar = container.querySelector('.tox-toolbar, .tox-toolbar-overlord, .tox-editor-header');
      if (toolbar instanceof HTMLElement) {
        state.toolbar = toolbar;
      }
    }

    var button = findColorDropdownButton(editor);
    if (button instanceof HTMLElement) {
      state.button = button;
    } else if (state.button instanceof HTMLElement && state.editorContainer instanceof HTMLElement && !state.editorContainer.contains(state.button)) {
      state.button = null;
    }

    if (state.isOpen) {
      closeColorDropdown(state);
      return;
    }

    closeAllColorDropdowns(editorId);

    var currentHex = normalizeHexColor(resolveEditorSelectionHexColor(editor)) || state.lastColor || '#000000';
    if (typeof state.setColorFromHex === 'function') {
      state.setColorFromHex(currentHex, true);
    } else if (state.hexInput instanceof HTMLInputElement) {
      state.hexInput.value = currentHex;
    }

    state.isOpen = true;
    if (state.button instanceof HTMLElement) {
      state.button.setAttribute('aria-expanded', 'true');
    }
    positionColorDropdown(state);
  }

  function openGalleryInsertDialog(editor) {
    // Dialog payload is derived from page-linked gallery rows prepared by server.
    if (!Array.isArray(ravenGalleryItems) || ravenGalleryItems.length === 0) {
      editor.notificationManager.open({
        text: 'No gallery images available for this page yet.',
        type: 'info'
      });
      return;
    }

    var imageItems = ravenGalleryItems.map(function (item) {
      return { text: String(item.label || ('Image #' + item.id)), value: String(item.id) };
    });

    var first = ravenGalleryItems[0];

    editor.windowManager.open({
      title: 'Insert Gallery Image',
      body: {
        type: 'panel',
        items: [
          {
            type: 'selectbox',
            name: 'image_id',
            label: 'Image',
            items: imageItems
          },
          {
            type: 'selectbox',
            name: 'size',
            label: 'Size',
            items: [
              { text: 'Small', value: 'sm' },
              { text: 'Medium', value: 'md' },
              { text: 'Large', value: 'lg' },
              { text: 'Original', value: 'original' }
            ]
          },
          {
            type: 'input',
            name: 'alt',
            label: 'Alt Text'
          }
        ]
      },
      // Explicit dialog buttons ensure submit is visible across TinyMCE builds.
      buttons: [
        {
          type: 'cancel',
          name: 'cancel',
          text: 'Cancel'
        },
        {
          type: 'submit',
          name: 'submit',
          text: 'Insert',
          primary: true
        }
      ],
      initialData: {
        image_id: String(first.id),
        size: 'md',
        alt: String(first.alt_text || '')
      },
      onSubmit: function (api) {
        var data = api.getData();
        var chosen = ravenGalleryItems.find(function (item) {
          return String(item.id) === String(data.image_id);
        });

        if (!chosen) {
          api.close();
          return;
        }

        var url = variantUrl(chosen, String(data.size || 'md'));
        if (url === '') {
          api.close();
          return;
        }

        var altText = String(data.alt || '').trim();
        var imageTag = '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(altText) + '">';
        var caption = String(chosen.caption || '').trim();

        // Insert semantic figure markup when caption is available; plain image otherwise.
        if (caption !== '') {
          editor.insertContent('<figure>' + imageTag + '<figcaption>' + escapeHtml(caption) + '</figcaption></figure>');
        } else {
          editor.insertContent(imageTag);
        }

        api.close();
      }
    });
  }

  function openExtensionShortcodeDialog(editor) {
    if (!Array.isArray(ravenShortcodeItems) || ravenShortcodeItems.length === 0) {
      editor.notificationManager.open({
        text: 'No extension shortcodes are available.',
        type: 'info'
      });
      return;
    }

    var shortcodeOptions = ravenShortcodeItems.map(function (item) {
      var shortcode = String(item.shortcode || '').trim();
      return {
        text: String(item.label || shortcode || 'Shortcode'),
        value: shortcode
      };
    }).filter(function (item) {
      return item.value !== '';
    });

    if (shortcodeOptions.length === 0) {
      editor.notificationManager.open({
        text: 'No extension shortcodes are available.',
        type: 'info'
      });
      return;
    }

    editor.windowManager.open({
      title: 'Insert Extension Shortcode',
      body: {
        type: 'panel',
        items: [
          {
            type: 'selectbox',
            name: 'shortcode_value',
            label: 'Shortcode',
            items: shortcodeOptions
          }
        ]
      },
      buttons: [
        {
          type: 'cancel',
          name: 'cancel',
          text: 'Cancel'
        },
        {
          type: 'submit',
          name: 'submit',
          text: 'Insert',
          primary: true
        }
      ],
      initialData: {
        shortcode_value: String(shortcodeOptions[0].value || '')
      },
      onSubmit: function (api) {
        var data = api.getData();
        var shortcode = String(data.shortcode_value || '').trim();

        if (!shortcode) {
          api.close();
          return;
        }

        // Shortcodes are resolved by runtime handlers during public rendering.
        editor.insertContent(shortcode);
        api.close();
      }
    });
  }

  function registerPageEditorButtons(editor) {
    editor.ui.registry.addIcon(
      'ravenFileEarmarkCode',
      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-earmark-code" viewBox="0 0 16 16" aria-hidden="true"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5z"/><path d="M8.646 6.646a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L10.293 9 8.646 7.354a.5.5 0 0 1 0-.708m-1.292 0a.5.5 0 0 0-.708 0l-2 2a.5.5 0 0 0 0 .708l2 2a.5.5 0 0 0 .708-.708L5.707 9l1.647-1.646a.5.5 0 0 0 0-.708"/></svg>'
    );

    editor.ui.registry.addIcon(
      'ravenGear',
      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-braces-asterisk" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1.114 8.063V7.9c1.005-.102 1.497-.615 1.497-1.6V4.503c0-1.094.39-1.538 1.354-1.538h.273V2h-.376C2.25 2 1.49 2.759 1.49 4.352v1.524c0 1.094-.376 1.456-1.49 1.456v1.299c1.114 0 1.49.362 1.49 1.456v1.524c0 1.593.759 2.352 2.372 2.352h.376v-.964h-.273c-.964 0-1.354-.444-1.354-1.538V9.663c0-.984-.492-1.497-1.497-1.6M14.886 7.9v.164c-1.005.103-1.497.616-1.497 1.6v1.798c0 1.094-.39 1.538-1.354 1.538h-.273v.964h.376c1.613 0 2.372-.759 2.372-2.352v-1.524c0-1.094.376-1.456 1.49-1.456v-1.3c-1.114 0-1.49-.362-1.49-1.456V4.352C14.51 2.759 13.75 2 12.138 2h-.376v.964h.273c.964 0 1.354.444 1.354 1.538V6.3c0 .984.492 1.497 1.497 1.6M7.5 11.5V9.207l-1.621 1.621-.707-.707L6.792 8.5H4.5v-1h2.293L5.172 5.879l.707-.707L7.5 6.792V4.5h1v2.293l1.621-1.621.707.707L9.208 7.5H11.5v1H9.207l1.621 1.621-.707.707L8.5 9.208V11.5z"/></svg>'
    );

    editor.ui.registry.addButton('ravenViewSource', {
      icon: 'ravenFileEarmarkCode',
      tooltip: 'View source',
      onAction: function () {
        editor.execCommand('mceCodeEditor');
      }
    });

    editor.ui.registry.addToggleButton('ravenInlineCode', {
      icon: 'sourcecode',
      tooltip: 'Inline code',
      onAction: function () {
        editor.formatter.toggle('code');
      },
      onSetup: function (buttonApi) {
        function syncState() {
          buttonApi.setActive(editor.formatter.match('code'));
        }

        editor.on('NodeChange', syncState);
        return function () {
          editor.off('NodeChange', syncState);
        };
      }
    });

    editor.ui.registry.addButton('ravenTextColor', {
      icon: 'text-color',
      tooltip: 'Text color',
      onAction: function () {
        toggleColorDropdown(editor);
      }
    });

    editor.ui.registry.addButton('ravenGallery', {
      icon: 'image',
      tooltip: 'Insert from page gallery',
      onAction: function () {
        openGalleryInsertDialog(editor);
      }
    });

    if (Array.isArray(ravenShortcodeItems) && ravenShortcodeItems.length > 0) {
      editor.ui.registry.addButton('ravenExtensions', {
        icon: 'ravenGear',
        tooltip: 'Insert extension shortcode',
        onAction: function () {
          openExtensionShortcodeDialog(editor);
        }
      });
    }

    // Clicking inside editor content is outside of the floating color dropdown and should close it.
    editor.on('mousedown', function () {
      closeAllColorDropdowns('');
    });

    editor.on('remove', function () {
      destroyColorDropdownForEditor(editor.id);
    });
  }

  function midnightPanelThemeActive() {
    return document.body instanceof HTMLElement && document.body.classList.contains('theme-dark');
  }

  function readPanelThemeCssVar(name, fallback) {
    if (!(document.body instanceof HTMLElement) || !window.getComputedStyle) {
      return fallback;
    }

    var value = String(window.getComputedStyle(document.body).getPropertyValue(name) || '').trim();
    return value !== '' ? value : fallback;
  }

  function tinyMceContentStyleForTheme() {
    var fontFamily = 'sans-serif';
    if (!midnightPanelThemeActive()) {
      // Keep editor body typography generic sans-serif even when panel UI uses custom fonts.
      return 'body { font-family: ' + fontFamily + '; }';
    }

    var surface = readPanelThemeCssVar('--raven-surface', '#161b22');
    var ink = readPanelThemeCssVar('--raven-ink', '#c9d1d9');
    var border = readPanelThemeCssVar('--raven-border', '#30363d');
    var muted = readPanelThemeCssVar('--raven-muted', '#8b949e');
    var link = '#79c0ff';
    var strong = '#f0f6fc';

    return [
      'body {',
      'font-family: ' + fontFamily + ';',
      'background: ' + surface + ';',
      'color: ' + ink + ';',
      '}',
      'a { color: ' + link + '; }',
      'hr { border-color: ' + border + '; }',
      'blockquote { border-left: 3px solid ' + border + '; color: ' + muted + '; margin-left: 0; padding-left: 0.8rem; }',
      'code, pre { background: #0d1117; color: ' + ink + '; border: 1px solid ' + border + '; border-radius: 0.25rem; }',
      'h1, h2, h3, h4, h5, h6, strong, b { color: ' + strong + '; }'
    ].join(' ');
  }

  function tinyMceConfigForTarget(textarea) {
    var config = {
      // Explicitly acknowledge TinyMCE OSS usage to suppress eval-mode warnings.
      license_key: 'gpl',
      target: textarea,
      height: 420,
      content_style: tinyMceContentStyleForTheme(),
      menubar: false,
      branding: false,
      promotion: false,
      // Keep controls visible after dynamic row reindex/re-init in modular body blocks.
      toolbar_mode: 'wrap',
      plugins: 'lists link table code image paste hr',
      block_formats: 'Paragraph=p; Div=div; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Blockquote=blockquote; pre=pre',
      toolbar: 'ravenViewSource removeformat blocks ravenTextColor alignleft alignright aligncenter alignjustify bold italic underline strikethrough subscript superscript ravenInlineCode bullist numlist table hr link pastetext ravenGallery'
        + ((Array.isArray(ravenShortcodeItems) && ravenShortcodeItems.length > 0) ? ' ravenExtensions' : ''),
      setup: registerPageEditorButtons
    };

    if (midnightPanelThemeActive()) {
      // Midnight panel theme maps to TinyMCE dark UI/content bundles.
      config.skin = 'oxide-dark';
      config.content_css = 'dark';
    }

    return config;
  }

  function initTinyMceForTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement) || !window.tinymce) {
      return;
    }

    if (String(textarea.id || '').trim() === '') {
      return;
    }

    if (window.tinymce.get(textarea.id)) {
      return;
    }

    window.tinymce.init(tinyMceConfigForTarget(textarea));
  }

  function destroyTinyMceForTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement) || !window.tinymce) {
      return;
    }

    var fieldId = String(textarea.id || '').trim();
    if (fieldId === '') {
      return;
    }

    var instance = window.tinymce.get(fieldId);
    if (instance) {
      destroyColorDropdownForEditor(instance.id);
      instance.save();
      instance.remove();
    }
  }

  var ravenEasyMdeLoaderState = {
    loading: false,
    ready: typeof window.EasyMDE === 'function',
    failed: false,
    queue: []
  };

  function flushEasyMdeQueue(ready) {
    var callbacks = ravenEasyMdeLoaderState.queue.slice();
    ravenEasyMdeLoaderState.queue = [];
    callbacks.forEach(function (callback) {
      if (typeof callback === 'function') {
        callback(ready);
      }
    });
  }

  function showEasyMdeUnavailableNotice() {
    var list = document.getElementById('page-body-blocks-list');
    if (!(list instanceof HTMLElement)) {
      return;
    }

    if (document.getElementById('raven-easymde-unavailable-alert')) {
      return;
    }

    var alert = document.createElement('div');
    alert.id = 'raven-easymde-unavailable-alert';
    alert.className = 'alert alert-warning mt-2';
    alert.textContent = 'Markdown editor assets could not be loaded. Check your /mde mapping.';

    var contentPane = document.getElementById('rvnp-editor-pane-content');
    if (contentPane instanceof HTMLElement) {
      contentPane.insertBefore(alert, contentPane.firstChild);
      return;
    }

    list.parentElement && list.parentElement.insertBefore(alert, list);
  }

  function ensureEasyMdeStylesheetFallback() {
    var cssCandidates = [
      '/mde/easymde.min.css',
      '/mde/lib/easymde.min.css',
      '/mde/dist/easymde.min.css',
      '/composer/tualo/easymde/lib/easymde.min.css',
      '/composer/tualo/easymde/dist/easymde.min.css'
    ];

    cssCandidates.forEach(function (href, index) {
      var normalizedHref = String(href || '').trim();
      if (normalizedHref === '') {
        return;
      }

      if (document.querySelector('link[data-rvn-easymde-fallback=\"1\"][href=\"' + normalizedHref + '\"]')) {
        return;
      }

      if (index === 0 && document.querySelector('link[href=\"' + normalizedHref + '\"]')) {
        return;
      }

      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = normalizedHref;
      link.setAttribute('data-rvn-easymde-fallback', '1');
      document.head.appendChild(link);
    });
  }

  function loadEasyMdeScriptCandidates(candidates, index, done) {
    if (typeof window.EasyMDE === 'function') {
      done(true);
      return;
    }

    if (!Array.isArray(candidates) || index >= candidates.length) {
      done(false);
      return;
    }

    var source = String(candidates[index] || '').trim();
    if (source === '') {
      loadEasyMdeScriptCandidates(candidates, index + 1, done);
      return;
    }

    if (document.querySelector('script[data-rvn-easymde-candidate=\"1\"][src=\"' + source + '\"]')) {
      loadEasyMdeScriptCandidates(candidates, index + 1, done);
      return;
    }

    var script = document.createElement('script');
    script.src = source;
    script.async = true;
    script.setAttribute('data-rvn-easymde-candidate', '1');
    script.onload = function () {
      if (typeof window.EasyMDE === 'function') {
        done(true);
        return;
      }

      loadEasyMdeScriptCandidates(candidates, index + 1, done);
    };
    script.onerror = function () {
      loadEasyMdeScriptCandidates(candidates, index + 1, done);
    };
    document.head.appendChild(script);
  }

  function ensureEasyMdeReady(callback) {
    if (typeof callback !== 'function') {
      return;
    }

    if (typeof window.EasyMDE === 'function') {
      ravenEasyMdeLoaderState.ready = true;
      callback(true);
      return;
    }

    if (ravenEasyMdeLoaderState.failed) {
      callback(false);
      return;
    }

    ravenEasyMdeLoaderState.queue.push(callback);
    if (ravenEasyMdeLoaderState.loading) {
      return;
    }

    ravenEasyMdeLoaderState.loading = true;
    ensureEasyMdeStylesheetFallback();

    loadEasyMdeScriptCandidates(
      [
        '/mde/easymde.min.js',
        '/mde/lib/easymde.min.js',
        '/mde/dist/easymde.min.js',
        '/composer/tualo/easymde/lib/easymde.min.js',
        '/composer/tualo/easymde/dist/easymde.min.js'
      ],
      0,
      function (ready) {
        ravenEasyMdeLoaderState.loading = false;
        ravenEasyMdeLoaderState.ready = ready;
        ravenEasyMdeLoaderState.failed = !ready;
        if (!ready && window.console && typeof window.console.warn === 'function') {
          window.console.warn('EasyMDE assets could not be loaded from /mde paths.');
        }
        if (!ready) {
          showEasyMdeUnavailableNotice();
        }

        flushEasyMdeQueue(ready);
      }
    );
  }

  function initEasyMdeForTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }

    if (textarea.__ravenEasyMde) {
      return;
    }

    ensureEasyMdeReady(function (ready) {
      if (!ready || !(textarea instanceof HTMLTextAreaElement) || !textarea.isConnected) {
        return;
      }

      if (textarea.__ravenEasyMde || typeof window.EasyMDE !== 'function') {
        return;
      }

      var parentRow = textarea.closest('[data-rvn-body-row=\"1\"]');
      if (parentRow instanceof HTMLElement) {
        var typeInput = parentRow.querySelector('input[data-rvn-body-type-input=\"1\"]');
        if (typeInput instanceof HTMLInputElement && bodyBlockEditorMode(typeInput.value) !== 'markdown') {
          return;
        }
      }

      try {
        textarea.__ravenEasyMde = new window.EasyMDE({
          element: textarea,
          autoDownloadFontAwesome: false,
          spellChecker: false,
          status: false
        });
      } catch (error) {
        textarea.__ravenEasyMde = null;
        if (window.console && typeof window.console.warn === 'function') {
          window.console.warn('EasyMDE init failed for textarea:', error);
        }
      }
    });
  }

  function destroyEasyMdeForTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }

    var instance = textarea.__ravenEasyMde;
    if (instance && typeof instance.toTextArea === 'function') {
      instance.toTextArea();
    }
    textarea.__ravenEasyMde = null;
  }

  function normalizeBodyBlockType(value) {
    var normalized = String(value || '').toLowerCase().trim();
    if (!Object.prototype.hasOwnProperty.call(ravenBodyBlockTypes || {}, normalized)) {
      return 'tinymce';
    }

    return normalized;
  }

  function bodyBlockEditorMode(type) {
    var normalizedType = normalizeBodyBlockType(type);
    var definitions = ravenBodyBlockTypes || {};
    if (!Object.prototype.hasOwnProperty.call(definitions, normalizedType)) {
      return 'tinymce';
    }

    var editor = String((definitions[normalizedType] || {}).editor || 'tinymce').toLowerCase().trim();
    if (['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'gallery'].indexOf(editor) === -1) {
      return 'tinymce';
    }

    return editor;
  }

  function bodyBlockLabel(type) {
    var normalizedType = normalizeBodyBlockType(type);
    var definitions = ravenBodyBlockTypes || {};
    if (Object.prototype.hasOwnProperty.call(definitions, normalizedType)) {
      var label = String((definitions[normalizedType] || {}).label || '').trim();
      if (label !== '') {
        return label;
      }
    }

    return 'Rich Text';
  }

  (function () {
    var form = document.getElementById('page-edit-form');
    var list = document.getElementById('page-body-blocks-list');
    var addTextButton = document.getElementById('page-text-block-add');
    var channelSelect = document.getElementById('channel_slug');
    var template = document.getElementById('page-body-block-template');
    var dropdownButtons = Array.from(document.querySelectorAll('[data-rvn-add-body-type]'));

    if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function resolvePreferredTextEditor() {
      if (!(addTextButton instanceof HTMLButtonElement)) {
        return 'tinymce';
      }

      var editorDefault = normalizeBodyBlockType(addTextButton.getAttribute('data-rvn-site-editor-default') || 'tinymce');
      if (!(channelSelect instanceof HTMLSelectElement)) {
        return editorDefault;
      }

      var selectedOption = channelSelect.options[channelSelect.selectedIndex];
      if (!(selectedOption instanceof HTMLOptionElement)) {
        return editorDefault;
      }

      var override = String(selectedOption.getAttribute('data-rvn-channel-editor-override') || 'inherit').toLowerCase().trim();
      if (override === 'inherit') {
        return editorDefault;
      }

      var resolved = normalizeBodyBlockType(override);
      return (['tinymce', 'plaintext', 'autobr', 'markdown'].indexOf(bodyBlockEditorMode(resolved)) === -1)
        ? editorDefault
        : resolved;
    }

    function syncPreferredTextEditorFromChannel() {
      if (!(addTextButton instanceof HTMLButtonElement)) {
        return;
      }

      addTextButton.setAttribute('data-rvn-default-text-editor', resolvePreferredTextEditor());
    }

    function syncTaxonomyPickersFromChannel() {
      if (!(channelSelect instanceof HTMLSelectElement)) {
        return;
      }

      var selectedOption = channelSelect.options[channelSelect.selectedIndex];
      var categorySelection = '0';
      var tagSelection = '0';
      if (selectedOption instanceof HTMLOptionElement) {
        categorySelection = String(selectedOption.getAttribute('data-rvn-channel-category-sets') || '0');
        tagSelection = String(selectedOption.getAttribute('data-rvn-channel-tag-sets') || '0');
      }

      if (typeof ravenCategoryChipPicker !== 'undefined' && ravenCategoryChipPicker && typeof ravenCategoryChipPicker.syncAllowedSets === 'function') {
        ravenCategoryChipPicker.syncAllowedSets(categorySelection);
      }
      if (typeof ravenTagChipPicker !== 'undefined' && ravenTagChipPicker && typeof ravenTagChipPicker.syncAllowedSets === 'function') {
        ravenTagChipPicker.syncAllowedSets(tagSelection);
      }
    }

    function configureRowForType(row, type) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var textarea = row.querySelector('textarea[data-rvn-body-content="1"]');
      var pathInput = row.querySelector('input[data-rvn-body-content-path="1"]');
      var galleryHelp = row.querySelector('[data-rvn-body-gallery-help="1"]');
      var labelNode = row.querySelector('[data-rvn-body-type-label="1"]');

      if (!(textarea instanceof HTMLTextAreaElement) || !(pathInput instanceof HTMLInputElement)) {
        return;
      }

      var normalizedType = normalizeBodyBlockType(type);
      var editorMode = bodyBlockEditorMode(normalizedType);
      if (labelNode instanceof HTMLElement) {
        labelNode.textContent = bodyBlockLabel(normalizedType);
      }

      var isPathType = editorMode === 'markdown_file';
      var isGalleryType = editorMode === 'gallery';
      textarea.style.display = (isPathType || isGalleryType) ? 'none' : '';
      pathInput.style.display = isPathType ? '' : 'none';
      if (galleryHelp instanceof HTMLElement) {
        galleryHelp.style.display = isGalleryType ? '' : 'none';
      }
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-rvn-body-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var typeInput = row.querySelector('input[data-rvn-body-type-input="1"]');
        var textarea = row.querySelector('textarea[data-rvn-body-content="1"]');
        var pathInput = row.querySelector('input[data-rvn-body-content-path="1"]');
        var cssIdInput = row.querySelector('input[data-rvn-body-css-id="1"]');
        var cssClassInput = row.querySelector('input[data-rvn-body-css-class="1"]');
        if (!(typeInput instanceof HTMLInputElement) || !(textarea instanceof HTMLTextAreaElement) || !(pathInput instanceof HTMLInputElement)) {
          return;
        }

        var type = normalizeBodyBlockType(typeInput.value);
        var editorMode = bodyBlockEditorMode(type);
        typeInput.value = type;
        typeInput.name = 'content_blocks[' + index + '][type]';
        if (cssIdInput instanceof HTMLInputElement) {
          cssIdInput.name = 'content_blocks[' + index + '][css_id]';
        }
        if (cssClassInput instanceof HTMLInputElement) {
          cssClassInput.name = 'content_blocks[' + index + '][css_class]';
        }

        destroyTinyMceForTextarea(textarea);
        destroyEasyMdeForTextarea(textarea);

        textarea.id = 'body_block_' + index;
        pathInput.id = 'body_block_' + index + '_path';

        if (editorMode === 'gallery') {
          textarea.name = '';
          pathInput.name = '';
        } else if (editorMode === 'markdown_file') {
          textarea.name = '';
          pathInput.name = 'content_blocks[' + index + '][content]';
        } else {
          textarea.name = 'content_blocks[' + index + '][content]';
          pathInput.name = '';
        }

        configureRowForType(row, type);

        if (editorMode === 'tinymce') {
          initTinyMceForTextarea(textarea);
        } else if (editorMode === 'markdown') {
          initEasyMdeForTextarea(textarea);
        }
      });
    }

    function bindRow(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.setAttribute('draggable', 'false');
      var dragHandle = row.querySelector('[data-rvn-body-drag-handle="1"]');
      if (dragHandle instanceof HTMLElement) {
        dragHandle.setAttribute('draggable', 'true');
      }
      var cssIdInput = row.querySelector('input[data-rvn-body-css-id="1"]');
      if (cssIdInput instanceof HTMLInputElement) {
        cssIdInput.addEventListener('input', function () {
          validateBodyCssIdInput(cssIdInput);
        });
        cssIdInput.addEventListener('blur', function () {
          validateBodyCssIdInput(cssIdInput);
        });
      }
      var removeButton = row.querySelector('[data-rvn-body-remove="1"]');
      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', function () {
          var textarea = row.querySelector('textarea[data-rvn-body-content="1"]');
          if (textarea instanceof HTMLTextAreaElement) {
            destroyTinyMceForTextarea(textarea);
            destroyEasyMdeForTextarea(textarea);
          }

          row.remove();
          reindexRows();
        });
      }
    }

    function validateBodyCssIdInput(input) {
      if (!(input instanceof HTMLInputElement)) {
        return true;
      }

      var row = input.closest('[data-rvn-body-row="1"]');
      var errorNode = row instanceof HTMLElement
        ? row.querySelector('[data-rvn-body-css-id-error="1"]')
        : null;
      var typedValue = String(input.value || '');
      var rawValue = typedValue.trim();
      var normalizedValue = rawValue.replace(/^#+/, '');
      var tokens = normalizedValue.split(/[\s,]+/).filter(function (token) {
        return String(token || '').trim() !== '';
      });
      var hasWhitespace = /\s/.test(typedValue);
      var hasMultipleIds = hasWhitespace || tokens.length > 1 || normalizedValue.indexOf('#') !== -1;

      input.classList.toggle('is-invalid', hasMultipleIds);
      if (errorNode instanceof HTMLElement) {
        errorNode.classList.toggle('d-none', !hasMultipleIds);
      }

      return !hasMultipleIds;
    }

    function extractFirstCssIdToken(value) {
      var typedValue = String(value || '');
      var normalizedValue = typedValue.trim().replace(/^#+/, '');
      var tokens = normalizedValue.split(/[\s,#]+/).filter(function (token) {
        return String(token || '').trim() !== '';
      });

      return tokens.length > 0 ? String(tokens[0]) : '';
    }

    function addBodyBlock(type, content) {
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('[data-rvn-body-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var normalizedType = normalizeBodyBlockType(type);
      var typeInput = row.querySelector('input[data-rvn-body-type-input="1"]');
      var textarea = row.querySelector('textarea[data-rvn-body-content="1"]');
      var pathInput = row.querySelector('input[data-rvn-body-content-path="1"]');
      if (!(typeInput instanceof HTMLInputElement) || !(textarea instanceof HTMLTextAreaElement) || !(pathInput instanceof HTMLInputElement)) {
        return;
      }

      typeInput.value = normalizedType;
      var editorMode = bodyBlockEditorMode(normalizedType);
      if (editorMode === 'markdown_file') {
        pathInput.value = String(content || '');
      } else if (editorMode === 'gallery') {
        textarea.value = '';
        pathInput.value = '';
      } else {
        textarea.value = String(content || '');
      }

      list.appendChild(row);
      bindRow(row);
      reindexRows();
    }

    dropdownButtons.forEach(function (button) {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      button.addEventListener('click', function () {
        var type = String(button.getAttribute('data-rvn-add-body-type') || 'tinymce');
        addBodyBlock(type, '');
      });
    });

    if (addTextButton instanceof HTMLButtonElement) {
      addTextButton.addEventListener('click', function () {
        var preferred = normalizeBodyBlockType(addTextButton.getAttribute('data-rvn-default-text-editor') || 'tinymce');
        if (['tinymce', 'plaintext', 'autobr', 'markdown'].indexOf(bodyBlockEditorMode(preferred)) === -1) {
          preferred = 'tinymce';
        }
        addBodyBlock(preferred, '');
      });
    }

    if (channelSelect instanceof HTMLSelectElement) {
      channelSelect.addEventListener('change', syncPreferredTextEditorFromChannel);
      channelSelect.addEventListener('change', syncTaxonomyPickersFromChannel);
    }
    syncPreferredTextEditorFromChannel();
    syncTaxonomyPickersFromChannel();

    var draggingRow = null;

    list.addEventListener('dragstart', function (event) {
      var source = event.target;
      if (!(source instanceof HTMLElement)) {
        return;
      }

      var dragHandle = source.closest('[data-rvn-body-drag-handle="1"]');
      if (!(dragHandle instanceof HTMLElement)) {
        if (event.preventDefault) {
          event.preventDefault();
        }
        return;
      }

      var row = dragHandle.closest('[data-rvn-body-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      if (window.tinymce) {
        window.tinymce.triggerSave();
      }
      list.querySelectorAll('textarea[data-rvn-body-content="1"]').forEach(function (textareaNode) {
        if (!(textareaNode instanceof HTMLTextAreaElement)) {
          return;
        }
        var markdownInstance = textareaNode.__ravenEasyMde;
        if (markdownInstance && markdownInstance.codemirror && typeof markdownInstance.codemirror.save === 'function') {
          markdownInstance.codemirror.save();
        }
      });

      draggingRow = row;
      draggingRow.classList.add('opacity-75');

      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'body-row');
      }
    });

    list.addEventListener('dragover', function (event) {
      if (!(draggingRow instanceof HTMLElement)) {
        return;
      }

      event.preventDefault();
      var targetNode = event.target;
      if (!(targetNode instanceof HTMLElement)) {
        return;
      }

      var targetRow = targetNode.closest('[data-rvn-body-row="1"]');
      if (!(targetRow instanceof HTMLElement) || targetRow === draggingRow) {
        return;
      }

      var rect = targetRow.getBoundingClientRect();
      var insertBeforeTarget = (event.clientY - rect.top) < (rect.height / 2);
      if (insertBeforeTarget) {
        list.insertBefore(draggingRow, targetRow);
      } else {
        list.insertBefore(draggingRow, targetRow.nextSibling);
      }
    });

    list.addEventListener('drop', function (event) {
      if (draggingRow instanceof HTMLElement) {
        event.preventDefault();
      }
    });

    list.addEventListener('dragend', function () {
      if (!(draggingRow instanceof HTMLElement)) {
        return;
      }

      draggingRow.classList.remove('opacity-75');
      draggingRow = null;
      reindexRows();
    });

    list.querySelectorAll('[data-rvn-body-row="1"]').forEach(function (row) {
      bindRow(row);
    });

    reindexRows();

    if (form instanceof HTMLFormElement) {
      form.addEventListener('submit', function () {
        list.querySelectorAll('input[data-rvn-body-css-id="1"]').forEach(function (cssIdNode) {
          if (!(cssIdNode instanceof HTMLInputElement)) {
            return;
          }

          cssIdNode.value = extractFirstCssIdToken(cssIdNode.value);
          validateBodyCssIdInput(cssIdNode);
        });

        if (window.tinymce) {
          window.tinymce.triggerSave();
        }

        list.querySelectorAll('textarea[data-rvn-body-content="1"]').forEach(function (textareaNode) {
          if (!(textareaNode instanceof HTMLTextAreaElement)) {
            return;
          }
          var markdownInstance = textareaNode.__ravenEasyMde;
          if (markdownInstance && markdownInstance.codemirror && typeof markdownInstance.codemirror.save === 'function') {
            markdownInstance.codemirror.save();
          }
        });
      });
    }
  })();
</script>
