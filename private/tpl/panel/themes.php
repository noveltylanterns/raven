<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/themes.php
 * Admin panel public-theme manager for listing, activation, and scaffolding.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $activeTheme */
/** @var array<string, string> $themeOptions */
/** @var string $packageArchiveAcceptAttribute */
/** @var array<int, string> $packageArchiveFormats */
/** @var array<string, string> $exportArchiveFormats */
/** @var array<int, array{
 *   slug: string,
 *   name: string,
 *   is_stock: bool,
 *   is_child_theme: bool,
 *   parent_theme: string,
 *   has_css: bool,
 *   has_wrapper: bool,
 *   inheritance_chain: string
 * }> $themes */

use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$packageArchiveHelp = array_map(
    static fn (string $format): string => '<code>.' . e(ltrim($format, '.')) . '</code>',
    $packageArchiveFormats
);
$themeHeaderActions = [
    '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#upload-theme-modal">'
        . '<i class="bi bi-upload me-2" aria-hidden="true"></i>Upload Theme'
        . '</button>',
    '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#create-theme-modal">'
        . '<i class="bi bi-plus-square me-2" aria-hidden="true"></i>Create New Theme'
        . '</button>',
];
?>
<?= Header::render([
    'title' => 'Theme Manager',
    'summary' => 'Manage installed public themes, switch the active theme, and scaffold new theme folders.',
    'actions' => $themeHeaderActions,
    'actions_class' => 'd-flex align-items-center gap-2',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <h2>Installed Themes</h2>
        <p class="text-muted">Active themes and stock themes cannot be uninstalled.</p>
        <?php if ($themes === []): ?>
            <p class="text-muted mb-0">No valid themes were discovered in <code>public/theme/</code>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Parent</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($themes as $theme): ?>
                        <?php
                        $slug = (string) ($theme['slug'] ?? '');
                        $name = (string) ($theme['name'] ?? $slug);
                        $isStock = (bool) ($theme['is_stock'] ?? false);
                        $parentTheme = (string) ($theme['parent_theme'] ?? '');
                        $isChildTheme = (bool) ($theme['is_child_theme'] ?? false);
                        $isActive = $slug === $activeTheme;
                        ?>
                        <tr>
                            <td>
                                <?= e($name) ?>
                                <?php if ($isChildTheme): ?>
                                    <span class="badge text-bg-secondary ms-2">Child</span>
                                <?php endif; ?>
                                <?php if ($isStock): ?>
                                    <span class="badge text-bg-info ms-2">Stock</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($slug) ?></code></td>
                            <td>
                                <?php if ($parentTheme !== ''): ?>
                                    <code><?= e($parentTheme) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">&lt;none&gt;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <form method="post" action="<?= e($panelBase) ?>/themes/enable" class="d-inline m-0">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="theme" value="<?= e($slug) ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-sm <?= $isActive ? 'btn-info' : 'btn-success' ?>"
                                            title="<?= e($isActive ? 'Active' : 'Enable') ?>"
                                            aria-label="<?= e($isActive ? 'Active' : 'Enable') ?>"
                                            <?= $isActive ? 'disabled' : '' ?>
                                        >
                                            <i class="bi <?= $isActive ? 'bi-check-circle-fill' : 'bi-play-circle-fill' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <div class="dropdown d-inline-block" data-rvn-portal-dropdown="1">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-secondary dropdown-toggle"
                                            data-bs-toggle="dropdown"
                                            data-bs-display="static"
                                            aria-expanded="false"
                                            title="Export"
                                            aria-label="Export"
                                        >
                                            <i class="bi bi-download" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end" data-rvn-portal-dropdown-menu="1">
                                            <?php foreach ($exportArchiveFormats as $formatValue => $formatLabel): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item"
                                                        href="<?= e($panelBase . '/themes/export?theme=' . rawurlencode($slug) . '&format=' . rawurlencode($formatValue)) ?>"
                                                    ><?= e($formatLabel) ?></a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php if (!$isActive && !$isStock): ?>
                                        <form method="post" action="<?= e($panelBase) ?>/themes/uninstall" class="d-inline m-0">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="theme" value="<?= e($slug) ?>">
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-danger"
                                                title="Uninstall"
                                                aria-label="Uninstall"
                                                onclick="return confirm('Uninstall theme <?= e($slug) ?> from disk?');"
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

<div class="modal fade" id="upload-theme-modal" tabindex="-1" aria-labelledby="upload-theme-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="upload-theme-modal-label">Upload Theme</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($panelBase) ?>/themes/upload" enctype="multipart/form-data">
                <?= $csrfField ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="theme_archive" class="form-label"><span class="h6">Theme Archive</span> <small>(<?= e(implode(', ', $packageArchiveFormats)) ?>)</small></label>
                        <input
                            id="theme_archive"
                            type="file"
                            name="theme_archive"
                            class="form-control"
                            accept="<?= e($packageArchiveAcceptAttribute) ?>"
                            required
                        >
                        <div class="form-text">Archive must contain a valid <code>theme.json</code> manifest. Raven accepts <?= implode(', ', $packageArchiveHelp) ?> packages; top-level folder wrappers are supported.</div>
                    </div>
                    <div>
                        <label for="theme_upload_slug" class="form-label">Slug Override (optional)</label>
                        <input
                            id="theme_upload_slug"
                            type="text"
                            name="upload_slug"
                            class="form-control"
                            maxlength="80"
                            pattern="[a-z0-9][a-z0-9_-]*"
                            placeholder="leave blank to use theme.json slug"
                        >
                        <div class="form-text">If left blank, Raven uses <code>theme.json</code> <code>slug</code> (fallback: archive filename). If that slug already exists, Raven appends <code>-copy</code> automatically.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Upload Theme</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="create-theme-modal" tabindex="-1" aria-labelledby="create-theme-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="create-theme-modal-label">Create New Theme</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($panelBase) ?>/themes/create">
                <?= $csrfField ?>
                <div class="modal-body">
                    <p class="mb-3">Create a new public theme in <code>public/theme/{slug}/</code>.</p>
                    <p class="mb-2 text-muted">You can either scaffold starter files or clone an existing theme as the base.</p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="theme_name" class="form-label">Theme Name</label>
                            <input
                                id="theme_name"
                                type="text"
                                name="name"
                                class="form-control"
                                maxlength="120"
                                required
                                placeholder="Example: New Public Theme"
                            >
                        </div>
                        <div class="col-md-6">
                            <label for="theme_slug" class="form-label">Directory Slug</label>
                            <input
                                id="theme_slug"
                                type="text"
                                name="theme"
                                class="form-control"
                                maxlength="80"
                                pattern="[a-z0-9][a-z0-9_-]*"
                                required
                                placeholder="example_theme"
                            >
                            <div class="form-text">Lowercase letters, numbers, underscores, and dashes only.</div>
                        </div>
                        <div class="col-12">
                            <label for="clone_theme" class="form-label">Clone Existing Theme (optional)</label>
                            <select id="clone_theme" name="clone_theme" class="form-select">
                                <option value="">None (use starter scaffold)</option>
                                <?php foreach ($themeOptions as $slug => $label): ?>
                                    <option value="<?= e($slug) ?>"><?= e($label) ?> [<?= e($slug) ?>]</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">When selected, all files from the source theme are copied into the new theme.</div>
                        </div>
                        <div class="col-12">
                            <label for="parent_theme" class="form-label">Parent Theme (optional)</label>
                            <select id="parent_theme" name="parent_theme" class="form-select">
                                <option value="">None (standalone theme)</option>
                                <?php foreach ($themeOptions as $slug => $label): ?>
                                    <option value="<?= e($slug) ?>"><?= e($label) ?> [<?= e($slug) ?>]</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Selecting a parent marks the new theme as a child theme and overrides cloned parent metadata.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input
                                    id="theme_generate_agents"
                                    type="checkbox"
                                    name="generate_agents"
                                    value="1"
                                    class="form-check-input"
                                >
                                <label for="theme_generate_agents" class="form-check-label">Generate Agent Guidance?</label>
                            </div>
                            <div class="form-text mb-2">
                                Creates <code>public/theme/{slug}/agents</code>, plus <code>AGENTS.md</code> and <code>CLAUDE.md</code> symlinks that both point to it.
                            </div>
                            <div class="form-check">
                                <input
                                    id="theme_generate_composer"
                                    type="checkbox"
                                    name="generate_composer"
                                    value="1"
                                    class="form-check-input"
                                >
                                <label for="theme_generate_composer" class="form-check-label">Generate composer.json?</label>
                            </div>
                            <div class="form-text">
                                Creates <code>public/theme/{slug}/composer.json</code> starter package metadata.
                            </div>
                            <div class="form-check mt-2">
                                <input
                                    id="theme_generate_package"
                                    type="checkbox"
                                    name="generate_package"
                                    value="1"
                                    class="form-check-input"
                                >
                                <label for="theme_generate_package" class="form-check-label">Generate package.json?</label>
                            </div>
                            <div class="form-text">
                                Creates <code>public/theme/{slug}/package.json</code> starter npm package metadata.
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="set_active" name="set_active">
                                <label class="form-check-label" for="set_active">
                                    Set as active site theme after scaffold
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">Create Theme</button>
                </div>
            </form>
        </div>
    </div>
</div>
