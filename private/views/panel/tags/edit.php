<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/tags/edit.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $tag */
/** @var string $tagRoutePrefix */
/** @var string $imageAllowedExtensions */
/** @var int|null $imageMaxFilesizeKb */
/** @var array<string, array{width: int, height: int}> $imageVariantSpecs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$tagName = trim((string) ($tag['name'] ?? ''));
$tagId = (int) ($tag['id'] ?? 0);
$hasPersistedTag = $tagId > 0;
$tagSlug = trim((string) ($tag['slug'] ?? ''));
$tagRoutePrefix = trim((string) ($tagRoutePrefix ?? ''), '/');
$deleteFormId = 'delete-tag-form';
$coverPath = trim((string) ($tag['cover_image_path'] ?? ''));
$previewPath = trim((string) ($tag['preview_image_path'] ?? ''));
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
$tagPublicUrl = null;
if ($tag !== null && $publicBase !== '' && $tagSlug !== '' && $tagRoutePrefix !== '') {
    $tagPublicUrl = $publicBase . '/' . rawurlencode($tagRoutePrefix) . '/' . rawurlencode($tagSlug);
}
?>
<div class="card mb-3">
    <div class="card-body">
        <h1 class="mb-0">
            <?= $tag === null ? 'New Tag' : 'Edit Tag: \'' . e($tagName !== '' ? $tagName : 'Untitled') . '\'' ?>
        </h1>
        <?php if ($tag === null): ?>
            <p class="text-muted mt-2 mb-0">Create or update a tag and manage its preview/cover media.</p>
        <?php elseif ($tagPublicUrl !== null): ?>
            <p class="mt-2 mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($tagPublicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($tagPublicUrl) ?>"
                    aria-label="Open tag URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($tagPublicUrl) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedTag): ?>
    <!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
    <form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/tags/delete">
        <?= $csrfField ?>
        <input type="hidden" name="id" value="<?= $tagId ?>">
    </form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/tags/save" enctype="multipart/form-data">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $tagId ?>">

    <!-- Match page-editor ergonomics with right-aligned top actions. -->
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Tag</button>
        <a href="<?= e($panelBase) ?>/tags" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Tags</a>
        <?php if ($hasPersistedTag): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this tag? Existing page-tag links will be removed.');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Tag
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label for="name" class="form-label h5">Name</label>
                <!-- Tag names are display-facing labels shown in panel/public listings. -->
                <input id="name" name="name" class="form-control" required value="<?= e((string) ($tag['name'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label h5">Slug</label>
                <!-- Slug powers `/{tags.prefix}/{slug}` tag index URLs. -->
                <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($tag['slug'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label h5">Description</label>
                <!-- Optional description is editorial/context metadata for this taxonomy term. -->
                <textarea id="description" name="description" class="form-control" rows="4"><?= e((string) ($tag['description'] ?? '')) ?></textarea>
            </div>

            <div class="mb-0">
                <h2 class="h5">Media</h2>
                <p class="small text-muted mb-3">
                    Allowed extensions: <code><?= e($imageAllowedExtensions) ?></code>.
                    Max filesize: <code><?= e($maxFilesizeLabel) ?></code>.
                    <br>
                    Variants use configured contain sizes: <code>sm <?= e((string) $smallSpec['width']) ?>x<?= e((string) $smallSpec['height']) ?></code>,
                    <code>md <?= e((string) $mediumSpec['width']) ?>x<?= e((string) $mediumSpec['height']) ?></code>,
                    <code>lg <?= e((string) $largeSpec['width']) ?>x<?= e((string) $largeSpec['height']) ?></code>.
                </p>

                <div class="mb-3">
                    <label for="cover_image" class="form-label">Cover Image</label>
                    <input id="cover_image" name="cover_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                    <?php if ($coverPath !== ''): ?>
                        <div class="mt-2">
                            <img src="<?= e($coverUrl) ?>" alt="Current tag cover image" class="img-thumbnail" style="max-width: 240px;">
                        </div>
                        <div class="small text-muted mt-1">
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                                data-raven-copy-url="1"
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

                <div class="mb-0">
                    <label for="preview_image" class="form-label">Preview Image</label>
                    <input id="preview_image" name="preview_image" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png">
                    <?php if ($previewPath !== ''): ?>
                        <div class="mt-2">
                            <img src="<?= e($previewUrl) ?>" alt="Current tag preview image" class="img-thumbnail" style="max-width: 240px;">
                        </div>
                        <div class="small text-muted mt-1">
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-muted text-decoration-none align-baseline"
                                data-raven-copy-url="1"
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
    </div>

    <!-- Duplicate actions at bottom so long forms do not require scrolling upward. -->
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Tag</button>
        <a href="<?= e($panelBase) ?>/tags" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Tags</a>
        <?php if ($hasPersistedTag): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this tag? Existing page-tag links will be removed.');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Tag
            </button>
        <?php endif; ?>
    </div>
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

    document.querySelectorAll('button[data-raven-copy-url="1"][data-copy-text]').forEach(function (button) {
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
