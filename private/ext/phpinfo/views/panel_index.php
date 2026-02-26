<?php

/**
 * RAVEN CMS
 * ~/private/ext/phpinfo/views/panel_index.php
 * PHP Info extension panel index view.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs_url?: string} $extensionMeta */
/** @var string $csrfField */
/** @var string $phpInfoHtml */
/** @var string $phpInfoCss */

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'PHP Info'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h1 class="mb-1">
                    <?= e($extensionName !== '' ? $extensionName : 'PHP Info') ?>
                    <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
                </h1>
                <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
                <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Runtime diagnostics from phpinfo().') ?></p>
            </div>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="card">
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
            }

            .raven-phpinfo-output hr {
                width: 100% !important;
                max-width: 100% !important;
            }
        </style>
        <?= $phpInfoHtml ?>
    </div>
</div>
