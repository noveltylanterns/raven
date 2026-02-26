<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/updates.php
 * Admin panel update-system scaffold with status checks and guarded run action.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This screen is intentionally conservative until upstream release/update flow is production-ready.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array{
 *   source_repo: string,
 *   current_revision: string,
 *   latest_revision: string,
 *   status: string,
 *   message: string,
 *   checked_at: string
 * } $updateStatus */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$status = strtolower((string) ($updateStatus['status'] ?? 'unknown'));
$currentRevision = (string) ($updateStatus['current_revision'] ?? '');
$latestRevision = (string) ($updateStatus['latest_revision'] ?? '');
$checkedAt = (string) ($updateStatus['checked_at'] ?? '');
$statusMessage = (string) ($updateStatus['message'] ?? '');
$sourceRepo = (string) ($updateStatus['source_repo'] ?? '');

$badgeClass = 'text-bg-secondary';
if ($status === 'current') {
    $badgeClass = 'text-bg-success';
} elseif ($status === 'outdated') {
    $badgeClass = 'text-bg-warning';
} elseif ($status === 'error') {
    $badgeClass = 'text-bg-danger';
}

$canRunUpdater = $status === 'outdated';
?>
<div class="card mb-3">
    <div class="card-body">
        <h1 class="mb-3">Update System</h1>

        <?php if ($flashSuccess !== null): ?>
            <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
        <?php endif; ?>

        <?php if ($flashError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
        <?php endif; ?>

        <p class="text-muted mb-0">
            Upstream source:
            <a href="<?= e($sourceRepo) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceRepo) ?></a>
        </p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3">Current Status</h2>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($status)) ?></span>
            <span class="text-muted small">
                <?= e($statusMessage !== '' ? $statusMessage : 'No status message available.') ?>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label h6">Local Revision</label>
                <div><code><?= e($currentRevision !== '' ? $currentRevision : '<unknown>') ?></code></div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label h6">Latest Upstream Revision</label>
                <div><code><?= e($latestRevision !== '' ? $latestRevision : '<unknown>') ?></code></div>
            </div>
            <div class="col-12">
                <label class="form-label h6">Last Checked (UTC)</label>
                <div><?= e($checkedAt !== '' ? $checkedAt : 'Never') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5 mb-3">Actions</h2>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            <form method="post" action="<?= e($panelBase) ?>/updates/check" class="m-0">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary btn-sm">Check for Updates</button>
            </form>

            <form method="post" action="<?= e($panelBase) ?>/updates/run" class="m-0">
                <?= $csrfField ?>
                <button
                    type="submit"
                    class="btn btn-warning btn-sm"
                    <?= $canRunUpdater ? '' : 'disabled' ?>
                    title="<?= $canRunUpdater ? 'Run updater' : 'Updater can run only when a newer revision is detected.' ?>"
                >
                    Run Updater
                </button>
            </form>
        </div>

        <div class="form-text mt-3">
            The updater execution step is intentionally gated while Raven upstream packaging and migration validation are finalized.
            Data directories such as <code>private/db</code>, <code>private/tmp</code>, <code>private/ext</code>, and <code>public/uploads</code> will remain protected.
        </div>
    </div>
</div>
