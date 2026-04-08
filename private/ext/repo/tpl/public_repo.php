<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/public_repo.php
 * Repositories public repo browser view.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use function Raven\Support\e;

$repoView = is_array($payload['repo'] ?? null) ? $payload['repo'] : $repo;
$mode = (string) ($payload['mode'] ?? 'metadata');
$currentRef = (string) ($payload['ref'] ?? '');
$currentPath = (string) ($payload['path'] ?? '');
$entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
$breadcrumbs = is_array($payload['breadcrumbs'] ?? null) ? $payload['breadcrumbs'] : [];
$readme = is_array($payload['readme'] ?? null) ? $payload['readme'] : null;
$file = is_array($payload['file'] ?? null) ? $payload['file'] : null;
$licensePath = (string) ($payload['license_path'] ?? '');

$buildRepoUrl = static function (array $query = []) use ($repoPathBase): string {
    return $query === [] ? $repoPathBase : ($repoPathBase . '?' . http_build_query($query));
};
$buildFileUrl = static function (string $suffix, array $query = []) use ($repoPathBase): string {
    return $query === [] ? ($repoPathBase . '/' . $suffix) : ($repoPathBase . '/' . $suffix . '?' . http_build_query($query));
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
$copyId = 'repo-copy-' . substr(md5((string) ($repoView['slug'] ?? 'repo') . $currentRef . $currentPath), 0, 8);
?>
<style>
.repo-shell{display:grid;gap:1rem}.repo-card{border:1px solid #d6d6d6;border-radius:.85rem;padding:1rem;background:#fff}.repo-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem}.repo-chip{display:inline-block;padding:.15rem .55rem;border-radius:999px;background:#ececec;font-size:.82rem;margin-right:.4rem}.repo-actions{display:flex;flex-wrap:wrap;gap:.6rem}.repo-actions a,.repo-actions button{display:inline-block;padding:.55rem .8rem;border:1px solid #bbb;border-radius:.55rem;background:#f7f7f7;color:inherit;text-decoration:none}.repo-actions button{cursor:pointer}.repo-tree{width:100%;border-collapse:collapse}.repo-tree th,.repo-tree td{padding:.55rem .45rem;border-top:1px solid #e4e4e4;text-align:left}.repo-tree tr:first-child td{border-top:none}.repo-breadcrumbs a{text-decoration:none}.repo-pre{white-space:pre-wrap;word-break:break-word;padding:1rem;border-radius:.75rem;background:#111;color:#f1f1f1;overflow:auto}.repo-note{padding:.85rem 1rem;border-radius:.75rem;background:#faf3d9;border:1px solid #ead28a}.repo-muted{color:#666}.repo-meta{font-size:.94rem;color:#555}.repo-form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}.repo-form label{display:block;font-size:.92rem;margin-bottom:.3rem}.repo-copy{margin-top:.6rem}
</style>
<section class="repo-shell">
    <article class="repo-card">
        <div class="repo-head">
            <div>
                <div><a href="<?= e($indexUrl) ?>">Repositories</a> / <strong><?= e((string) ($repoView['label'] ?? $repoView['slug'] ?? 'Repository')) ?></strong></div>
                <h1 style="margin:.4rem 0 .35rem 0;"><?= e((string) ($repoView['label'] ?? $repoView['slug'] ?? 'Repository')) ?></h1>
                <div class="repo-meta"><code><?= e((string) ($repoView['slug'] ?? '')) ?></code></div>
                <p class="repo-muted"><?= e((string) ($repoView['description'] ?? 'Read-only Git repository mirror.')) ?></p>
                <div>
                    <span class="repo-chip"><?= e((string) ($repoView['visibility_label'] ?? 'Public')) ?></span>
                    <?php if ($currentRef !== ''): ?><span class="repo-chip">Branch: <?= e($currentRef) ?></span><?php endif; ?>
                    <?php if ($currentPath !== ''): ?><span class="repo-chip">Path: <?= e($currentPath) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="repo-actions">
                <?php if (!empty($repoView['public_download_enabled'])): ?>
                    <a href="<?= e($buildFileUrl('archive', ['ref' => $currentRef !== '' ? $currentRef : null, 'format' => 'zip'])) ?>">Download ZIP</a>
                    <a href="<?= e($buildFileUrl('archive', ['ref' => $currentRef !== '' ? $currentRef : null, 'format' => 'tar'])) ?>">Download TAR</a>
                <?php endif; ?>
                <?php if ($licensePath !== '' && !empty($repoView['public_download_enabled'])): ?>
                    <a href="<?= e($buildRepoUrl(['ref' => $currentRef, 'path' => $licensePath])) ?>">License</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($cloneUrl !== ''): ?>
            <div class="repo-copy">
                <div class="repo-meta">Clone command</div>
                <pre class="repo-pre" id="<?= e($copyId) ?>" style="margin-top:.35rem;">git clone <?= e($cloneUrl) ?></pre>
                <button type="button" data-copy-target="<?= e($copyId) ?>">Copy clone command</button>
            </div>
        <?php endif; ?>
    </article>

    <?php if (!empty($payload['notice'])): ?>
        <div class="repo-note"><?= e((string) $payload['notice']) ?></div>
    <?php endif; ?>

    <article class="repo-card">
        <form class="repo-form" method="get" action="<?= e($repoPathBase) ?>">
            <div>
                <label for="repo_ref">Branch</label>
                <input type="text" id="repo_ref" name="ref" value="<?= e($currentRef) ?>">
            </div>
            <div>
                <label for="repo_path">Path</label>
                <input type="text" id="repo_path" name="path" value="<?= e($currentPath) ?>">
            </div>
            <div>
                <label for="repo_readme">README</label>
                <select id="repo_readme" name="readme">
                    <option value="on"<?= $readmeEnabled ? ' selected' : '' ?>>Auto-include</option>
                    <option value="off"<?= !$readmeEnabled ? ' selected' : '' ?>>Hidden</option>
                </select>
            </div>
            <div>
                <button type="submit">Browse</button>
            </div>
        </form>
        <?php if ($breadcrumbs !== []): ?>
            <p class="repo-breadcrumbs repo-meta" style="margin-top:1rem;">
                <a href="<?= e($buildRepoUrl(['ref' => $currentRef])) ?>">root</a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    / <a href="<?= e($buildRepoUrl(['ref' => $currentRef, 'path' => (string) ($crumb['path'] ?? ''), 'readme' => $readmeEnabled ? 'on' : 'off'])) ?>"><?= e((string) ($crumb['label'] ?? '')) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </article>

    <?php if ($mode === 'metadata'): ?>
        <div class="repo-note">This repository publishes metadata publicly, but its Git objects are kept private.</div>
    <?php elseif ($mode === 'downloads'): ?>
        <div class="repo-note">This repository exposes downloads and clone operations publicly, but the web file browser is intentionally disabled.</div>
    <?php elseif ($mode === 'tree'): ?>
        <article class="repo-card">
            <h2 style="margin-top:0;">File Tree</h2>
            <?php if ($entries === []): ?>
                <p class="repo-muted">This directory is empty.</p>
            <?php else: ?>
                <table class="repo-tree">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Size</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($currentPath !== ''): ?>
                        <?php $parentPath = str_contains($currentPath, '/') ? dirname($currentPath) : ''; ?>
                        <?php if ($parentPath === '.') { $parentPath = ''; } ?>
                        <tr>
                            <td><a href="<?= e($buildRepoUrl(['ref' => $currentRef, 'path' => $parentPath, 'readme' => $readmeEnabled ? 'on' : 'off'])) ?>">..</a></td>
                            <td>dir</td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php $entryPath = (string) ($entry['path'] ?? ''); ?>
                        <tr>
                            <td><a href="<?= e($buildRepoUrl(['ref' => $currentRef, 'path' => $entryPath, 'readme' => $readmeEnabled ? 'on' : 'off'])) ?>"><?= e((string) ($entry['name'] ?? '')) ?></a></td>
                            <td><?= !empty($entry['is_dir']) ? 'dir' : 'file' ?></td>
                            <td><?= e($formatSize(is_int($entry['size'] ?? null) ? $entry['size'] : null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </article>

        <?php if ($readme !== null): ?>
            <article class="repo-card">
                <h2 style="margin-top:0;">README</h2>
                <pre class="repo-pre"><?= e((string) ($readme['content'] ?? '')) ?></pre>
            </article>
        <?php endif; ?>
    <?php elseif ($mode === 'file' && $file !== null): ?>
        <article class="repo-card">
            <div class="repo-head">
                <div>
                    <h2 style="margin-top:0; margin-bottom:.3rem;">File Viewer</h2>
                    <div class="repo-meta"><?= e((string) ($file['path'] ?? '')) ?> · <?= e($formatSize(is_int($file['size'] ?? null) ? $file['size'] : null)) ?></div>
                </div>
                <div class="repo-actions">
                    <a href="<?= e($buildFileUrl('raw', ['ref' => $currentRef, 'path' => (string) ($file['path'] ?? '')])) ?>">Raw</a>
                    <a href="<?= e($buildFileUrl('download', ['ref' => $currentRef, 'path' => (string) ($file['path'] ?? '')])) ?>">Download</a>
                </div>
            </div>
            <?php if (!empty($file['previewable'])): ?>
                <?php $fileCopyId = $copyId . '-file'; ?>
                <pre class="repo-pre" id="<?= e($fileCopyId) ?>"><?= e((string) ($file['content'] ?? '')) ?></pre>
                <button type="button" data-copy-target="<?= e($fileCopyId) ?>">Copy file contents</button>
            <?php elseif (!empty($file['is_binary'])): ?>
                <div class="repo-note">This file looks binary, so the web preview is disabled. Use the raw or download links instead.</div>
            <?php else: ?>
                <div class="repo-note">This file is larger than the inline preview budget. Use the raw or download links instead.</div>
            <?php endif; ?>
        </article>
    <?php endif; ?>
</section>
<script>
(function(){
  var buttons = document.querySelectorAll('[data-copy-target]');
  buttons.forEach(function(button){
    button.addEventListener('click', function(){
      var id = button.getAttribute('data-copy-target');
      var target = id ? document.getElementById(id) : null;
      if (!target) {
        return;
      }
      var text = String(target.textContent || '');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      }
    });
  });
})();
</script>
