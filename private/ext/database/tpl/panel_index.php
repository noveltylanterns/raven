<?php

/**
 * RAVEN CMS
 * ~/private/ext/database/tpl/panel_index.php
 * Database Manager extension page template for launch and diagnostics.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This extension template stays read-only and only links into Adminer runtime.

declare(strict_types=1);

/** @var bool $canManageConfiguration */
/** @var bool $adminerInstalled */
/** @var bool $extensionEntrypointExists */
/** @var string $adminerPath */
/** @var string $extensionsPath */
/** @var string|null $selectorError */
/** @var array{
 *   driver: string,
 *   prefix: string,
 *   sqlite_path: string,
 *   sqlite_file: string,
 *   mysql: array<string, string>,
 *   pgsql: array<string, string>
 * } $databaseSummary */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */

use function Raven\Support\e;

$driver = strtolower((string) ($databaseSummary['driver'] ?? 'sqlite'));
$extensionName = trim((string) ($extensionMeta['name'] ?? 'Database Manager'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
$canLaunchAdminer = $extensionEntrypointExists && $adminerInstalled;
$adminerPath = trim((string) ($adminerPath ?? ''));
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>
                <?= e($extensionName !== '' ? $extensionName : 'Database Manager') ?>
                <?php if ($extensionVersion !== ''): ?>
                    <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion) ?></small>
                <?php endif; ?>
            </h1>
            <?php if ($extensionDocsUrl !== ''): ?>
            <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
            </a>
            <?php endif; ?>
        </div>

        <h5>by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h5>
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'This page is provided by the Database Manager extension and uses Adminer as a single-page database editor.') ?></p>
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
    <?php endif; ?>

    <?php if (is_string($selectorError) && trim($selectorError) !== ''): ?>
    <div class="alert alert-warning mb-3" role="alert">
        <?= e($selectorError) ?>
    </div>
    <?php endif; ?>

    <?php if ($canLaunchAdminer && $adminerPath !== ''): ?>
    <nav>
        <a class="btn btn-success" href="<?= e($adminerPath) ?>">Open Adminer<i class="bi bi-chevron-right ms-2" aria-hidden="true"></i></a>
    </nav>
    <?php endif; ?>

    <section class="card">
        <div class="card-body">
            <h2>Connection Summary</h2>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <tr>
                        <th scope="row" class="h5" style="width: 220px;">Active Driver</th>
                        <td><code><?= e($driver) ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row" class="h5">Table Prefix</th>
                        <td><code><?= e((string) ($databaseSummary['prefix'] ?? '')) ?></code></td>
                    </tr>

                    <?php if ($driver === 'sqlite'): ?>
                        <tr>
                            <th scope="row" class="h5">SQLite Path</th>
                            <td><code><?= e((string) ($databaseSummary['sqlite_path'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">SQLite Database</th>
                            <td>
                                <?php $sqliteFile = trim((string) ($databaseSummary['sqlite_file'] ?? '')); ?>
                                <?php if ($sqliteFile === ''): ?>
                                    <span class="text-muted">&lt;none&gt;</span>
                                <?php else: ?>
                                    <div><code><?= e($sqliteFile) ?></code></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php elseif ($driver === 'mysql'): ?>
                        <?php $mysql = (array) ($databaseSummary['mysql'] ?? []); ?>
                        <tr>
                            <th scope="row" class="h5">MySQL Host</th>
                            <td><code><?= e((string) ($mysql['host'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">MySQL Port</th>
                            <td><code><?= e((string) ($mysql['port'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">MySQL Database</th>
                            <td><code><?= e((string) ($mysql['name'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">MySQL User</th>
                            <td><code><?= e((string) ($mysql['user'] ?? '')) ?></code></td>
                        </tr>
                    <?php elseif ($driver === 'pgsql'): ?>
                        <?php $pgsql = (array) ($databaseSummary['pgsql'] ?? []); ?>
                        <tr>
                            <th scope="row" class="h5">PostgreSQL Host</th>
                            <td><code><?= e((string) ($pgsql['host'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">PostgreSQL Port</th>
                            <td><code><?= e((string) ($pgsql['port'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">PostgreSQL Database</th>
                            <td><code><?= e((string) ($pgsql['name'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="h5">PostgreSQL User</th>
                            <td><code><?= e((string) ($pgsql['user'] ?? '')) ?></code></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($canLaunchAdminer && $adminerPath !== ''): ?>
    <nav>
        <a class="btn btn-success" href="<?= e($adminerPath) ?>">Open Adminer<i class="bi bi-chevron-right ms-2" aria-hidden="true"></i></a>
    </nav>
    <?php endif; ?>

<?php endif; ?>
