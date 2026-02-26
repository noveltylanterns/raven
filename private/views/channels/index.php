<?php

/**
 * RAVEN CMS
 * ~/private/views/channels/index.php
 * Public-facing channel landing template for site output.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Fallback channel template mirrors default public-theme behavior.

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var array<string, mixed> $page */
/** @var bool $galleryEnabled */
/** @var array<int, array<string, mixed>> $galleryImages */

use function Raven\Core\Support\e;
?>
<article>
    <header class="mb-3">
        <h2 class="h4 mb-1"><?= e((string) $page['title']) ?></h2>
        <?php if (!empty($page['channel_slug'])): ?>
            <p class="text-muted small mb-0">Channel: <?= e((string) $page['channel_slug']) ?></p>
        <?php endif; ?>
    </header>

    <div><?= (string) $page['content'] ?></div>
    <?php
    $extendedBlocks = is_array($page['extended_blocks'] ?? null) ? $page['extended_blocks'] : [];
    if ($extendedBlocks === []) {
        $fallbackExtended = trim((string) ($page['extended'] ?? ''));
        if ($fallbackExtended !== '') {
            $extendedBlocks = [$fallbackExtended];
        }
    }
    ?>
    <?php if ($extendedBlocks !== []): ?>
        <?php foreach ($extendedBlocks as $blockIndex => $extendedBlock): ?>
            <?php $extendedHtml = trim((string) ($extendedBlock ?? '')); ?>
            <?php if ($extendedHtml === ''): ?>
                <?php continue; ?>
            <?php endif; ?>
            <div class="mt-3 raven-page-extended-block raven-page-extended-block-<?= (int) $blockIndex + 1 ?>"><?= $extendedHtml ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($galleryEnabled && $galleryImages !== []): ?>
        <section class="mt-4">
            <h3 class="h5 mb-3">Gallery</h3>
            <div class="row g-3">
                <?php foreach ($galleryImages as $image): ?>
                    <?php
                    $variants = is_array($image['variants'] ?? null) ? $image['variants'] : [];
                    $imageUrl = (string) (($variants['md']['url'] ?? '') ?: ($image['url'] ?? ''));
                    $fullUrl = (string) (($variants['lg']['url'] ?? '') ?: $imageUrl);
                    $altText = (string) ($image['alt_text'] ?? '');
                    $caption = (string) ($image['caption'] ?? '');
                    ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <figure class="mb-0">
                            <a href="<?= e($fullUrl) ?>">
                                <img src="<?= e($imageUrl) ?>" class="img-fluid rounded border" alt="<?= e($altText) ?>">
                            </a>
                            <?php if ($caption !== ''): ?>
                                <figcaption class="small text-muted mt-2"><?= e($caption) ?></figcaption>
                            <?php endif; ?>
                        </figure>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</article>
