<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/redirect/edit.php
 * Admin panel view template for Redirect create/edit screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $redirectRow */
/** @var array<int, array<string, mixed>> $channelOptions */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$redirectTitle = trim((string) ($redirectRow['title'] ?? ''));
$redirectId = (int) ($redirectRow['id'] ?? 0);
$hasPersistedRedirect = $redirectId > 0;
$deleteFormId = 'delete-redirect-form';
$selectedChannelSlug = trim((string) ($redirectRow['channel_slug'] ?? ''));
$redirectSlug = trim((string) ($redirectRow['slug'] ?? ''));
$isActive = (int) ($redirectRow['active'] ?? 1) === 1;
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$redirectPublicUrl = null;
if ($redirectRow !== null && $publicBase !== '' && $redirectSlug !== '') {
    $redirectPathParts = [];
    if ($selectedChannelSlug !== '') {
        $redirectPathParts[] = rawurlencode($selectedChannelSlug);
    }
    $redirectPathParts[] = rawurlencode($redirectSlug);
    $redirectPublicUrl = $publicBase . '/' . implode('/', $redirectPathParts);
}
$redirectHeaderBodyHtml = '';
if ($redirectRow !== null && $redirectPublicUrl !== null) {
    $redirectPublicUrlEscaped = e($redirectPublicUrl);
    $redirectHeaderBodyHtml = <<<HTML
            <p class="mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="{$redirectPublicUrlEscaped}"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="{$redirectPublicUrlEscaped}"
                    aria-label="Open redirect URL"
                    style="font-size: 0.88em;"
                >
                    {$redirectPublicUrlEscaped}
                </a>
            </p>
HTML;
}
$redirectEditorToolbarItems = [
    '<button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Redirect</button>',
    '<a href="' . e($panelBase) . '/redirect" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Redirects</a>',
];
if ($hasPersistedRedirect) {
    $redirectEditorToolbarItems[] = '<button type="submit" class="btn btn-danger" form="' . e($deleteFormId) . '" onclick="return confirm(\'Delete this redirect?\');"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Redirect</button>';
}
?>
<?= Header::render([
    'title_html' => $redirectRow === null
        ? 'New Redirect'
        : 'Edit Redirect: <span class="text-primary">\'' . e($redirectTitle !== '' ? $redirectTitle : 'Untitled') . '\'</span>',
    'summary' => $redirectRow === null ? 'Create or update redirect routes and destination targets.' : '',
    'body_html' => $redirectHeaderBodyHtml,
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedRedirect): ?>
<!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/redirect/delete">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $redirectId ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/redirect/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $redirectId ?>">
    <?= Toolbar::render([
        'items' => $redirectEditorToolbarItems,
    ]) ?>

    <section class="card">
        <div class="card-body">

            <div class="form-group">
                <label for="title" class="form-label">Title</label>
                <!-- Human-facing title used for admin list readability. -->
                <input id="title" name="title" class="form-control" required value="<?= e((string) ($redirectRow['title'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="slug" class="form-label">Slug</label>
                <!-- Slug composes redirect source path: /{slug} or /{channel_slug}/{slug}. -->
                <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($redirectRow['slug'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <!-- Optional admin-facing note describing intent/purpose of this redirect. -->
                <textarea id="description" name="description" class="form-control" rows="3"><?= e((string) ($redirectRow['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-group">
                <label for="channel_slug" class="form-label">Channel</label>
                <!-- Optional channel scope for redirects under /{channel}/{slug}. -->
                <select id="channel_slug" name="channel_slug" class="form-select">
                    <option value="">&lt;none&gt;</option>
                    <?php foreach ($channelOptions as $channelOption): ?>
                        <?php $optionSlug = (string) ($channelOption['slug'] ?? ''); ?>
                        <?php if ($optionSlug === ''): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <option
                            value="<?= e($optionSlug) ?>"
                            <?= $selectedChannelSlug === $optionSlug ? 'selected' : '' ?>
                        >
                            <?= e($optionSlug) ?> (<?= e((string) ($channelOption['name'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status" class="form-label">Status</label>
                <!-- Active redirects resolve on public routes; Inactive entries are ignored. -->
                <select id="status" name="status" class="form-select">
                    <option value="active"<?= $isActive ? ' selected' : '' ?>>Active</option>
                    <option value="inactive"<?= !$isActive ? ' selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="form-group mb-0">
                <label for="target" class="form-label">Target URL</label>
                <!-- Supports external absolute URLs and root-relative internal destinations. -->
                <input
                    id="target"
                    name="target"
                    class="form-control"
                    required
                    value="<?= e((string) ($redirectRow['target'] ?? '')) ?>"
                    placeholder="https://example.com/path or /local-path"
                >
                <div class="form-text">
                    Allowed values: absolute <code>http(s)</code> URLs or root-relative paths starting with <code>/</code>.
                </div>
            </div>

        </div>
    </section>

    <?= Toolbar::render([
        'items' => $redirectEditorToolbarItems,
    ]) ?>
</form>
