<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/tag/set_edit.php
 * Admin panel tag-set edit template.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $set */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$setId = $set !== null ? (int) ($set['id'] ?? 0) : null;
$hasPersistedSet = $set !== null;
$isDefaultSet = $hasPersistedSet && $setId === 1;
$deleteFormId = 'delete-tag-set-form';
$tagSetToolbarItems = [];
if (!$isDefaultSet) {
    $tagSetToolbarItems[] = '<button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Tag Set</button>';
}
$tagSetToolbarItems[] = '<a href="' . e($panelBase) . '/tag/set" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Sets</a>';
if ($hasPersistedSet && !$isDefaultSet) {
    $tagSetToolbarItems[] = '<button type="submit" class="btn btn-danger" form="' . e($deleteFormId) . '" onclick="return confirm(\'Delete this tag set?\');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Tag Set</button>';
}
?>
<?= Header::render([
    'title_html' => $set === null
        ? 'New Tag Set'
        : 'Edit Tag Set: <span class="text-primary">\'' . e((string) ($set['name'] ?? 'Untitled')) . '\'</span>',
    'summary' => 'Tag sets define which tags channels can use.',
    'help_url' => $panelBase . '/docs/tags',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedSet && !$isDefaultSet): ?>
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/tag/set/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $setId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/tag/set/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $setId !== null ? (string) $setId : '' ?>">
    <?= Toolbar::render([
        'items' => $tagSetToolbarItems,
        'class' => 'rvnp-editor-actions',
    ]) ?>

    <section class="card">
        <div class="card-body">
            <div class="form-group">
                <label for="name" class="form-label">Name</label>
                <input id="name" name="name" class="form-control<?= $isDefaultSet ? ' bg-light text-muted' : '' ?>"<?= $isDefaultSet ? ' disabled aria-disabled="true" tabindex="-1"' : ' required' ?> value="<?= e((string) ($set['name'] ?? '')) ?>">
                <div class="form-text">Display name for this tag set in taxonomy controls.</div>
                <?php if ($isDefaultSet): ?>
                    <input type="hidden" name="name" value="<?= e((string) ($set['name'] ?? '')) ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="slug" class="form-label">Slug</label>
                <input id="slug" name="slug" class="form-control<?= $isDefaultSet ? ' bg-light text-muted' : '' ?>"<?= $isDefaultSet ? ' disabled aria-disabled="true" tabindex="-1"' : ' required' ?> value="<?= e((string) ($set['slug'] ?? '')) ?>">
                <?php if ($isDefaultSet): ?>
                    <input type="hidden" name="slug" value="<?= e((string) ($set['slug'] ?? '')) ?>">
                <?php endif; ?>
                <?php if ($isDefaultSet): ?>
                    <div class="form-text">The stock Default Tag Set keeps the reserved slug <code>default</code>.</div>
                <?php else: ?>
                    <div class="form-text">Canonical slug used to distinguish this tag set.</div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-control<?= $isDefaultSet ? ' bg-light text-muted' : '' ?>" rows="4"<?= $isDefaultSet ? ' disabled aria-disabled="true" tabindex="-1"' : '' ?>><?= e((string) ($set['description'] ?? '')) ?></textarea>
                <div class="form-text">Optional editorial description for this tag set.</div>
                <?php if ($isDefaultSet): ?>
                    <input type="hidden" name="description" value="<?= e((string) ($set['description'] ?? '')) ?>">
                <?php endif; ?>
            </div>
        </div>
    </section>
</form>
