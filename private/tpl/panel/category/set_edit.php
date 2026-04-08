<?php

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $set */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$setId = $set !== null ? (int) ($set['id'] ?? 0) : null;
$hasPersistedSet = $set !== null;
$isDefaultSet = $hasPersistedSet && $setId === 1;
$deleteFormId = 'delete-category-set-form';
?>
<header class="card">
    <div class="card-body">
        <h1><?= $set === null ? 'New Category Set' : 'Edit Category Set: <span class="text-primary">\'' . e((string) ($set['name'] ?? 'Untitled')) . '\'</span>' ?></h1>
        <p class="text-muted mb-0">Category sets define which categories channels can use.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedSet && !$isDefaultSet): ?>
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/category/set/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $setId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/category/set/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $setId !== null ? (string) $setId : '' ?>">
    <nav class="rvnp-editor-actions">
        <a href="<?= e($panelBase) ?>/category/set" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Sets</a>
        <?php if (!$isDefaultSet): ?>
            <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Category Set</button>
        <?php endif; ?>
        <?php if ($hasPersistedSet && !$isDefaultSet): ?>
            <button type="submit" class="btn btn-danger" form="<?= e($deleteFormId) ?>" onclick="return confirm('Delete this category set?');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Category Set</button>
        <?php endif; ?>
    </nav>

    <section class="card">
        <div class="card-body">
            <div class="form-group">
                <label for="name" class="form-label">Name</label>
                <input id="name" name="name" class="form-control<?= $isDefaultSet ? ' bg-light text-muted' : '' ?>"<?= $isDefaultSet ? ' disabled aria-disabled="true" tabindex="-1"' : ' required' ?> value="<?= e((string) ($set['name'] ?? '')) ?>">
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
                    <div class="form-text">The stock Default Category Set keeps the reserved slug <code>default</code>.</div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-control<?= $isDefaultSet ? ' bg-light text-muted' : '' ?>" rows="4"<?= $isDefaultSet ? ' disabled aria-disabled="true" tabindex="-1"' : '' ?>><?= e((string) ($set['description'] ?? '')) ?></textarea>
                <?php if ($isDefaultSet): ?>
                    <input type="hidden" name="description" value="<?= e((string) ($set['description'] ?? '')) ?>">
                <?php endif; ?>
            </div>
        </div>
    </section>
</form>
