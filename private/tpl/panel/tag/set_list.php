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
        <h1>Tag Sets</h1>
        <p class="text-muted mb-0">Manage reusable tag sets that channels can share or isolate.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<nav>
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/tag/set/edit"><i class="bi bi-collection me-2" aria-hidden="true"></i>New Tag Set</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/tag"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Tags</a>
</nav>

<section class="card">
    <div class="card-body">
        <?php if ($setRows === []): ?>
            <p class="text-muted mb-0">No tag sets yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Tags</th>
                        <th scope="col">Channels</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($setRows as $setRow): ?>
                        <?php
                        $setId = (int) ($setRow['id'] ?? 0);
                        $isRoot = !empty($setRow['is_stock']);
                        $tagCount = (int) ($setRow['tag_count'] ?? 0);
                        $tagListUrl = $panelBase . '/tag?set=' . rawurlencode((string) $setId);
                        ?>
                        <tr>
                            <td><?= $setId ?></td>
                            <td><a href="<?= e($panelBase) ?>/tag/set/edit/<?= $setId ?>"><?= e((string) ($setRow['name'] ?? 'Set')) ?></a></td>
                            <td><code><?= e((string) ($setRow['slug'] ?? '')) ?></code></td>
                            <td>
                                <?php if ($tagCount > 0): ?>
                                    <a href="<?= e($tagListUrl) ?>"><?= $tagCount ?></a>
                                <?php else: ?>
                                    <?= $tagCount ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($setRow['channel_count'] ?? 0) ?></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <a class="btn btn-primary btn-sm" href="<?= e($panelBase) ?>/tag/set/edit/<?= $setId ?>" title="Edit" aria-label="Edit">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <?php if (!$isRoot): ?>
                                        <form method="post" action="<?= e($panelBase) ?>/tag/set/delete" onsubmit="return confirm('Delete this tag set?');">
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
    <a class="btn btn-primary" href="<?= e($panelBase) ?>/tag/set/edit"><i class="bi bi-collection me-2" aria-hidden="true"></i>New Tag Set</a>
    <a class="btn btn-secondary" href="<?= e($panelBase) ?>/tag"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Tags</a>
</nav>
