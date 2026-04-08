<?php

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array<int, array<string, mixed>> $setRows */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Lib\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
?>
<header class="card">
    <div class="card-body">
        <h1>Category Sets</h1>
        <p class="text-muted mb-0">Manage reusable category sets that channels can share or isolate.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/category/set/edit"><i class="bi bi-collection me-2" aria-hidden="true"></i>New Category Set</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/category"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Categories</a>
</nav>

<section class="card">
    <div class="card-body">
        <?php if ($setRows === []): ?>
            <p class="text-muted mb-0">No category sets yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Categories</th>
                        <th scope="col">Channels</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($setRows as $setRow): ?>
                        <?php
                        $setId = (int) ($setRow['id'] ?? 0);
                        $isRoot = !empty($setRow['is_stock']);
                        $categoryCount = (int) ($setRow['category_count'] ?? 0);
                        $categoryListUrl = $panelBase . '/category?set=' . rawurlencode((string) $setId);
                        ?>
                        <tr>
                            <td><?= $setId ?></td>
                            <td><a href="<?= e($panelBase) ?>/category/set/edit/<?= $setId ?>"><?= e((string) ($setRow['name'] ?? 'Set')) ?></a></td>
                            <td><code><?= e((string) ($setRow['slug'] ?? '')) ?></code></td>
                            <td>
                                <?php if ($categoryCount > 0): ?>
                                    <a href="<?= e($categoryListUrl) ?>"><?= $categoryCount ?></a>
                                <?php else: ?>
                                    <?= $categoryCount ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($setRow['channel_count'] ?? 0) ?></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a class="btn btn-primary btn-sm" href="<?= e($panelBase) ?>/category/set/edit/<?= $setId ?>" title="Edit" aria-label="Edit">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <?php if (!$isRoot): ?>
                                        <form method="post" action="<?= e($panelBase) ?>/category/set/delete" onsubmit="return confirm('Delete this category set?');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="id" value="<?= $setId ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">
                                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/category/set/edit"><i class="bi bi-collection me-2" aria-hidden="true"></i>New Category Set</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/category"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Categories</a>
</nav>
