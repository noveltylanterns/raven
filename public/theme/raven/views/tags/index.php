<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/tags/index.php
 * Public-facing view template for site output.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Render public output with escaped values and minimal view-side branching.

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var array<string, mixed> $tag */
/** @var array<int, array<string, mixed>> $pages */
/** @var array<string, mixed> $pagination */

use function Raven\Core\Support\e;
?>
<section>
    <h2 class="h4">Tag: <?= e((string) $tag['name']) ?></h2>

    <?php if ($pages === []): ?>
        <p>No pages found for this tag yet.</p>
    <?php else: ?>
        <ul class="list-group mb-3">
            <?php foreach ($pages as $item): ?>
                <?php
                $pageSlug = trim((string) ($item['slug'] ?? ''));
                $channelSlug = trim((string) ($item['channel_slug'] ?? ''));
                $pageUrl = '/' . ($channelSlug !== '' ? rawurlencode($channelSlug) . '/' : '') . rawurlencode($pageSlug);
                ?>
                <li class="list-group-item">
                    <a href="<?= e($pageUrl) ?>"><?= e((string) $item['title']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ((int) $pagination['total_pages'] > 1): ?>
        <nav aria-label="Tag pagination">
            <ul class="pagination">
                <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                    <?php
                    // Page 1 omits the extra segment by specification.
                    $href = (string) $pagination['base_path'] . ($i === 1 ? '' : '/' . $i);
                    ?>
                    <li class="page-item<?= $i === (int) $pagination['current'] ? ' active' : '' ?>">
                        <a class="page-link" href="<?= e($href) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</section>
