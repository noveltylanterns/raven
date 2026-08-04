<?php

/**
 * RAVEN CMS
 * ~/private/ext/backup/tpl/panel_index.php
 * Backup & Restore panel view.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string $backupBasePath */
/** @var string|null $success */
/** @var string|null $error */

use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$success = isset($success) ? trim((string) $success) : '';
$error = isset($error) ? trim((string) $error) : '';
$backupBasePath = rtrim($backupBasePath, '/');
?>
<?= Header::render([
    'title' => 'Backup & Restore',
    'summary' => 'Export or restore Raven page content, taxonomy, channels, sets, redirects, and relationships.',
    'actions' => [
        '<a class="btn btn-primary btn-sm" href="' . e($backupBasePath) . '/export">'
            . '<i class="bi bi-download me-2" aria-hidden="true"></i>Export Backup'
            . '</a>',
    ],
    'actions_class' => 'd-flex align-items-center gap-2',
]) ?>

<?php if ($success !== ''): ?>
    <div class="alert alert-success" role="alert"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="alert alert-warning" role="alert">
    <strong>Restore warning:</strong>
    Restore is intended only for a new Raven system. The archive contains numeric IDs and is not
    designed to merge with an existing installation. Existing conflicts may cause the restore to fail.
</div>

<section class="card">
    <div class="card-body">
        <h2 class="h5">Restore a Raven backup</h2>
        <p class="text-muted">
            Upload a JSON backup exported from this utility. Pages, categories, tags, channels, taxonomy
            sets, redirects, and page relationships are restored with their original numeric IDs.
            Media files and media-tab content are not included.
        </p>
        <form method="post" action="<?= e($backupBasePath) ?>/import" enctype="multipart/form-data">
            <?= $csrfField ?>
            <div class="mb-3">
                <label class="form-label" for="backup_file">Backup JSON file</label>
                <input class="form-control" type="file" id="backup_file" name="backup_file" accept="application/json,.json" required>
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Restore this backup into the current system? Use this only on a new Raven system.');">
                <i class="bi bi-upload me-2" aria-hidden="true"></i>Restore Backup
            </button>
        </form>
    </div>
</section>
