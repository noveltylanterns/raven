<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/extensions.php
 * Admin panel extensions manager for scaffolding, upload, and lifecycle controls.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This view is intentionally minimal; it reserves UX space for future extension capabilities.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array<int, array{
 *   directory: string,
 *   panel_path: string,
 *   has_panel_routes: bool,
 *   name: string,
 *   version: string,
 *   description: string,
 *   author: string,
 *   author_url: string,
 *   homepage: string,
 *   valid: bool,
 *   invalid_reason: string,
 *   enabled: bool,
 *   is_stock: bool,
 *   can_delete: bool,
 *   delete_block_reason: string
 * }> $extensions */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
?>
<style>
    body#rvnp #extensions-table thead th[data-sort-key] {
        color: var(--raven-muted);
        cursor: pointer;
        user-select: none;
        transition: color 140ms ease;
    }

    body#rvnp #extensions-table thead th[data-sort-key].is-active-sort {
        color: var(--bs-emphasis-color);
        font-weight: 700;
    }

    body#rvnp #extensions-table thead th[data-sort-key].is-active-sort .raven-routing-sort-caret {
        opacity: 1;
    }
</style>

<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>Extension Manager</h1>
            <div class="d-flex align-items-center gap-2">
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#upload-extension-modal"
                >
                    <i class="bi bi-upload me-2" aria-hidden="true"></i>Upload Extension
                </button>
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#create-extension-modal"
                ><i class="bi bi-plus-square me-2" aria-hidden="true"></i>Create New Extension</button>
            </div>
        </div>
        <p class="text-muted mb-2">Use this page to create, upload, enable, and disable Raven extensions.</p>
        <h5>Notes:</h5>
        <p class="text-muted mb-0">
          - Enabled extensions must be disabled before deletion.<br>
          - Stock extensions cannot be deleted, only disabled.<br>
          - Disable unused extensions for performance!
        </p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <h2>Installed Extensions</h2>
        <?php if ($extensions === []): ?>
            <p class="text-muted mb-0">No extensions found in <code>private/ext/</code>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="extensions-table" class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th scope="col" data-sort-key="name" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Name</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="author" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Author</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="description" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Description</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($extensions as $extension): ?>
                        <?php
                        $directory = (string) ($extension['directory'] ?? '');
                        $extensionPanelPath = trim((string) ($extension['panel_path'] ?? ''), '/');
                        $name = (string) ($extension['name'] ?? $directory);
                        $description = (string) ($extension['description'] ?? '');
                        $author = (string) ($extension['author'] ?? '');
                        $authorUrl = (string) ($extension['author_url'] ?? '');
                        $homepage = (string) ($extension['homepage'] ?? '');
                        $valid = (bool) ($extension['valid'] ?? false);
                        $invalidReason = (string) ($extension['invalid_reason'] ?? '');
                        $enabled = (bool) ($extension['enabled'] ?? false);
                        $isStock = (bool) ($extension['is_stock'] ?? false);
                        $canDelete = (bool) ($extension['can_delete'] ?? false);
                        $deleteBlockReason = (string) ($extension['delete_block_reason'] ?? '');
                        $nameLabel = $name !== '' ? $name : $directory;
                        $panelTarget = $extensionPanelPath !== '' ? ($panelBase . '/' . ltrim($extensionPanelPath, '/')) : '';
                        $canOpenSettings = $enabled && $panelTarget !== '';
                        $authorLabel = $author !== '' ? $author : '<none>';
                        $descriptionLabel = $description !== '' ? $description : '<none>';
                        ?>
                        <tr
                            data-extensions-row="1"
                            data-sort-name="<?= e($nameLabel) ?>"
                            data-sort-author="<?= e($authorLabel) ?>"
                            data-sort-description="<?= e($descriptionLabel) ?>"
                        >
                            <td>
                                <?php if ($homepage !== ''): ?>
                                    <a href="<?= e($homepage) ?>" target="_blank" rel="noopener noreferrer"><?= e($nameLabel) ?></a>
                                <?php else: ?>
                                    <?= e($nameLabel) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($author !== '' && $authorUrl !== ''): ?>
                                    <a href="<?= e($authorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($author) ?></a>
                                <?php elseif ($author !== ''): ?>
                                    <?= e($author) ?>
                                <?php else: ?>
                                    <?= e('<none>') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($descriptionLabel) ?>
                                <?php if (!$valid && $invalidReason !== ''): ?>
                                    <div class="small text-danger mt-1"><?= e($invalidReason) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <?php if ($canOpenSettings): ?>
                                        <a
                                            href="<?= e($panelTarget) ?>"
                                            class="btn btn-primary btn-sm"
                                            aria-label="Settings"
                                            title="Settings"
                                        >
                                            <i class="bi bi-gear-fill" aria-hidden="true"></i>
                                            <span class="visually-hidden">Settings</span>
                                        </a>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e($panelBase) ?>/extensions/toggle" class="d-inline m-0">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="extension" value="<?= e($directory) ?>">
                                        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                                        <button
                                            type="submit"
                                            class="btn <?= $enabled ? 'btn-warning' : 'btn-success' ?> btn-sm"
                                            <?= !$valid ? 'disabled' : '' ?>
                                            <?= !$valid && $invalidReason !== '' ? 'title="' . e($invalidReason) . '"' : '' ?>
                                            <?= $valid ? 'title="' . e($enabled ? 'Disable' : 'Enable') . '"' : '' ?>
                                            aria-label="<?= e($enabled ? 'Disable' : 'Enable') ?>"
                                        >
                                            <i class="bi <?= $enabled ? 'bi-stop-circle-fill' : 'bi-play-circle-fill' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <form method="get" action="<?= e($panelBase) ?>/extensions/export" class="d-inline m-0">
                                        <input type="hidden" name="extension" value="<?= e($directory) ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-secondary btn-sm"
                                            title="Export"
                                            aria-label="Export"
                                        >
                                            <i class="bi bi-download" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <?php if ($canDelete): ?>
                                        <form method="post" action="<?= e($panelBase) ?>/extensions/delete" class="d-inline m-0">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="extension" value="<?= e($directory) ?>">
                                            <button
                                                type="submit"
                                                class="btn btn-danger btn-sm"
                                                aria-label="Delete"
                                                title="Delete"
                                                onclick="return confirm('Delete this extension from disk? This cannot be undone.');"
                                            >
                                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
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

<div class="modal fade" id="upload-extension-modal" tabindex="-1" aria-labelledby="upload-extension-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="upload-extension-modal-label">Upload Extension</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($panelBase) ?>/extensions/upload" enctype="multipart/form-data">
                <?= $csrfField ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="extension_archive" class="form-label"><span class="h6">Extension Archive</span> <small>(zip)</small></label>
                        <input
                            id="extension_archive"
                            type="file"
                            name="extension_archive"
                            class="form-control"
                            accept=".zip,application/zip"
                            required
                        >
                        <div class="form-text">Archive must contain a valid <code>ext.json</code> manifest (top-level folder wrappers are supported).</div>
                    </div>
                    <div>
                        <label for="extension_upload_slug" class="form-label">Slug Override (optional)</label>
                        <input
                            id="extension_upload_slug"
                            type="text"
                            name="upload_slug"
                            class="form-control"
                            maxlength="120"
                            pattern="[a-z0-9][a-z0-9_-]*"
                            placeholder="leave blank to use ext.json slug"
                        >
                        <div class="form-text">If left blank, Raven uses <code>ext.json</code> <code>slug</code>. If that slug already exists, Raven appends <code>-copy</code> automatically.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Upload Extension</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="create-extension-modal" tabindex="-1" aria-labelledby="create-extension-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="create-extension-modal-label">Create New Extension</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($panelBase) ?>/extensions/create">
                <?= $csrfField ?>
                <div class="modal-body">
                    <div class="row g-3">
                        <p>Use this form to scaffold a new skeleton extension in <code>private/ext/{slug}/</code>:</p>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="extension_name" class="form-label">Extension Name</label>
                            <input
                                id="extension_name"
                                type="text"
                                name="name"
                                class="form-control"
                                maxlength="120"
                                required
                                placeholder="Example: My Extension"
                            >
                        </div>
                        <div class="col-md-6">
                            <label for="extension_directory" class="form-label">Directory Slug</label>
                            <input
                                id="extension_directory"
                                type="text"
                                name="extension"
                                class="form-control"
                                maxlength="120"
                                pattern="[a-z0-9][a-z0-9_-]*"
                                required
                                placeholder="my_extension"
                            >
                            <div class="form-text">Lowercase letters, numbers, underscores, and dashes only.</div>
                        </div>
                        <div class="col-md-2">
                            <label for="extension_version" class="form-label">Version</label>
                            <input
                                id="extension_version"
                                type="text"
                                name="version"
                                class="form-control"
                                maxlength="80"
                                placeholder="0.1.0"
                            >
                        </div>
                        <div class="col-md-5">
                            <label for="extension_author" class="form-label">Author Name</label>
                            <input
                                id="extension_author"
                                type="text"
                                name="author"
                                class="form-control"
                                maxlength="120"
                                placeholder="Team or company"
                            >
                        </div>
                        <div class="col-md-5">
                            <label for="extension_author_url" class="form-label">Author URL</label>
                            <input
                                id="extension_author_url"
                                type="url"
                                name="author_url"
                                class="form-control"
                                maxlength="400"
                                placeholder="https://example.com/about"
                            >
                        </div>
                        <div class="col-12">
                            <label for="extension_docs_url" class="form-label">Documentation URL</label>
                            <input
                                id="extension_docs_url"
                                type="url"
                                name="docs_url"
                                class="form-control"
                                maxlength="400"
                                placeholder="https://example.com"
                            >
                        </div>
                        <div class="col-12">
                            <label for="extension_description" class="form-label">Description</label>
                            <textarea
                                id="extension_description"
                                name="description"
                                class="form-control"
                                rows="3"
                                maxlength="1000"
                                placeholder="Describe what this extension does."
                            ></textarea>
                        </div>
                        <div class="col-12">
                            <label for="extension_type" class="form-label">Type</label>
                            <select id="extension_type" name="type" class="form-select" required>
                                <option value="" selected disabled>Select plugin type...</option>
                                <option value="content">content</option>
                                <option value="helper">helper</option>
                                <option value="module">module</option>
                                <option value="plugin">plugin</option>
                                <option value="system">system</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="row g-3">
                                <div class="col-12 col-md-7">
                                    <div class="form-check">
                                        <input
                                            id="extension_generate_agents"
                                            type="checkbox"
                                            name="generate_agents"
                                            value="1"
                                            class="form-check-input"
                                            checked
                                        >
                                        <label for="extension_generate_agents" class="form-check-label">Generate AGENTS.md?</label>
                                    </div>
                                    <div class="form-text mb-3">
                                        Creates <code>private/ext/{slug}/AGENTS.md</code> with extension-local guidance and a reference to <code>private/ext/AGENTS.md</code>.
                                    </div>
                                    <div class="form-check">
                                        <input
                                            id="extension_generate_composer"
                                            type="checkbox"
                                            name="generate_composer"
                                            value="1"
                                            class="form-check-input"
                                            checked
                                        >
                                        <label for="extension_generate_composer" class="form-check-label">Generate composer.json?</label>
                                    </div>
                                    <div class="form-text">
                                        Creates <code>private/ext/{slug}/composer.json</code> with starter package metadata.
                                    </div>
                                </div>
                                <div class="col-12 col-md-5">
                                    <h6 class="mb-2">Generated Files</h6>
                                    <ul id="extension-generated-files" class="small mb-0"></ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Create Extension<i class="bi bi-plus-square ms-2" aria-hidden="true"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var table = document.getElementById('extensions-table');
        if (!(table instanceof HTMLTableElement)) {
            return;
        }
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-extensions-row="1"]'));
        var tableBody = table.tBodies.length > 0 ? table.tBodies[0] : null;
        var sortHeaders = Array.prototype.slice.call(table.querySelectorAll('thead th[data-sort-key]'));
        var sortState = {
            key: 'name',
            direction: 'asc'
        };
        var sortAttrByKey = {
            name: 'data-sort-name',
            author: 'data-sort-author',
            description: 'data-sort-description'
        };
        function sortValue(row, key) {
            var attrName = sortAttrByKey[key];
            if (typeof attrName !== 'string' || attrName === '') {
                return '';
            }

            return String(row.getAttribute(attrName) || '');
        }
        function compareNatural(left, right) {
            return String(left).localeCompare(String(right), undefined, {
                numeric: true,
                sensitivity: 'base'
            });
        }
        function updateSortHeaderState() {
            sortHeaders.forEach(function (header) {
                if (!(header instanceof HTMLTableCellElement)) {
                    return;
                }
                var key = String(header.getAttribute('data-sort-key') || '').trim();
                var caretIcon = header.querySelector('.raven-routing-sort-caret');
                if (key === '' || key !== sortState.key) {
                    header.setAttribute('aria-sort', 'none');
                    header.classList.remove('is-active-sort');
                    if (caretIcon instanceof HTMLElement) {
                        caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                    }
                    return;
                }
                header.setAttribute('aria-sort', sortState.direction === 'desc' ? 'descending' : 'ascending');
                header.classList.add('is-active-sort');
                if (caretIcon instanceof HTMLElement) {
                    caretIcon.classList.remove('bi-caret-up-fill', 'bi-caret-down-fill');
                    caretIcon.classList.add(sortState.direction === 'desc' ? 'bi-caret-down-fill' : 'bi-caret-up-fill');
                }
            });
        }
        function sortRowsBy(key, forcedDirection) {
            if (!(tableBody instanceof HTMLTableSectionElement) || key === '') {
                return;
            }
            var direction = 'asc';
            if (forcedDirection === 'asc' || forcedDirection === 'desc') {
                direction = forcedDirection;
            } else if (sortState.key === key) {
                direction = sortState.direction === 'asc' ? 'desc' : 'asc';
            }
            sortState = {
                key: key,
                direction: direction
            };
            rows.sort(function (leftRow, rightRow) {
                var leftValue = sortValue(leftRow, key);
                var rightValue = sortValue(rightRow, key);
                var result = compareNatural(leftValue, rightValue);
                if (result === 0) {
                    result = compareNatural(
                        sortValue(leftRow, 'name'),
                        sortValue(rightRow, 'name')
                    );
                }
                return direction === 'desc' ? -result : result;
            });
            rows.forEach(function (row) {
                tableBody.appendChild(row);
            });
            updateSortHeaderState();
        }
        sortHeaders.forEach(function (header) {
            if (!(header instanceof HTMLTableCellElement)) {
                return;
            }
            var key = String(header.getAttribute('data-sort-key') || '').trim();
            if (key === '') {
                return;
            }
            header.addEventListener('click', function () {
                sortRowsBy(key);
            });
            header.addEventListener('keydown', function (event) {
                if (!(event instanceof KeyboardEvent)) {
                    return;
                }
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                sortRowsBy(key);
            });
        });
        sortRowsBy('name', 'asc');
    })();
</script>
<script>
    (function () {
        var extensionTypeSelect = document.getElementById('extension_type');
        var generateAgentsCheckbox = document.getElementById('extension_generate_agents');
        var generateComposerCheckbox = document.getElementById('extension_generate_composer');
        var generatedFilesList = document.getElementById('extension-generated-files');
        if (!(extensionTypeSelect instanceof HTMLSelectElement)) {
            return;
        }
        if (!(generatedFilesList instanceof HTMLUListElement)) {
            return;
        }

        function renderGeneratedFiles() {
            var type = String(extensionTypeSelect.value || '').toLowerCase().trim();
            if (type === '') {
                generatedFilesList.innerHTML = '';
                return;
            }
            var files = ['ext.json', 'ext.php', 'lib/schema.php'];
            if (type === 'helper' || type === 'plugin' || type === 'module') {
                files.push('lib/shortcodes.php');
            }
            if (type === 'content' || type === 'plugin' || type === 'module') {
                files.push('lib/fields.php');
            }
            files.push('lib/routes_panel.php', 'tpl/panel_index.php');
            if (type === 'module') {
                files.push('lib/routes_public.php');
                files.push('tpl/public_index.php');
            }
            if (generateAgentsCheckbox instanceof HTMLInputElement && generateAgentsCheckbox.checked) {
                files.push('AGENTS.md');
            }
            if (generateComposerCheckbox instanceof HTMLInputElement && generateComposerCheckbox.checked) {
                files.push('composer.json');
            }

            files.sort(function (left, right) {
                return String(left).localeCompare(String(right), undefined, {
                    numeric: true,
                    sensitivity: 'base'
                });
            });

            generatedFilesList.innerHTML = '';
            files.forEach(function (file) {
                var item = document.createElement('li');
                var code = document.createElement('code');
                code.textContent = file;
                item.appendChild(code);
                generatedFilesList.appendChild(item);
            });
        }

        extensionTypeSelect.addEventListener('change', renderGeneratedFiles);
        if (generateAgentsCheckbox instanceof HTMLInputElement) {
            generateAgentsCheckbox.addEventListener('change', renderGeneratedFiles);
        }
        if (generateComposerCheckbox instanceof HTMLInputElement) {
            generateComposerCheckbox.addEventListener('change', renderGeneratedFiles);
        }
        renderGeneratedFiles();
    })();
</script>
