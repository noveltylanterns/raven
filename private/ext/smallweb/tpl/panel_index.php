<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/tpl/panel_index.php
 * Smallweb extension tabbed index: settings + per-protocol file lists.
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

/** @var array $settings */
/** @var string $currentTab */
/** @var array<string, array<int, array{name: string, type: string, hidden: bool, executable: bool, size: int, modified: int}>> $protocolFiles */
/** @var array{dirs: array, files: array}|null $protocolTree */
/** @var array<string, string>|null $parentDirs */
/** @var string $domain */
/** @var string $settingsSavePath */
/** @var string $indexPath */
/** @var callable(string): string $panelUrl */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string} $extensionMeta */
/** @var \Raven\Ext\Smallweb\SmallwebService $svc */

use Raven\Ext\Smallweb\SmallwebService;

use function Raven\Lib\Security\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Smallweb'));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? ''));

$protocols = $settings['protocols'] ?? [];
$enabledProtocols = [];
foreach (SmallwebService::SUPPORTED_PROTOCOLS as $proto) {
    if (!empty($protocols[$proto]['enabled'])) {
        $enabledProtocols[] = $proto;
    }
}

$activeTab = $currentTab;

$formatSize = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
};
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>
                <?= e($extensionName !== '' ? $extensionName : 'Smallweb') ?>
            </h1>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($extensionDocsUrl !== ''): ?>
                    <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <h5>by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h5>
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Manage plaintext webroots for finger, gopher, and gemini protocols.') ?></p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if ($activeTab === 'settings'): ?>
<?php // Standalone form element so submit buttons outside the section can target it via form="" ?>
<form id="sw-settings-form" method="post" action="<?= e($settingsSavePath) ?>">
    <?= $csrfField ?>
</form>

<nav>
    <button class="btn btn-primary" type="submit" form="sw-settings-form"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Settings</button>
</nav>
<?php else: ?>
<?php
    $files = $protocolFiles[$activeTab] ?? [];
    $protoLabel = $svc->protocolLabel($activeTab);
    $protoScheme = $svc->protocolScheme($activeTab);
    $newPath = $panelUrl('/smallweb/' . $activeTab . '/new');
    $editPath = $panelUrl('/smallweb/' . $activeTab . '/edit');
    $deletePath = $panelUrl('/smallweb/' . $activeTab . '/delete');
    $hasDirs = $svc->protocolSupportsDirectories($activeTab);
    $tree = $hasDirs ? ($protocolTree ?? ['dirs' => [], 'files' => []]) : null;
    $availableParentDirs = $hasDirs ? ($parentDirs ?? ['' => '/']) : [];
    $mkdirPath = $panelUrl('/smallweb/' . $activeTab . '/mkdir');
    $rmdirPath = $panelUrl('/smallweb/' . $activeTab . '/rmdir');
    $uploadPath = $panelUrl('/smallweb/' . $activeTab . '/upload');
?>

<nav>
    <a href="<?= e($newPath) ?>" class="btn btn-primary">
        <i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>New Page
    </a>
    <?php if ($hasDirs): ?>
    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#sw-mkdir-modal">
        <i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Folder
    </button>
    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#sw-upload-modal">
        <i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>Upload File
    </button>
    <?php endif; ?>
</nav>
<?php endif; ?>

<section class="rvnp-editor-layout">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab === 'settings' ? ' active' : '' ?>"
                href="<?= e($indexPath) ?>"
            ><i class="bi bi-gear me-1" aria-hidden="true"></i>Settings</a>
        </li>
        <?php foreach ($enabledProtocols as $proto): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $activeTab === $proto ? ' active' : '' ?>"
                    href="<?= e($panelUrl('/smallweb/' . $proto)) ?>"
                ><i class="bi <?= e($svc->protocolIcon($proto)) ?> me-1" aria-hidden="true"></i><?= e($svc->protocolLabel($proto)) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">

        <?php if ($activeTab === 'settings'): ?>
        <!-- ══ Settings ══ -->
            <?php foreach (SmallwebService::SUPPORTED_PROTOCOLS as $proto):
                $protoSettings = $protocols[$proto] ?? [];
                $protoEnabled = (bool) ($protoSettings['enabled'] ?? false);
                $protoLabel = $svc->protocolLabel($proto);
                $protoScheme = $svc->protocolScheme($proto);
            ?>
            <fieldset class="mb-2">
                <h3><i class="bi <?= e($svc->protocolIcon($proto)) ?> me-2" aria-hidden="true"></i><?= e($protoLabel) ?> Protocol</h3>
                <p class="text-muted small mb-2">Manage the <code><?= e($protoScheme) ?>://</code> webroot directory.</p>

                <div class="mb-2 form-check form-switch">
                    <input class="form-check-input" type="checkbox" form="sw-settings-form"
                        id="protocol_<?= e($proto) ?>_enabled"
                        name="protocol_<?= e($proto) ?>_enabled"
                        value="1"<?= $protoEnabled ? ' checked' : '' ?>>
                    <label class="form-check-label" for="protocol_<?= e($proto) ?>_enabled">Enable <?= e($protoLabel) ?></label>
                </div>

                <div id="sw-perms-<?= e($proto) ?>"<?= $protoEnabled ? '' : ' style="display:none"' ?>>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="protocol_<?= e($proto) ?>_chmod_dir" class="form-label">Directory chmod</label>
                        <input type="text" class="form-control font-monospace" form="sw-settings-form"
                            id="protocol_<?= e($proto) ?>_chmod_dir"
                            name="protocol_<?= e($proto) ?>_chmod_dir"
                            value="<?= e($protoSettings['chmod_dir'] ?? '0755') ?>"
                            maxlength="4" pattern="0[0-7]{3}" placeholder="0755">
                        <div class="form-text"><?= e($proto) ?>/ directory permissions</div>
                    </div>
                    <div class="col-md-4">
                        <label for="protocol_<?= e($proto) ?>_chmod_txt" class="form-label">Plaintext chmod</label>
                        <input type="text" class="form-control font-monospace" form="sw-settings-form"
                            id="protocol_<?= e($proto) ?>_chmod_txt"
                            name="protocol_<?= e($proto) ?>_chmod_txt"
                            value="<?= e($protoSettings['chmod_txt'] ?? '0644') ?>"
                            maxlength="4" pattern="0[0-7]{3}" placeholder="0644">
                        <div class="form-text">.txt file permissions</div>
                    </div>
                    <div class="col-md-4">
                        <label for="protocol_<?= e($proto) ?>_chmod_cgi" class="form-label">Script chmod</label>
                        <input type="text" class="form-control font-monospace" form="sw-settings-form"
                            id="protocol_<?= e($proto) ?>_chmod_cgi"
                            name="protocol_<?= e($proto) ?>_chmod_cgi"
                            value="<?= e($protoSettings['chmod_cgi'] ?? '0755') ?>"
                            maxlength="4" pattern="0[0-7]{3}" placeholder="0755">
                        <div class="form-text">.cgi file permissions</div>
                    </div>
                </div>
                </div>
            </fieldset>
            <?php if ($proto !== SmallwebService::SUPPORTED_PROTOCOLS[array_key_last(SmallwebService::SUPPORTED_PROTOCOLS)]): ?>
                <hr class="my-4">
            <?php endif; ?>
            <?php endforeach; ?>

            <hr class="my-4">
            <fieldset class="mb-2">
                <h3><i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>File Uploads</h3>
                <p class="text-muted small mb-2">Allowed file extensions for uploads to gemini, spartan, and gopher filetrees.</p>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="allowed_upload_extensions" class="form-label">Allowed Extensions</label>
                        <input type="text" class="form-control font-monospace" form="sw-settings-form"
                            id="allowed_upload_extensions"
                            name="allowed_upload_extensions"
                            value="<?= e($settings['allowed_upload_extensions'] ?? SmallwebService::DEFAULT_UPLOAD_EXTENSIONS) ?>"
                            maxlength="500"
                            placeholder="<?= e(SmallwebService::DEFAULT_UPLOAD_EXTENSIONS) ?>">
                        <div class="form-text">Comma-separated list of file extensions (without dots). These are in addition to built-in types like .gmi, .gph, .txt, .cgi.</div>
                    </div>
                </div>
            </fieldset>

        <?php else: ?>
        <!-- ══ Protocol File List: <?= e($activeTab) ?> ══ -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h3 class="mb-1"><?= e($protoScheme) ?>:// <?= $hasDirs ? 'Filetree' : 'Responses' ?></h3>
                <p class="text-muted small mb-0">
                    Manage <?= $hasDirs ? 'files' : 'responses' ?> served at <code><?= e($protoScheme) ?>://<?= e($domain) ?>/</code>
                </p>
            </div>
        </div>

        <?php if ($hasDirs && $tree !== null && $tree['dirs'] !== []):
            $renderDirAccordion = null;
            $renderDirAccordion = static function (array $dirs, string $accordionId) use (
                &$renderDirAccordion, $editPath, $deletePath, $rmdirPath, $newPath,
                $protoScheme, $domain, $svc, $activeTab, $formatSize, $csrfField
            ): void {
                $tabSupportsExecutable = $svc->protocolSupportsExecutable($activeTab);
            ?>
            <div class="accordion" id="<?= e($accordionId) ?>">
                <?php foreach ($dirs as $dirEntry):
                    $dirName = (string) $dirEntry['name'];
                    $dirPath = (string) $dirEntry['path'];
                    $children = (array) ($dirEntry['children'] ?? ['dirs' => [], 'files' => []]);
                    $childDirs = (array) ($children['dirs'] ?? []);
                    $childFiles = (array) ($children['files'] ?? []);
                    $isEmpty = $childDirs === [] && $childFiles === [];
                    $collapseId = 'sw-dir-' . str_replace('/', '-', $dirPath);
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <div class="d-flex align-items-center">
                            <button class="accordion-button collapsed flex-grow-1 py-2" type="button"
                                data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>"
                                aria-expanded="false" aria-controls="<?= e($collapseId) ?>">
                                <i class="bi bi-folder me-2" aria-hidden="true"></i>
                                <code style="text-transform:none"><?= e($dirName) ?>/</code>
                                <?php if ($isEmpty): ?>
                                    <span class="badge bg-secondary ms-2">empty</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2"><?= count($childFiles) ?> file<?= count($childFiles) !== 1 ? 's' : '' ?><?= $childDirs !== [] ? ', ' . count($childDirs) . ' folder' . (count($childDirs) !== 1 ? 's' : '') : '' ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="d-flex gap-1 me-2 flex-shrink-0">
                                <a href="<?= e($newPath) ?>?dir=<?= rawurlencode($dirPath) ?>" class="btn btn-primary btn-sm" title="New page in /<?= e($dirPath) ?>" aria-label="New page in folder"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i></a>
                                <button type="button" class="btn btn-secondary btn-sm" title="Upload file to /<?= e($dirPath) ?>" aria-label="Upload file to folder" data-bs-toggle="modal" data-bs-target="#sw-upload-modal" onclick="document.getElementById('sw-upload-parent').value='<?= e($dirPath) ?>'"><i class="bi bi-cloud-upload" aria-hidden="true"></i></button>
                                <?php if ($isEmpty): ?>
                                <form method="post" action="<?= e($rmdirPath) ?>" class="m-0">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="subdir" value="<?= e($dirPath) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete folder" aria-label="Delete folder"
                                        onclick="return confirm('Delete folder /<?= e($dirPath) ?>?');"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </h2>
                    <div id="<?= e($collapseId) ?>" class="accordion-collapse collapse">
                        <div class="accordion-body p-2">
                            <?php if ($childDirs !== []):
                                $renderDirAccordion($childDirs, $collapseId . '-sub');
                            endif; ?>
                            <?php if ($childFiles !== []): ?>
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th>Type</th>
                                    <?php if ($tabSupportsExecutable): ?><th>Status</th><?php endif; ?>
                                    <th>Size</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($childFiles as $cf):
                                    $cfName = (string) ($cf['name'] ?? '');
                                    $cfType = (string) ($cf['type'] ?? '');
                                    $cfExec = (bool) ($cf['executable'] ?? false);
                                    $cfSize = (int) ($cf['size'] ?? 0);
                                    $cfDirQuery = '?dir=' . rawurlencode($dirPath);
                                ?>
                                <tr>
                                    <td><?php if ($cfType !== 'file'): ?><a href="<?= e($editPath) ?>/<?= rawurlencode($cfName) . e($cfDirQuery) ?>"><code style="text-transform:none"><?= e($cfName) ?></code></a><?php else: ?><code style="text-transform:none"><?= e($cfName) ?></code><?php endif; ?></td>
                                    <td><span class="badge bg-secondary"><?= e($svc->typeLabel($cfType)) ?></span></td>
                                    <?php if ($tabSupportsExecutable): ?>
                                    <td><span class="badge <?= $cfExec ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $cfExec ? 'Executable' : 'Static' ?></span></td>
                                    <?php endif; ?>
                                    <td><?= e($formatSize($cfSize)) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-2">
                                            <?php if ($cfType !== 'file'): ?>
                                            <a href="<?= e($editPath) ?>/<?= rawurlencode($cfName) . e($cfDirQuery) ?>" class="btn btn-primary btn-sm" title="Edit" aria-label="Edit"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                            <?php endif; ?>
                                            <form method="post" action="<?= e($deletePath) ?>" class="m-0">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="filename" value="<?= e($cfName) ?>">
                                                <input type="hidden" name="dir" value="<?= e($dirPath) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete"
                                                    onclick="return confirm('Delete <?= e($cfName) ?>?');"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php elseif ($childDirs === []): ?>
                                <p class="text-muted small mb-0 ps-2">Empty folder.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php };
            $renderDirAccordion($tree['dirs'], 'sw-dirs-' . $activeTab);
        endif; ?>

        <?php if ($files === [] && (!$hasDirs || $tree === null || $tree['dirs'] === [])): ?>
            <p class="text-muted mb-0">No <?= e($protoScheme) ?>:// <?= $hasDirs ? 'files' : 'responses' ?> yet. Create one to get started.</p>
        <?php elseif ($files !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" data-rvn-sort-table="1" data-sort-default-key="filename" data-sort-default-direction="asc">
                    <thead>
                    <tr>
                        <th scope="col" class="text-center">Link</th>
                        <th scope="col" data-sort-key="filename" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Filename</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="type" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Type</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="status" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Status</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="size" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Size</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" data-sort-key="modified" role="button" tabindex="0" aria-sort="none"><span class="raven-routing-sort-label">Modified</span><i class="bi raven-routing-sort-caret ms-1" aria-hidden="true"></i></th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                        $tabSupportsHidden = $svc->protocolSupportsHidden($activeTab);
                        $tabSupportsExecutable = $svc->protocolSupportsExecutable($activeTab);
                    ?>
                    <?php foreach ($files as $file):
                        $fname = (string) ($file['name'] ?? '');
                        $ftype = (string) ($file['type'] ?? 'txt');
                        $fhidden = (bool) ($file['hidden'] ?? false);
                        $fexecutable = (bool) ($file['executable'] ?? false);
                        $fsize = (int) ($file['size'] ?? 0);
                        $fmod = (int) ($file['modified'] ?? 0);
                        $typeLabel = $svc->typeLabel($ftype);
                        $typeBadgeClass = ($ftype === 'cgi') ? 'bg-warning text-dark' : 'bg-secondary';
                        if ($tabSupportsHidden) {
                            $statusLabel = $fhidden ? 'Hidden' : 'Public';
                            $statusBadgeClass = $fhidden ? 'bg-dark' : 'bg-success';
                        } else {
                            $statusLabel = $fexecutable ? 'Executable' : 'Static';
                            $statusBadgeClass = $fexecutable ? 'bg-warning text-dark' : 'bg-secondary';
                        }
                        $modifiedLabel = $fmod > 0 ? date('Y-m-d H:i', $fmod) : "\xE2\x80\x94";
                        $slug = pathinfo($fname, PATHINFO_FILENAME);
                        $protoUri = $protoScheme . '://' . $domain . '/' . $slug;
                    ?>
                        <tr
                            data-rvn-sort-row="1"
                            data-sort-filename="<?= e($fname) ?>"
                            data-sort-type="<?= e($typeLabel) ?>"
                            data-sort-status="<?= e($statusLabel) ?>"
                            data-sort-size="<?= $fsize ?>"
                            data-sort-modified="<?= $fmod ?>"
                        >
                            <td class="text-center">
                                <button type="button" class="btn btn-secondary btn-sm sw-copy-link" data-copy="<?= e($protoUri) ?>" title="<?= e($protoUri) ?>" aria-label="Copy link" data-bs-toggle="tooltip" data-bs-trigger="manual" data-bs-title="Copied!">
                                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                                </button>
                            </td>
                            <td>
                                <?php if ($ftype !== 'file'): ?>
                                <a href="<?= e($editPath) ?>/<?= rawurlencode($fname) ?>">
                                    <code><?= e($fname) ?></code>
                                </a>
                                <?php else: ?>
                                <code><?= e($fname) ?></code>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $typeBadgeClass ?>"><?= e($typeLabel) ?></span></td>
                            <td><span class="badge <?= $statusBadgeClass ?>"><?= e($statusLabel) ?></span></td>
                            <td><?= e($formatSize($fsize)) ?></td>
                            <td><?= e($modifiedLabel) ?></td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-2">
                                    <?php if ($ftype !== 'file'): ?>
                                    <a
                                        href="<?= e($editPath) ?>/<?= rawurlencode($fname) ?>"
                                        class="btn btn-primary btn-sm"
                                        title="Edit"
                                        aria-label="Edit"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="visually-hidden">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e($deletePath) ?>" class="m-0">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="filename" value="<?= e($fname) ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-danger btn-sm"
                                            title="Delete"
                                            aria-label="Delete"
                                            onclick="return confirm('Delete <?= e($fname) ?>?');"
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
        <?php endif; ?>

    </div>
</section>

<?php if ($hasDirs && $activeTab !== 'settings'): ?>
<!-- ══ New Folder Modal (must be outside rvnp-editor-layout to avoid stacking context issues) ══ -->
<div class="modal fade" id="sw-mkdir-modal" tabindex="-1" aria-labelledby="sw-mkdir-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="sw-mkdir-modal-label">New Folder</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($mkdirPath) ?>">
                <?= $csrfField ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="sw-mkdir-slug" class="form-label">Folder Name</label>
                        <input type="text" class="form-control font-monospace" id="sw-mkdir-slug" name="folder_slug"
                            required pattern="[a-z0-9][a-z0-9_\-]*" maxlength="120" placeholder="my-folder" autocomplete="off">
                        <div class="form-text">Lowercase letters, numbers, hyphens, underscores.</div>
                    </div>
                    <div>
                        <label for="sw-mkdir-parent" class="form-label">Parent Directory</label>
                        <select class="form-select font-monospace" id="sw-mkdir-parent" name="folder_parent">
                            <?php foreach ($availableParentDirs as $dirValue => $dirLabel): ?>
                                <option value="<?= e($dirValue) ?>"><?= e($dirLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Create Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ Upload File Modal ══ -->
<div class="modal fade" id="sw-upload-modal" tabindex="-1" aria-labelledby="sw-upload-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title mb-0" id="sw-upload-modal-label">Upload File</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= e($uploadPath) ?>" enctype="multipart/form-data">
                <?= $csrfField ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="sw-upload-file" class="form-label">File</label>
                        <input type="file" class="form-control" id="sw-upload-file" name="upload_file" required
                            accept="<?= e('.' . implode(',.', $svc->getAllowedUploadExtensions())) ?>">
                        <div class="form-text">Allowed: <?= e(implode(', ', $svc->getAllowedUploadExtensions())) ?></div>
                    </div>
                    <div>
                        <label for="sw-upload-parent" class="form-label">Parent Directory</label>
                        <select class="form-select font-monospace" id="sw-upload-parent" name="upload_parent">
                            <?php foreach ($availableParentDirs as $dirValue => $dirLabel): ?>
                                <option value="<?= e($dirValue) ?>"><?= e($dirLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'settings'): ?>
<nav>
    <button class="btn btn-primary" type="submit" form="sw-settings-form"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Settings</button>
</nav>
<script>
document.querySelectorAll('[id^="protocol_"][id$="_enabled"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var proto = this.id.replace('protocol_', '').replace('_enabled', '');
        var panel = document.getElementById('sw-perms-' + proto);
        if (panel) {
            panel.style.display = this.checked ? '' : 'none';
        }
    });
});
</script>
<?php else: ?>
<nav>
    <a href="<?= e($newPath) ?>" class="btn btn-primary">
        <i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>New Page
    </a>
    <?php if ($hasDirs): ?>
    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#sw-mkdir-modal">
        <i class="bi bi-folder-plus me-2" aria-hidden="true"></i>New Folder
    </button>
    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#sw-upload-modal">
        <i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>Upload File
    </button>
    <?php endif; ?>
</nav>
<script>
window.addEventListener('load', function() {
    document.querySelectorAll('.sw-copy-link').forEach(function(btn) {
        var tip = new bootstrap.Tooltip(btn);
        btn.addEventListener('click', function() {
            var text = this.getAttribute('data-copy');
            var icon = this.querySelector('i');
            navigator.clipboard.writeText(text).then(function() {
                icon.className = 'bi bi-clipboard-check';
                tip.show();
                setTimeout(function() {
                    tip.hide();
                    icon.className = 'bi bi-clipboard';
                }, 1500);
            });
        });
    });
});
</script>
<?php endif; ?>
