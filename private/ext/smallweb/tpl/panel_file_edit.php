<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/tpl/panel_file_edit.php
 * Smallweb extension file create/edit template (protocol-generic).
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

/** @var string $protocol */
/** @var array{slug: string, type: string, hidden: bool, executable: bool, content: string, filename: string}|null $fileData */
/** @var string $savePath */
/** @var string $deletePath */
/** @var string $indexPath */
/** @var callable(string): string $panelUrl */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */
/** @var string $subdir */
/** @var \Raven\Smallweb\SmallwebService $svc */

use function Raven\Core\Support\e;

$isEditMode = is_array($fileData);
$fileSlug = (string) ($fileData['slug'] ?? '');
$fileType = (string) ($fileData['type'] ?? '');
$fileHidden = (bool) ($fileData['hidden'] ?? false);
$fileExecutable = (bool) ($fileData['executable'] ?? false);
$fileContent = (string) ($fileData['content'] ?? '');
$originalFilename = (string) ($fileData['filename'] ?? '');
$subdir = (string) ($subdir ?? '');
$parentDirs = is_array($parentDirs ?? null) ? $parentDirs : null;
$hasDirs = $parentDirs !== null;

$protocolTypes = $svc->protocolTypes($protocol);
$supportsHidden = $svc->protocolSupportsHidden($protocol);
$supportsExecutable = $svc->protocolSupportsExecutable($protocol);

if ($fileType === '' || !isset($protocolTypes[$fileType])) {
    $fileType = (string) array_key_first($protocolTypes);
}

$protoLabel = $svc->protocolLabel($protocol);
$backUrl = $panelUrl('/smallweb/' . $protocol);
$dirQuery = $subdir !== '' ? '?dir=' . rawurlencode($subdir) : '';
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1><?= $isEditMode ? 'Edit ' . e($protoLabel) . ' Page: <code style="text-transform:none">' . e($originalFilename) . '</code>' : 'New ' . e($protoLabel) . ' Page' ?></h1>
        </div>
        <?php if ($subdir !== ''): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= e($backUrl) ?>"><?= e($protoLabel) ?></a></li>
                <?php
                    $crumbParts = explode('/', $subdir);
                    $crumbPath = '';
                    foreach ($crumbParts as $i => $part):
                        $crumbPath .= ($crumbPath !== '' ? '/' : '') . $part;
                        $isLast = $i === count($crumbParts) - 1;
                ?>
                <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
                    <?php if ($isLast): ?>
                        <code style="text-transform:none"><?= e($part) ?></code>
                    <?php else: ?>
                        <code style="text-transform:none"><?= e($part) ?></code>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php elseif (!$isEditMode): ?>
        <p class="text-muted mb-0">Create a new page for the <?= e(strtolower($protoLabel)) ?> protocol.</p>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php // Standalone form element so submit buttons outside the section can target it via form="" ?>
<form id="sw-file-form" method="post" action="<?= e($savePath) ?>">
    <?= $csrfField ?>
    <?php if ($isEditMode): ?>
        <input type="hidden" name="original_filename" value="<?= e($originalFilename) ?>">
    <?php endif; ?>
    <?php if (!$hasDirs && $subdir !== ''): ?>
        <input type="hidden" name="dir" value="<?= e($subdir) ?>">
    <?php endif; ?>
</form>

<nav>
    <button class="btn btn-primary" type="submit" form="sw-file-form">
        <i class="bi bi-floppy me-2" aria-hidden="true"></i><?= $isEditMode ? 'Save Changes' : 'Create Page' ?>
    </button>
    <a href="<?= e($backUrl . $dirQuery) ?>" class="btn btn-secondary">
        <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Back to <?= e($protoLabel) ?> Pages
    </a>
</nav>

<section class="card">
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="<?= $hasDirs ? 'col-md-4' : 'col-md-6' ?>">
                <label for="file_slug" class="form-label">Slug</label>
                <input type="text" class="form-control font-monospace" id="file_slug" name="slug" form="sw-file-form"
                    value="<?= e($fileSlug) ?>"
                    required
                    pattern="[a-z0-9][a-z0-9_\-]*"
                    maxlength="120"
                    placeholder="my-page"
                    autocomplete="off">
            </div>
            <div class="col-md-3">
                <label for="file_type" class="form-label">Type</label>
                <select class="form-select" id="file_type" name="type" form="sw-file-form">
                    <?php foreach ($protocolTypes as $typeKey => $typeMeta): ?>
                        <option value="<?= e($typeKey) ?>"<?= $fileType === $typeKey ? ' selected' : '' ?>><?= e($typeMeta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasDirs): ?>
            <div class="col-md-3">
                <label for="file_dir" class="form-label">Parent</label>
                <select class="form-select font-monospace" id="file_dir" name="dir" form="sw-file-form">
                    <?php foreach ($parentDirs as $dirValue => $dirLabel): ?>
                        <option value="<?= e($dirValue) ?>"<?= $subdir === (string) $dirValue ? ' selected' : '' ?>><?= e($dirLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($supportsHidden): ?>
            <div class="col-md-3">
                <label for="file_published" class="form-label">Published</label>
                <select class="form-select" id="file_published" name="published" form="sw-file-form">
                    <option value="public"<?= !$fileHidden ? ' selected' : '' ?>>Public</option>
                    <option value="hidden"<?= $fileHidden ? ' selected' : '' ?>>Hidden</option>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($supportsExecutable): ?>
            <div class="<?= $hasDirs ? 'col-md-2' : 'col-md-3' ?>">
                <label for="file_executable" class="form-label">Executable?</label>
                <select class="form-select" id="file_executable" name="executable" form="sw-file-form">
                    <option value="no"<?= !$fileExecutable ? ' selected' : '' ?>>No</option>
                    <option value="yes"<?= $fileExecutable ? ' selected' : '' ?>>Yes</option>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="mb-0">
            <label for="file_content" class="form-label">Content</label>
            <textarea class="form-control font-monospace" id="file_content" name="content" form="sw-file-form"
                rows="20"
                spellcheck="false"
                style="tab-size: 4; white-space: pre; overflow-wrap: normal; overflow-x: auto;"
            ><?= e($fileContent) ?></textarea>
        </div>
    </div>
</section>

<?php if ($isEditMode): ?>
<section class="card border-danger">
    <div class="card-body">
        <h3 class="text-danger">Delete Page</h3>
        <p class="text-muted">This will permanently delete <code><?= e($originalFilename) ?></code> from disk.</p>
        <form method="post" action="<?= e($deletePath) ?>">
            <?= $csrfField ?>
            <input type="hidden" name="filename" value="<?= e($originalFilename) ?>">
            <?php if ($subdir !== ''): ?>
                <input type="hidden" name="dir" value="<?= e($subdir) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete <?= e($originalFilename) ?>?');">
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete This Page
            </button>
        </form>
    </div>
</section>
<?php endif; ?>

<nav>
    <button class="btn btn-primary" type="submit" form="sw-file-form">
        <i class="bi bi-floppy me-2" aria-hidden="true"></i><?= $isEditMode ? 'Save Changes' : 'Create Page' ?>
    </button>
    <a href="<?= e($backUrl . $dirQuery) ?>" class="btn btn-secondary">
        <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Back to <?= e($protoLabel) ?> Pages
    </a>
</nav>
