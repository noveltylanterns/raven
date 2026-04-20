<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/public_embed.php
 * Repositories shortcode embed view.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

use function Raven\Lib\Security\e;

$repo = is_array($repo ?? null) ? $repo : [];
$payload = is_array($payload ?? null) ? $payload : [];
$embedMode = (string) ($embedMode ?? 'notice');
$notice = trim((string) ($notice ?? ''));
$canonicalBaseUrl = rtrim((string) ($canonicalBaseUrl ?? ''), '/');
$readmeEnabled = !empty($readmeEnabled);
$currentRef = trim((string) ($payload['ref'] ?? ''));
$currentPath = trim((string) ($payload['path'] ?? ''));
$entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
$readme = is_array($payload['readme'] ?? null) ? $payload['readme'] : null;
$file = is_array($payload['file'] ?? null) ? $payload['file'] : null;

$buildRepoUrl = static function (array $query = []) use ($canonicalBaseUrl): string {
    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    return $query === [] ? $canonicalBaseUrl : ($canonicalBaseUrl . '?' . http_build_query($query));
};
$buildFileUrl = static function (string $suffix, array $query = []) use ($canonicalBaseUrl): string {
    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    return $query === [] ? ($canonicalBaseUrl . '/' . $suffix) : ($canonicalBaseUrl . '/' . $suffix . '?' . http_build_query($query));
};
$formatSize = static function (?int $bytes): string {
    if ($bytes === null || $bytes < 0) {
        return 'dir';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index += 1;
    }

    return rtrim(rtrim(number_format($value, $index === 0 ? 0 : 1), '0'), '.') . ' ' . $units[$index];
};

if (!defined('RVN_REPO_EMBED_STYLE_PRINTED')) {
    define('RVN_REPO_EMBED_STYLE_PRINTED', true);
    ?>
    <style>
    .rvn-repo-embed{margin:1.25rem 0;border:1px solid #d9d9d9;border-radius:.9rem;background:#fff;overflow:hidden}
    .rvn-repo-embed__head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;padding:1rem 1rem .75rem 1rem;border-bottom:1px solid #ececec}
    .rvn-repo-embed__eyebrow{font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;color:#667085}
    .rvn-repo-embed__title{margin:.2rem 0 .35rem 0;font-size:1.05rem}
    .rvn-repo-embed__meta{font-size:.9rem;color:#667085}
    .rvn-repo-embed__chips{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.55rem}
    .rvn-repo-embed__chip{display:inline-block;padding:.18rem .55rem;border-radius:999px;background:#eef2f6;font-size:.78rem;color:#344054}
    .rvn-repo-embed__actions{display:flex;flex-wrap:wrap;gap:.55rem}
    .rvn-repo-embed__actions a{display:inline-block;padding:.48rem .7rem;border:1px solid #cbd5e1;border-radius:.6rem;background:#f8fafc;color:inherit;text-decoration:none;font-size:.88rem}
    .rvn-repo-embed__body{padding:1rem}
    .rvn-repo-embed__note{padding:.8rem .9rem;border:1px solid #f1d38a;border-radius:.7rem;background:#fff8e1;color:#6b4f00}
    .rvn-repo-embed__tree{margin:0;padding-left:1.1rem}
    .rvn-repo-embed__tree li{margin:.4rem 0}
    .rvn-repo-embed__pre{margin:.85rem 0 0 0;padding:.95rem;border-radius:.75rem;background:#101828;color:#f8fafc;white-space:pre-wrap;word-break:break-word;overflow:auto}
    .rvn-repo-embed__footer{padding:0 1rem 1rem 1rem}
    .rvn-repo-embed__footer a{font-size:.92rem}
    .rvn-repo-embed__empty{color:#667085}
    </style>
    <?php
}
?>
<article class="rvn-repo-embed">
    <div class="rvn-repo-embed__head">
        <div>
            <div class="rvn-repo-embed__eyebrow">Repository Embed</div>
            <h3 class="rvn-repo-embed__title"><a href="<?= e($buildRepoUrl()) ?>"><?= e((string) ($repo['label'] ?? $repo['slug'] ?? 'Repository')) ?></a></h3>
            <div class="rvn-repo-embed__meta"><code><?= e((string) ($repo['slug'] ?? '')) ?></code></div>
            <div class="rvn-repo-embed__chips">
                <span class="rvn-repo-embed__chip"><?= e((string) ($repo['visibility_label'] ?? 'Public')) ?></span>
                <?php if ($currentRef !== ''): ?><span class="rvn-repo-embed__chip">Branch: <?= e($currentRef) ?></span><?php endif; ?>
                <?php if ($currentPath !== ''): ?><span class="rvn-repo-embed__chip">Path: <?= e($currentPath) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="rvn-repo-embed__actions">
            <a href="<?= e($buildRepoUrl()) ?>">Open Repository</a>
            <?php if ($embedMode === 'file' && $file !== null): ?>
                <a href="<?= e($buildFileUrl('raw', ['ref' => $currentRef, 'path' => (string) ($file['path'] ?? '')])) ?>">Raw</a>
                <a href="<?= e($buildFileUrl('download', ['ref' => $currentRef, 'path' => (string) ($file['path'] ?? '')])) ?>">Download</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="rvn-repo-embed__body">
        <?php if ($notice !== ''): ?>
            <div class="rvn-repo-embed__note"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if ($embedMode === 'tree'): ?>
            <?php if ($entries === []): ?>
                <p class="rvn-repo-embed__empty">This directory is empty.</p>
            <?php else: ?>
                <ul class="rvn-repo-embed__tree">
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $entryPath = (string) ($entry['path'] ?? '');
                        $query = ['ref' => $currentRef, 'path' => $entryPath];
                        if (!$readmeEnabled) {
                            $query['readme'] = 'off';
                        }
                        ?>
                        <li>
                            <a href="<?= e($buildRepoUrl($query)) ?>"><?= e((string) ($entry['name'] ?? '')) ?></a>
                            <span class="rvn-repo-embed__meta"> · <?= !empty($entry['is_dir']) ? 'dir' : $formatSize(is_int($entry['size'] ?? null) ? $entry['size'] : null) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($readme !== null): ?>
                <pre class="rvn-repo-embed__pre"><?= e((string) ($readme['content'] ?? '')) ?></pre>
            <?php endif; ?>
        <?php elseif ($embedMode === 'file' && $file !== null): ?>
            <div class="rvn-repo-embed__meta"><?= e((string) ($file['path'] ?? '')) ?> · <?= e($formatSize(is_int($file['size'] ?? null) ? $file['size'] : null)) ?></div>
            <?php if (!empty($file['previewable'])): ?>
                <pre class="rvn-repo-embed__pre"><?= e((string) ($file['content'] ?? '')) ?></pre>
            <?php elseif (!empty($file['is_binary'])): ?>
                <div class="rvn-repo-embed__note">This file looks binary, so the inline embed preview is disabled. Use the Raw or Download links instead.</div>
            <?php else: ?>
                <div class="rvn-repo-embed__note">This file is larger than the inline preview budget. Use the Raw or Download links instead.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="rvn-repo-embed__footer">
        <?php
        $footerQuery = [];
        if ($embedMode === 'file' && $file !== null) {
            $footerQuery['ref'] = $currentRef;
            $footerQuery['path'] = (string) ($file['path'] ?? '');
        } else {
            if ($currentRef !== '') {
                $footerQuery['ref'] = $currentRef;
            }
            if ($currentPath !== '') {
                $footerQuery['path'] = $currentPath;
            }
            if (!$readmeEnabled) {
                $footerQuery['readme'] = 'off';
            }
        }
        ?>
        <a href="<?= e($buildRepoUrl($footerQuery)) ?>">Open the full repository browser</a>
    </div>
</article>