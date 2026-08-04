<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/docs.php
 * User Manual document view rendered inside the standard panel wrapper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $contentHtml */
/** @var string $documentPath */
/** @var string $documentTitle */

use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$documentPath = trim((string) ($documentPath ?? ''), '/');
$documentTitle = trim((string) ($documentTitle ?? 'User Manual'));
$indexUrl = $panelBase . '/docs/home';
$headerActions = [];
if ($documentPath !== 'readme.md') {
    $headerActions[] = '<a class="btn btn-primary btn-sm" href="' . e($indexUrl) . '">'
        . '<i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Documentation Index</a>';
}
?>

<?= Header::render([
    'title' => $documentTitle,
    'summary' => 'Raven CMS User Manual',
    'actions' => $headerActions,
]) ?>

<section class="card rvnp-docs-card">
    <div class="card-body rvnp-docs-content">
        <?= $contentHtml ?>
    </div>
</section>
