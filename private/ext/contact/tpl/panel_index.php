<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/tpl/panel_index.php
 * Contact Forms extension list page template with CRUD actions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array<int, array{
 *   name: string,
 *   slug: string,
 *   enabled: bool
 * }> $forms */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $editBasePath */
/** @var string $contactSubmissionsBasePath */
/** @var string $savePath */
/** @var string $deletePath */
/** @var string $csrfField */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */

use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Contact Forms'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
$contactHeaderActions = [];
if ($extensionDocsUrl !== '') {
    $contactHeaderActions[] = '<a href="' . e($extensionDocsUrl) . '" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">'
        . '<i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation'
        . '</a>';
}
?>
<?= Header::render([
    'title_html' => e($extensionName !== '' ? $extensionName : 'Contact Forms')
        . ($extensionVersion !== '' ? ' <small class="ms-2 text-muted" style="font-size: 0.48em;">v. ' . e($extensionVersion) . '</small>' : ''),
    'title_class' => 'mb-0',
    'subheading_html' => 'by ' . e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown'),
    'summary' => $extensionDescription !== '' ? $extensionDescription : 'Configured contact form definitions available to page content integrations.',
    'actions' => $contactHeaderActions,
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<nav>
    <a href="<?= e($editBasePath) ?>" class="btn btn-primary"><i class="bi bi-envelope-plus me-2" aria-hidden="true"></i>New Contact Form</a>
</nav>

<section class="card">
    <div class="card-body">
        <h2>Configured Forms</h2>

        <?php if ($forms === []): ?>
            <p class="text-muted mb-0">No contact forms are configured.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" data-rvn-sort-table="1" data-sort-default-key="title" data-sort-default-direction="asc">
                    <thead>
                    <tr>
                        <th scope="col" data-sort-key="title" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Title</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="slug" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Slug</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="status" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Status</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($forms as $form): ?>
                        <?php
                        $slug = (string) ($form['slug'] ?? '');
                        $formTitle = (string) ($form['name'] ?? '');
                        $statusLabel = (bool) ($form['enabled'] ?? false) ? 'Enabled' : 'Disabled';
                        ?>
                        <tr
                            data-rvn-sort-row="1"
                            data-sort-title="<?= e($formTitle) ?>"
                            data-sort-slug="<?= e($slug) ?>"
                            data-sort-status="<?= e($statusLabel) ?>"
                        >
                            <td>
                                <a href="<?= e($editBasePath) ?>/<?= rawurlencode($slug) ?>">
                                    <?= e($formTitle) ?>
                                </a>
                            </td>
                            <td><?= e($slug) ?></td>
                            <td><?= e($statusLabel) ?></td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-2">
                                    <a href="<?= e($contactSubmissionsBasePath) ?>/<?= rawurlencode($slug) ?>" class="btn btn-success btn-sm">View Submissions</a>
                                    <a
                                        href="<?= e($editBasePath) ?>/<?= rawurlencode($slug) ?>"
                                        class="btn btn-primary btn-sm"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <form method="post" action="<?= e($deletePath) ?>" class="m-0">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-danger btn-sm"
                                            title="Delete"
                                            aria-label="Delete"
                                            onclick="return confirm('Delete this contact form?');"
                                        >
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            <span class="visually-hidden">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<nav>
    <a href="<?= e($editBasePath) ?>" class="btn btn-primary"><i class="bi bi-envelope-plus me-2" aria-hidden="true"></i>New Contact Form</a>
</nav>
