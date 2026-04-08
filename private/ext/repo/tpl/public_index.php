<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/public_index.php
 * Repositories public index view.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use function Raven\Support\e;

$formatTimestamp = static function (?string $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Never synced';
    }

    $time = strtotime($value);
    return $time === false ? e($value) : gmdate('Y-m-d H:i', $time) . ' UTC';
};
?>
<style>
.repo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}.repo-card{border:1px solid #d6d6d6;border-radius:.75rem;padding:1rem;background:#fff}.repo-meta{font-size:.92rem;color:#555}.repo-badge{display:inline-block;padding:.15rem .5rem;border-radius:999px;background:#ececec;font-size:.8rem;margin-right:.4rem}.repo-empty{padding:1.5rem;border:1px dashed #bbb;border-radius:.75rem;background:#fafafa}
</style>
<section>
    <h1>Repositories</h1>
    <p>Public repository mirrors published through Raven. These mirrors are read-only and update on the host system's sync schedule.</p>
</section>

<?php if ($repos === []): ?>
    <div class="repo-empty">No repositories are publicly listed right now.</div>
<?php else: ?>
    <div class="repo-grid">
        <?php foreach ($repos as $repo): ?>
            <article class="repo-card">
                <div class="repo-badge"><?= e((string) ($repo['visibility_label'] ?? 'Public')) ?></div>
                <h2 style="margin:.5rem 0 .35rem 0;"><a href="<?= e($indexUrl . '/' . rawurlencode((string) ($repo['slug'] ?? ''))) ?>"><?= e((string) ($repo['label'] ?? $repo['slug'] ?? 'Repository')) ?></a></h2>
                <div class="repo-meta"><code><?= e((string) ($repo['slug'] ?? '')) ?></code></div>
                <?php if (!empty($repo['description'])): ?>
                    <p><?= e((string) $repo['description']) ?></p>
                <?php endif; ?>
                <div class="repo-meta">Last updated: <?= e($formatTimestamp(is_string($repo['last_successful_sync_at'] ?? null) ? $repo['last_successful_sync_at'] : null)) ?></div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
