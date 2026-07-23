<?php

/**
 * RAVEN CMS
 * ~/private/ext/phpinfo/tpl/panel_index.php
 * PHP Info extension panel index view.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */
/** @var string $csrfField */
/** @var string $phpInfoHtml */
/** @var string $phpInfoCss */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'PHP Info'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://lanterns.io/raven'));
$phpInfoHeaderActions = [];
if ($extensionDocsUrl !== '') {
    $phpInfoHeaderActions[] = '<a href="' . e($extensionDocsUrl) . '" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">'
        . '<i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation'
        . '</a>';
}
?>
<?= Header::render([
    'title_html' => e($extensionName !== '' ? $extensionName : 'PHP Info')
        . ($extensionVersion !== '' ? ' <small class="ms-2 text-muted" style="font-size: 0.48em;">v. ' . e($extensionVersion) . '</small>' : ''),
    'subheading_html' => 'by ' . e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown'),
    'summary' => $extensionDescription !== '' ? $extensionDescription : 'Runtime diagnostics from phpinfo().',
    'actions' => $phpInfoHeaderActions,
]) ?>

<section class="card">
    <div class="card-body raven-phpinfo-output">
        <?php ob_start(); ?>
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
        <?php Footer::pushStyle((string) ob_get_clean()); ?>
        <?= $phpInfoHtml ?>
    </div>
</section>
