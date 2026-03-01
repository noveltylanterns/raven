<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/views/panel_index.php
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
/** @var array{name?: string, version?: string, author?: string, description?: string, docs_url?: string} $extensionMeta */
/** @var string $extensionDirectory */
/** @var string $extensionPermissionAction */
/** @var string $extensionPermissionRedirect */
/** @var int $extensionRequiredPermissionBit */
/** @var array<int, array{bit: int, label: string}> $extensionPermissionOptions */

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Contact Forms'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>
                <?= e($extensionName !== '' ? $extensionName : 'Contact Forms') ?>
                <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
            </h1>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($extensionDocsUrl !== ''): ?>
                    <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                    </a>
                <?php endif; ?>
                <div class="dropdown">
                    <button
                        class="btn btn-warning btn-sm dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Set Permission Mask
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($extensionPermissionOptions as $option): ?>
                            <?php $optionBit = (int) ($option['bit'] ?? 0); ?>
                            <li>
                                <form method="post" action="<?= e($extensionPermissionAction) ?>">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="extension" value="<?= e($extensionDirectory) ?>">
                                    <input type="hidden" name="permission_bit" value="<?= e((string) $optionBit) ?>">
                                    <input type="hidden" name="redirect" value="<?= e($extensionPermissionRedirect) ?>">
                                    <button
                                        type="submit"
                                        class="dropdown-item"
                                    >
                                        <i class="bi bi-patch-check me-2<?= $optionBit === $extensionRequiredPermissionBit ? ' text-success' : ' opacity-0' ?>" aria-hidden="true"></i>
                                        <?= e((string) ($option['label'] ?? '')) ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
        <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Configured contact form definitions available to page content integrations.') ?></p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
    <a href="<?= e($editBasePath) ?>" class="btn btn-primary"><i class="bi bi-envelope-plus me-2" aria-hidden="true"></i>New Contact Form</a>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6 mb-3">Configured Forms</h2>

        <?php if ($forms === []): ?>
            <p class="text-muted mb-0">No contact forms are configured.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" data-raven-sort-table="1" data-sort-default-key="title" data-sort-default-direction="asc">
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
                            data-raven-sort-row="1"
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
</div>

<div class="d-flex justify-content-end mt-3">
    <a href="<?= e($editBasePath) ?>" class="btn btn-primary"><i class="bi bi-envelope-plus me-2" aria-hidden="true"></i>New Contact Form</a>
</div>
