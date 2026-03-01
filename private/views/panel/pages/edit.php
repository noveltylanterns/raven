<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/pages/edit.php
 * Admin panel page editor with content/meta/media tabs.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This view keeps editor UX logic in-template while controller handles all persistence.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $page */
/** @var array<int, array<string, mixed>> $channelOptions */
/** @var array<int, array<string, mixed>> $categoryOptions */
/** @var array<int, array<string, mixed>> $tagOptions */
/** @var array<int, array{id: int, name: string, slug: string}> $assignedCategories */
/** @var array<int, array{id: int, name: string, slug: string}> $assignedTags */
/** @var array<int, array<string, mixed>> $galleryImages */
/** @var string $imageUploadTarget */
/** @var int $imageMaxFilesPerUpload */
/** @var array<int, array{extension: string, label: string, shortcode: string}> $shortcodeInsertItems */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$pageId = (int) ($page['id'] ?? 0);
$hasPersistedPage = $pageId > 0;
$deleteFormId = 'delete-page-form';
$selectedChannelSlug = (string) ($page['channel_slug'] ?? '');
$selectedStatus = (int) ($page['is_published'] ?? 1) === 1 ? 'published' : 'draft';
$galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1;
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['content', 'meta', 'media'], true) ? $requestedTab : 'content';
$maxFilesPerUploadNote = $imageMaxFilesPerUpload > 0
    ? 'max ' . $imageMaxFilesPerUpload . ' per upload'
    : 'no limit per upload';

// Build a permalink preview for published pages shown in the editor header area.
$pageSlug = trim((string) ($page['slug'] ?? ''));
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$permalinkBase = $normalizedDomain;
if ($permalinkBase !== '' && !preg_match('#^https?://#i', $permalinkBase)) {
    $permalinkBase = 'https://' . $permalinkBase;
}
$permalinkBase = rtrim($permalinkBase, '/');
$permalinkPathParts = [];
if ($selectedChannelSlug !== '') {
    $permalinkPathParts[] = trim($selectedChannelSlug, '/');
}
if ($pageSlug !== '') {
    $permalinkPathParts[] = trim($pageSlug, '/');
}
$publishedPermalink = null;
if ($selectedStatus === 'published' && $permalinkBase !== '' && $pageSlug !== '') {
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
$extendedBlocks = [];
$rawExtendedBlocks = $page['extended_blocks'] ?? null;
if (is_array($rawExtendedBlocks)) {
    foreach ($rawExtendedBlocks as $entry) {
        if (!is_scalar($entry) && $entry !== null) {
            continue;
        }

        $value = (string) ($entry ?? '');
        if (trim($value) === '') {
            continue;
        }

        $extendedBlocks[] = $value;
    }
}
$pageTitle = trim((string) ($page['title'] ?? ''));
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= $page === null ? 'Create New Page' : 'Edit Page: \'' . e($pageTitle !== '' ? $pageTitle : 'Untitled') . '\'' ?>
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
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/pages/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $pageId ?>">
</form>
<?php endif; ?>

<form id="page-edit-form" method="post" action="<?= e($panelBase) ?>/pages/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $pageId ?>">

    <!-- Mirror list-page action layout with right-aligned top controls. -->
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Page</button>
        <a href="<?= e($panelBase) ?>/pages" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Pages</a>
        <?php if ($hasPersistedPage): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this page?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Page
            </button>
        <?php endif; ?>
    </div>

    <!-- Bootstrap tabs split primary writing fields from metadata and media controls. -->
    <ul class="nav nav-tabs" id="pageEditorTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link<?= $activeTab === 'content' ? ' active' : '' ?>"
                        id="page-content-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#page-content-pane"
                        type="button"
                        role="tab"
                        aria-controls="page-content-pane"
                        aria-selected="<?= $activeTab === 'content' ? 'true' : 'false' ?>"
                    >
                        Content
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link<?= $activeTab === 'meta' ? ' active' : '' ?>"
                        id="page-meta-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#page-meta-pane"
                        type="button"
                        role="tab"
                        aria-controls="page-meta-pane"
                        aria-selected="<?= $activeTab === 'meta' ? 'true' : 'false' ?>"
                    >
                        Meta
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link<?= $activeTab === 'media' ? ' active' : '' ?>"
                        id="page-media-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#page-media-pane"
                        type="button"
                        role="tab"
                        aria-controls="page-media-pane"
                        aria-selected="<?= $activeTab === 'media' ? 'true' : 'false' ?>"
                    >
                        Media
                    </button>
                </li>
            </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 rounded-bottom p-3" id="pageEditorTabsContent">
                <div
                    class="tab-pane fade<?= $activeTab === 'content' ? ' show active' : '' ?>"
                    id="page-content-pane"
                    role="tabpanel"
                    aria-labelledby="page-content-tab"
                    tabindex="0"
                >
                    <div class="mb-3">
                        <label for="title" class="form-label h5">Title</label>
                        <input id="title" name="title" class="form-control" required value="<?= e((string) ($page['title'] ?? '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="content" class="form-label h5">Body</label>
                        <textarea id="content" name="content" class="form-control" rows="12" data-raven-editor-input="1"><?= e((string) ($page['content'] ?? '')) ?></textarea>
                    </div>

                    <div class="mb-0">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <label class="form-label h5 mb-0">Extended Blocks</label>
                            <button type="button" class="btn btn-primary btn-sm" id="page-extended-block-add">Add Extended Block</button>
                        </div>
                        <div class="form-text mb-2">
                            Optional. Add one or more rich-text blocks for longer page layouts.
                        </div>
                        <div id="page-extended-blocks-list">
                            <?php foreach ($extendedBlocks as $extendedIndex => $extendedBlock): ?>
                                <div class="border rounded p-3 mb-3" data-raven-extended-row="1">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="text-muted drag" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                                        <button type="button" class="btn btn-danger btn-sm" data-raven-extended-remove="1">Remove</button>
                                    </div>
                                    <textarea
                                        id="extended_block_<?= (int) $extendedIndex ?>"
                                        name="extended_blocks[<?= (int) $extendedIndex ?>]"
                                        class="form-control"
                                        rows="10"
                                        data-raven-editor-input="1"
                                        data-raven-extended-field="1"
                                    ><?= e($extendedBlock) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div
                    class="tab-pane fade<?= $activeTab === 'meta' ? ' show active' : '' ?>"
                    id="page-meta-pane"
                    role="tabpanel"
                    aria-labelledby="page-meta-tab"
                    tabindex="0"
                >
                    <div class="mb-3">
                        <label for="status" class="form-label h5">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="published"<?= $selectedStatus === 'published' ? ' selected' : '' ?>>Published</option>
                            <option value="draft"<?= $selectedStatus === 'draft' ? ' selected' : '' ?>>Draft</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label h5">Slug</label>
                        <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($page['slug'] ?? '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label h5">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"><?= e((string) ($page['description'] ?? '')) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="channel_slug" class="form-label h5">Channel</label>
                        <select id="channel_slug" name="channel_slug" class="form-select">
                            <option value=""<?= $selectedChannelSlug === '' ? ' selected' : '' ?>>&lt;none&gt;</option>
                            <?php foreach ($channelOptions as $channel): ?>
                                <?php $slug = (string) ($channel['slug'] ?? ''); ?>
                                <option value="<?= e($slug) ?>"<?= $selectedChannelSlug === $slug ? ' selected' : '' ?>>
                                    <?= e((string) ($channel['name'] ?? $slug)) ?> (<?= e($slug) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" data-raven-chip-picker="categories">
                        <label class="form-label h5" for="add-category-button">Categories</label>
                        <div class="d-flex flex-wrap align-items-center gap-2" data-raven-chip-list="categories">
                            <?php foreach ($assignedCategories as $category): ?>
                                <span class="badge text-bg-primary d-inline-flex align-items-center gap-2" data-raven-chip-id="<?= (int) $category['id'] ?>">
                                    <span><?= e((string) $category['name']) ?></span>
                                    <button type="button" class="btn btn-sm p-0 border-0 text-white" data-raven-chip-remove="categories" aria-label="Remove category">&times;</button>
                                    <input type="hidden" name="category_ids[]" value="<?= (int) $category['id'] ?>">
                                </span>
                            <?php endforeach; ?>

                            <div class="dropdown">
                                <button id="add-category-button" class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Add Category
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($categoryOptions === []): ?>
                                        <li><span class="dropdown-item-text text-muted">No categories available</span></li>
                                    <?php else: ?>
                                        <?php foreach ($categoryOptions as $categoryOption): ?>
                                            <?php
                                            $categoryId = (int) ($categoryOption['id'] ?? 0);
                                            $categoryName = (string) ($categoryOption['name'] ?? '');
                                            $categorySlug = (string) ($categoryOption['slug'] ?? '');
                                            ?>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-raven-add-chip="categories"
                                                    data-raven-option-id="<?= $categoryId ?>"
                                                    data-raven-option-label="<?= e($categoryName) ?>"
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

                    <div class="mb-3" data-raven-chip-picker="tags">
                        <label class="form-label h5" for="add-tag-button">Tags</label>
                        <div class="d-flex flex-wrap align-items-center gap-2" data-raven-chip-list="tags">
                            <?php foreach ($assignedTags as $tag): ?>
                                <span class="badge text-bg-secondary d-inline-flex align-items-center gap-2" data-raven-chip-id="<?= (int) $tag['id'] ?>">
                                    <span><?= e((string) $tag['name']) ?></span>
                                    <button type="button" class="btn btn-sm p-0 border-0 text-white" data-raven-chip-remove="tags" aria-label="Remove tag">&times;</button>
                                    <input type="hidden" name="tag_ids[]" value="<?= (int) $tag['id'] ?>">
                                </span>
                            <?php endforeach; ?>

                            <div class="dropdown">
                                <button id="add-tag-button" class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Add Tag
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($tagOptions === []): ?>
                                        <li><span class="dropdown-item-text text-muted">No tags available</span></li>
                                    <?php else: ?>
                                        <?php foreach ($tagOptions as $tagOption): ?>
                                            <?php
                                            $tagId = (int) ($tagOption['id'] ?? 0);
                                            $tagName = (string) ($tagOption['name'] ?? '');
                                            $tagSlug = (string) ($tagOption['slug'] ?? '');
                                            ?>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-raven-add-chip="tags"
                                                    data-raven-option-id="<?= $tagId ?>"
                                                    data-raven-option-label="<?= e($tagName) ?>"
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
                </div>

                <div
                    class="tab-pane fade<?= $activeTab === 'media' ? ' show active' : '' ?>"
                    id="page-media-pane"
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
                                Storage target: <code><?= e($imageUploadTarget) ?></code>
                            </div>
                            <div class="form-check mb-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    value="1"
                                    id="gallery_enabled"
                                    name="gallery_enabled"
                                    <?= $galleryEnabled ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="gallery_enabled">Display gallery on public page</label>
                            </div>
                        </div>

                        <div class="border rounded p-3 mb-3">
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
                                    data-raven-max-files="<?= (int) $imageMaxFilesPerUpload ?>"
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
                                    formaction="<?= e($panelBase) ?>/pages/gallery/upload"
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
                                    data-raven-gallery-select-all
                                >
                                    Select All
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-raven-gallery-clear-all
                                >
                                    Clear All
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-danger"
                                    name="gallery_delete_selected"
                                    value="1"
                                    data-raven-gallery-delete-selected
                                    formaction="<?= e($panelBase) ?>/pages/gallery/delete"
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
                                    $isPreview = !empty($galleryImage['is_preview']);
                                    $includeInGallery = array_key_exists('include_in_gallery', $galleryImage)
                                        ? !empty($galleryImage['include_in_gallery'])
                                        : true;
                                    $sharedAltTitle = (string) (($galleryImage['alt_text'] ?? '') !== ''
                                        ? $galleryImage['alt_text']
                                        : ($galleryImage['title_text'] ?? ''));
                                    ?>
                                    <div class="border rounded p-3 mb-3" data-raven-gallery-row>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="d-flex align-items-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-link btn-sm text-muted p-0 border-0 lh-1"
                                                    data-raven-gallery-drag-handle
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
                                                    data-raven-gallery-select
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
                                                                data-raven-gallery-single="cover"
                                                                value="1"
                                                                <?= $isCover ? 'checked' : '' ?>
                                                            >
                                                            <label class="form-check-label" for="gallery_cover_<?= $imageId ?>">Use as cover image</label>
                                                        </div>
                                                        <div class="form-check mb-0">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                name="gallery_images[<?= $imageId ?>][is_preview]"
                                                                id="gallery_preview_<?= $imageId ?>"
                                                                data-raven-gallery-single="preview"
                                                                value="1"
                                                                <?= $isPreview ? 'checked' : '' ?>
                                                            >
                                                            <label class="form-check-label" for="gallery_preview_<?= $imageId ?>">Use as preview image</label>
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
                                                        formaction="<?= e($panelBase) ?>/pages/gallery/delete"
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
                                    data-raven-gallery-select-all
                                >
                                    Select All
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-raven-gallery-clear-all
                                >
                                    Clear All
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-danger"
                                    name="gallery_delete_selected"
                                    value="1"
                                    data-raven-gallery-delete-selected
                                    formaction="<?= e($panelBase) ?>/pages/gallery/delete"
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

    <!-- Duplicate controls at bottom so long forms do not require scrolling back up. -->
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Page</button>
        <a href="<?= e($panelBase) ?>/pages" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Pages</a>
        <?php if ($hasPersistedPage): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this page?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Page
            </button>
        <?php endif; ?>
    </div>
</form>

<template id="page-extended-block-template">
    <div class="border rounded p-3 mb-3" data-raven-extended-row="1">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <span class="text-muted drag" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
            <button type="button" class="btn btn-danger btn-sm" data-raven-extended-remove="1">Remove</button>
        </div>
        <textarea
            class="form-control"
            rows="10"
            data-raven-editor-input="1"
            data-raven-extended-field="1"
        ></textarea>
    </div>
</template>

<style>
  /* Match Extended-row container styling to Media file rows. */
  body.raven-panel-theme #page-content-pane [data-raven-extended-row] {
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

  .raven-editor-color-hex-row input[data-raven-color-hex] {
    flex: 1 1 auto;
  }

  .raven-editor-color-dropdown button[data-raven-color-apply],
  .raven-editor-color-dropdown button[data-raven-color-clear] {
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

  .raven-editor-color-dropdown input[data-raven-color-hex] {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    text-transform: uppercase;
  }
</style>

<!-- TinyMCE loaded locally from Nginx /mce/ mapping (no CDN). -->
<script src="/mce/tinymce.min.js"></script>
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

    var maxFiles = parseInt(String(input.getAttribute('data-raven-max-files') || '10'), 10);
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

  // Cover/preview flags are single-choice; selecting one clears other rows in that group.
  (function () {
    var groupCheckboxes = Array.from(document.querySelectorAll('input[data-raven-gallery-single]'));
    if (groupCheckboxes.length === 0) {
      return;
    }

    function checkboxesForGroup(groupName) {
      return groupCheckboxes.filter(function (checkbox) {
        return checkbox instanceof HTMLInputElement
          && checkbox.getAttribute('data-raven-gallery-single') === groupName;
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
    ['cover', 'preview'].forEach(function (groupName) {
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

        var groupName = String(checkbox.getAttribute('data-raven-gallery-single') || '');
        if (groupName === '') {
          return;
        }

        enforceSingleSelection(groupName, checkbox);
      });
    });
  })();

  // Mirrors list-view behavior: checking gallery items highlights rows for bulk actions.
  (function () {
    var checkboxes = Array.from(document.querySelectorAll('input[data-raven-gallery-select]'));
    if (checkboxes.length === 0) {
      return;
    }

    var bulkButtons = Array.from(document.querySelectorAll('[data-raven-gallery-delete-selected]'));
    var selectAllButtons = Array.from(document.querySelectorAll('[data-raven-gallery-select-all]'));
    var clearAllButtons = Array.from(document.querySelectorAll('[data-raven-gallery-clear-all]'));

    function syncGallerySelectionState() {
      // Keeps row highlights + bulk button state in sync with checkbox state.
      var selectedCount = 0;

      checkboxes.forEach(function (checkbox) {
        if (!(checkbox instanceof HTMLInputElement)) {
          return;
        }

        var row = checkbox.closest('[data-raven-gallery-row]');
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

    var rows = Array.from(list.querySelectorAll('[data-raven-gallery-row]'));
    if (rows.length === 0) {
      return;
    }

    function syncSortOrderWithDom() {
      var galleryRows = Array.from(list.querySelectorAll('[data-raven-gallery-row]'));
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

      var row = source.closest('[data-raven-gallery-row]');
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

      var targetRow = targetNode.closest('[data-raven-gallery-row]');
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
  function initRavenChipPicker(kind, inputName, badgeClass) {
    var picker = document.querySelector('[data-raven-chip-picker="' + kind + '"]');
    if (!picker) {
      return;
    }

    var chipList = picker.querySelector('[data-raven-chip-list="' + kind + '"]');
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
      picker.querySelectorAll('[data-raven-add-chip="' + kind + '"]').forEach(function (button) {
        var id = String(button.getAttribute('data-raven-option-id') || '');
        button.disabled = ids.has(id);
      });
    }

    function createChip(id, label) {
      // Chip markup includes remove button + hidden input for form persistence.
      var chip = document.createElement('span');
      chip.className = 'badge ' + badgeClass + ' d-inline-flex align-items-center gap-2';
      chip.setAttribute('data-raven-chip-id', id);

      var labelNode = document.createElement('span');
      labelNode.textContent = label;

      var removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'btn btn-sm p-0 border-0 text-white';
      removeButton.setAttribute('data-raven-chip-remove', kind);
      removeButton.setAttribute('aria-label', 'Remove ' + kind.slice(0, -1));
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
      var remove = event.target.closest('[data-raven-chip-remove="' + kind + '"]');
      if (!remove) {
        return;
      }

      var chip = remove.closest('[data-raven-chip-id]');
      if (chip) {
        chip.remove();
        syncDropdown();
      }
    });

    picker.querySelectorAll('[data-raven-add-chip="' + kind + '"]').forEach(function (button) {
      button.addEventListener('click', function () {
        var id = String(button.getAttribute('data-raven-option-id') || '');
        var label = String(button.getAttribute('data-raven-option-label') || '');
        if (id === '' || label === '') {
          return;
        }

        if (selectedIds().has(id)) {
          return;
        }

        chipList.insertBefore(createChip(id, label), chipList.querySelector('.dropdown'));
        syncDropdown();
      });
    });

    syncDropdown();
  }

  initRavenChipPicker('categories', 'category_ids[]', 'text-bg-primary');
  initRavenChipPicker('tags', 'tag_ids[]', 'text-bg-secondary');

  var ravenGalleryItems = <?= json_encode($tinyMceGalleryItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
  var ravenShortcodeItems = <?= json_encode($shortcodeInsertItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
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
      + '  <canvas width="240" height="146" class="raven-editor-color-sv" data-raven-color-sv aria-label="Saturation and value"></canvas>'
      + '  <canvas width="20" height="146" class="raven-editor-color-hue" data-raven-color-hue aria-label="Hue"></canvas>'
      + '</div>'
      + '<div class="raven-picker-help">Drag inside the square for shade and brightness. Use the side bar for hue.</div>'
      + '<div class="raven-editor-color-hex-row">'
      + '  <span class="raven-editor-color-preview" data-raven-color-preview aria-hidden="true"></span>'
      + '  <input id="raven-color-hex-' + safeEditorId + '" type="text" class="form-control form-control-sm" data-raven-color-hex maxlength="7" placeholder="#000000">'
      + '</div>'
      + '<div class="text-danger small mt-1 d-none" data-raven-color-error></div>'
      + '<div class="raven-picker-row">'
      + '  <button type="button" class="btn btn-primary btn-sm" data-raven-color-apply>Apply Color</button>'
      + '  <button type="button" class="btn btn-outline-secondary btn-sm" data-raven-color-clear>Clear Formatting</button>'
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
      svCanvas: panel.querySelector('[data-raven-color-sv]'),
      hueCanvas: panel.querySelector('[data-raven-color-hue]'),
      previewNode: panel.querySelector('[data-raven-color-preview]'),
      hexInput: panel.querySelector('[data-raven-color-hex]'),
      errorNode: panel.querySelector('[data-raven-color-error]'),
      applyButton: panel.querySelector('[data-raven-color-apply]'),
      clearButton: panel.querySelector('[data-raven-color-clear]')
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

  function tinyMceConfigForTarget(textarea) {
    return {
      // Explicitly acknowledge TinyMCE OSS usage to suppress eval-mode warnings.
      license_key: 'gpl',
      target: textarea,
      height: 420,
      // Keep editor body typography generic sans-serif even when panel UI uses custom fonts.
      content_style: 'body { font-family: sans-serif; }',
      menubar: false,
      branding: false,
      promotion: false,
      plugins: 'lists link table code image paste hr',
      block_formats: 'Paragraph=p; Div=div; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Blockquote=blockquote; pre=pre',
      toolbar: 'ravenViewSource removeformat blocks ravenTextColor alignleft alignright aligncenter alignjustify bold italic underline strikethrough subscript superscript ravenInlineCode bullist numlist table hr link pastetext ravenGallery'
        + ((Array.isArray(ravenShortcodeItems) && ravenShortcodeItems.length > 0) ? ' ravenExtensions' : ''),
      setup: registerPageEditorButtons
    };
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

  (function () {
    var form = document.getElementById('page-edit-form');
    var list = document.getElementById('page-extended-blocks-list');
    var addButton = document.getElementById('page-extended-block-add');
    var template = document.getElementById('page-extended-block-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-raven-extended-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var textarea = row.querySelector('textarea[data-raven-extended-field="1"]');
        if (!(textarea instanceof HTMLTextAreaElement)) {
          return;
        }

        var fieldId = 'extended_block_' + index;
        if (textarea.id !== fieldId) {
          destroyTinyMceForTextarea(textarea);
          textarea.id = fieldId;
        }

        textarea.name = 'extended_blocks[' + index + ']';
        initTinyMceForTextarea(textarea);
      });
    }

    function bindRow(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.setAttribute('draggable', 'true');
      var removeButton = row.querySelector('[data-raven-extended-remove="1"]');
      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', function () {
          var textarea = row.querySelector('textarea[data-raven-extended-field="1"]');
          if (textarea instanceof HTMLTextAreaElement) {
            destroyTinyMceForTextarea(textarea);
          }

          row.remove();
          reindexRows();
        });
      }
    }

    var draggingRow = null;

    list.addEventListener('dragstart', function (event) {
      var source = event.target;
      if (!(source instanceof HTMLElement)) {
        return;
      }

      var row = source.closest('[data-raven-extended-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      if (window.tinymce) {
        // Persist current editor contents before moving row nodes in the DOM.
        window.tinymce.triggerSave();
      }

      draggingRow = row;
      draggingRow.classList.add('opacity-75');

      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'extended-row');
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

      var targetRow = targetNode.closest('[data-raven-extended-row="1"]');
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

    addButton.addEventListener('click', function () {
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('[data-raven-extended-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      list.appendChild(row);
      bindRow(row);
      reindexRows();
    });

    list.querySelectorAll('[data-raven-extended-row="1"]').forEach(function (row) {
      bindRow(row);
    });

    reindexRows();

    if (form instanceof HTMLFormElement) {
      form.addEventListener('submit', function () {
        if (window.tinymce) {
          window.tinymce.triggerSave();
        }
      });
    }
  })();

  initTinyMceForTextarea(document.getElementById('content'));
  document.querySelectorAll('textarea[data-raven-extended-field="1"]').forEach(function (textarea) {
    initTinyMceForTextarea(textarea);
  });
</script>
