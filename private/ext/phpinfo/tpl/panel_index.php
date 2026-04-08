<?php

/**
 * RAVEN CMS
 * ~/private/ext/phpinfo/tpl/panel_index.php
 * PHP Info extension panel index view.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */
/** @var string $csrfField */
/** @var string $phpInfoHtml */
/** @var string $phpInfoCss */

use function Raven\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'PHP Info'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>
                <?= e($extensionName !== '' ? $extensionName : 'PHP Info') ?>
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
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Runtime diagnostics from phpinfo().') ?></p>
    </div>
</header>

<section class="card">
    <div class="card-body raven-phpinfo-output">
        <style>
            <?= $phpInfoCss ?>

            .raven-phpinfo-output {
                overflow-x: auto;
            }

            .raven-phpinfo-output table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: auto !important;
                box-shadow: none;
            }
            .raven-phpinfo-output tr {
                border-bottom: 1px solid #777;
            }
            .raven-phpinfo-output tr.h {
                border-bottom: 0;
            }
            .raven-phpinfo-output th {
                text-align: left !important;
                border-top: 0;
                border-left: 0;
                border-right: 0;
                border-bottom: 1px solid #777;
                background-color: #D3DBFB;
            }
            .raven-phpinfo-output td,
            .raven-phpinfo-output .h td {
                border: 0;
            }
            .raven-phpinfo-output td.e {
                background-color: #F4F4F4;
            }
            body#rvnp main .raven-phpinfo-output h2 {
                border-bottom: 0;
            }

            .raven-phpinfo-output hr {
                width: 100% !important;
                max-width: 100% !important;
            }
        </style>
        <?= $phpInfoHtml ?>
    </div>
</section>
