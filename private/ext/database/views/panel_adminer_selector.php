<?php

/**
 * RAVEN CMS
 * ~/private/ext/database/views/panel_adminer_selector.php
 * Database Manager extension view for selecting Adminer launch target.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This selector keeps Adminer launch choices visible and deterministic.

declare(strict_types=1);

/** @var bool $canManageConfiguration */
/** @var bool $adminerInstalled */
/** @var bool $extensionEntrypointExists */
/** @var string $extensionsPath */
/** @var string $databasePath */
/** @var string $driver */
/** @var array<int, array{name: string, detail: string, launch_path: string}> $targets */
/** @var string|null $selectorError */
/** @var array{name?: string, version?: string} $extensionMeta */

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Database Manager'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$modeLabel = strtolower($driver) === 'sqlite' ? '.db Files' : 'SQL Tables';
$canLaunchAdminer = $extensionEntrypointExists && $adminerInstalled;
?>
<header class="card">
    <div class="card-body">
        <h1>
            <?= e($extensionName !== '' ? $extensionName : 'Database Manager') ?>
            <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
        </h1>
        <p class="mb-0">Select one <?= e($modeLabel) ?> target to open in Adminer. The list is sorted alphabetically.</p>
    </div>
</header>

<?php if (!$canManageConfiguration): ?>
<div class="alert alert-danger" role="alert">
    Manage System Configuration permission is required for this section.
</div>
<?php endif; ?>

<?php if ($canManageConfiguration): ?>
    <?php if (!$extensionEntrypointExists): ?>
    <div class="alert alert-danger mb-3" role="alert">
        Extension entrypoint is missing at <code>~/private/ext/database/adminer.php</code>.
    </div>
    <?php elseif (!$adminerInstalled): ?>
    <div class="alert alert-warning mb-3" role="alert">
        Adminer dependency is not installed locally yet.
        Run <code>composer update</code> (or <code>composer require vrana/adminer:^5.3</code>) when network access is available.
    </div>
    <?php elseif (is_string($selectorError) && trim($selectorError) !== ''): ?>
    <div class="alert alert-warning mb-3" role="alert">
        <?= e($selectorError) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 mb-3">Launch Targets (<?= e($modeLabel) ?>)</h2>

            <?php if ($targets === []): ?>
                <p class="text-muted mb-0">No launch targets were found for this driver.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Target</th>
                                <th scope="col">Details</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($targets as $target): ?>
                                <tr>
                                    <td><code><?= e((string) ($target['name'] ?? '')) ?></code></td>
                                    <td>
                                        <?php $detail = trim((string) ($target['detail'] ?? '')); ?>
                                        <?php if ($detail === ''): ?>
                                            <span class="text-muted">&ndash;</span>
                                        <?php else: ?>
                                            <?= e($detail) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($canLaunchAdminer): ?>
                                            <a
                                                class="btn btn-success btn-sm"
                                                href="<?= e((string) ($target['launch_path'] ?? '')) ?>"
                                                target="_blank"
                                                rel="noreferrer noopener"
                                            >
                                                Open in Adminer
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary btn-sm" disabled>Unavailable</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mt-3 gap-2">
                <a class="btn btn-secondary" href="<?= e($databasePath) ?>">
                    <i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Database Manager
                </a>
                <a class="btn btn-outline-secondary" href="<?= e($extensionsPath) ?>">
                    <i class="bi bi-grid me-2" aria-hidden="true"></i>Back to Extensions
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>
