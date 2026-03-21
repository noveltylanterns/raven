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
$activeTab = in_array($requestedTab, ['basic', 'content', 'media'], true) ? $requestedTab : 'basic';
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
            class="tab-pane fade<?= $activeTab === 'content' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-content"
            role="tabpanel"
            aria-labelledby="channel-content-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label for="editor_override" class="form-label">Editor Override</label>
                <select id="editor_override" name="editor_override" class="form-select">
                    <option value="inherit"<?= $editorOverride === 'inherit' ? ' selected' : '' ?>>Use Global Default</option>
                    <option value="tinymce"<?= $editorOverride === 'tinymce' ? ' selected' : '' ?>>Rich Text (TinyMCE)</option>
                    <option value="plaintext"<?= $editorOverride === 'plaintext' ? ' selected' : '' ?>>Plaintext</option>
                    <option value="autobr"<?= $editorOverride === 'autobr' ? ' selected' : '' ?>>Auto &lt;br&gt;</option>
                    <option value="markdown"<?= $editorOverride === 'markdown' ? ' selected' : '' ?>>Markdown</option>
                </select>
                <div class="form-text">
                    Controls which block type the Page Editor inserts when using <strong>Add Text Block</strong> for pages in this channel.
                </div>
            </div>

            <div class="form-group mb-0">
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

            <div class="form-group mb-0 mt-3">
                <label class="form-label d-block">Route Separator</label>
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
