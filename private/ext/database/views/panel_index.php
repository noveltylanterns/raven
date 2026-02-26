<?php

/**
 * RAVEN CMS
 * ~/private/ext/database/views/panel_index.php
 * Database Manager extension page template for launch and diagnostics.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This extension template stays read-only and only links into Adminer runtime.

declare(strict_types=1);

/** @var bool $canManageConfiguration */
/** @var bool $adminerInstalled */
/** @var bool $extensionEntrypointExists */
/** @var string $adminerLaunchPath */
/** @var string $extensionsPath */
/** @var array{
 *   driver: string,
 *   table_prefix: string,
 *   sqlite_base_path: string,
 *   sqlite_files: array<string, string>,
 *   mysql: array<string, string>,
 *   pgsql: array<string, string>
 * } $databaseSummary */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs_url?: string} $extensionMeta */

use function Raven\Core\Support\e;

$driver = strtolower((string) ($databaseSummary['driver'] ?? 'sqlite'));
$extensionName = trim((string) ($extensionMeta['name'] ?? 'Database Manager'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h1 class="mb-1">
                    <?= e($extensionName !== '' ? $extensionName : 'Database Manager') ?>
                    <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
                </h1>
                <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
                <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'This page is provided by the Database Manager extension and uses Adminer as a single-page database editor.') ?></p>
            </div>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>

        <?php if (!$canManageConfiguration): ?>
            <div class="alert alert-danger" role="alert">
                Manage System Configuration permission is required for this section.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManageConfiguration): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Launch Adminer</h2>

            <?php if (!$extensionEntrypointExists): ?>
                <div class="alert alert-danger" role="alert">
                    Extension entrypoint is missing at <code>~/private/ext/database/adminer.php</code>.
                </div>
            <?php elseif (!$adminerInstalled): ?>
                <div class="alert alert-warning" role="alert">
                    Adminer dependency is not installed locally yet.
                    Run <code>composer update</code> (or <code>composer require vrana/adminer:^5.3</code>) when network access is available.
                </div>
            <?php else: ?>
                <p class="mb-3">
                    Open the Adminer launch selector:
                </p>
                <a
                    class="btn btn-success btn-sm"
                    href="<?= e($adminerLaunchPath) ?>"
                >
                    Open Adminer<i class="bi bi-chevron-right ms-2" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 mb-3">Connection Summary</h2>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <tr>
                        <th scope="row" style="width: 220px;">Active Driver</th>
                        <td><code><?= e($driver) ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">Table Prefix</th>
                        <td><code><?= e((string) ($databaseSummary['table_prefix'] ?? '')) ?></code></td>
                    </tr>

                    <?php if ($driver === 'sqlite'): ?>
                        <tr>
                            <th scope="row">SQLite Base Path</th>
                            <td><code><?= e((string) ($databaseSummary['sqlite_base_path'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">SQLite Files</th>
                            <td>
                                <?php $sqliteFiles = (array) ($databaseSummary['sqlite_files'] ?? []); ?>
                                <?php if ($sqliteFiles === []): ?>
                                    <span class="text-muted">&lt;none&gt;</span>
                                <?php else: ?>
                                    <?php foreach ($sqliteFiles as $key => $filename): ?>
                                        <div><code><?= e((string) $key) ?></code>: <code><?= e((string) $filename) ?></code></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php elseif ($driver === 'mysql'): ?>
                        <?php $mysql = (array) ($databaseSummary['mysql'] ?? []); ?>
                        <tr>
                            <th scope="row">MySQL Host</th>
                            <td><code><?= e((string) ($mysql['host'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">MySQL Port</th>
                            <td><code><?= e((string) ($mysql['port'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">MySQL Database</th>
                            <td><code><?= e((string) ($mysql['dbname'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">MySQL User</th>
                            <td><code><?= e((string) ($mysql['user'] ?? '')) ?></code></td>
                        </tr>
                    <?php elseif ($driver === 'pgsql'): ?>
                        <?php $pgsql = (array) ($databaseSummary['pgsql'] ?? []); ?>
                        <tr>
                            <th scope="row">PostgreSQL Host</th>
                            <td><code><?= e((string) ($pgsql['host'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">PostgreSQL Port</th>
                            <td><code><?= e((string) ($pgsql['port'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">PostgreSQL Database</th>
                            <td><code><?= e((string) ($pgsql['dbname'] ?? '')) ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">PostgreSQL User</th>
                            <td><code><?= e((string) ($pgsql['user'] ?? '')) ?></code></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <a class="btn btn-secondary" href="<?= e($extensionsPath) ?>"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Extensions</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-end">
        <a class="btn btn-secondary" href="<?= e($extensionsPath) ?>"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Extensions</a>
    </div>
<?php endif; ?>
