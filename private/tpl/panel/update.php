<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/update.php
 * Admin panel update workflow template.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array<string, mixed> $updateSource */
/** @var array<string, mixed> $updateResult */
/** @var bool $allowOverwrite */
/** @var array<string, string> $updateSourceModes */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$updateSource = is_array($updateSource ?? null) ? $updateSource : [];
$updateResult = is_array($updateResult ?? null) ? $updateResult : [];
$updateSourceModes = is_array($updateSourceModes ?? null) ? $updateSourceModes : [];
$selectedMode = (string) ($updateSource['mode'] ?? 'github_mirror');
$githubRepo = (string) ($updateSource['github_repo'] ?? 'noveltylanterns/raven');
$repoUrl = (string) ($updateSource['repo_url'] ?? '');
$comparison = is_array($updateResult['comparison'] ?? null) ? $updateResult['comparison'] : [];
$local = is_array($updateResult['local'] ?? null) ? $updateResult['local'] : [];
$remote = is_array($updateResult['remote'] ?? null) ? $updateResult['remote'] : [];
$summary = is_array($updateResult['summary'] ?? null) ? $updateResult['summary'] : [];
$actions = is_array($updateResult['actions'] ?? null) ? $updateResult['actions'] : [];
$comparisonState = (string) ($comparison['state'] ?? 'unknown');
$comparisonLabel = (string) ($comparison['label'] ?? 'Unknown');
$statusClass = match ($comparisonState) {
    'up_to_date' => 'success',
    'behind' => 'warning',
    'ahead', 'diverged' => 'danger',
    default => 'secondary',
};
$resultOperation = (string) ($updateResult['operation'] ?? 'check');
$revisionRows = [
    [
        'label' => 'Revision',
        'local' => (string) ($local['revision'] ?? ''),
        'local_meta' => '',
        'remote' => (string) ($remote['revision'] ?? ''),
        'remote_meta' => '',
    ],
    [
        'label' => 'Branch',
        'local' => (string) ($local['branch'] ?? ''),
        'local_meta' => '',
        'remote' => (string) ($remote['branch'] ?? ''),
        'remote_meta' => '',
    ],
    [
        'label' => 'Timestamp',
        'local' => (string) ($local['timestamp'] ?? ''),
        'local_meta' => '',
        'remote' => (string) ($remote['timestamp'] ?? ''),
        'remote_meta' => '',
    ],
];
$updateToolbarItems = [
    '<button type="submit" form="update-system-form" name="update_action" value="check" class="btn btn-primary">Check For Updates</button>',
    '<button type="submit" form="update-system-form" name="update_action" value="dry_run" class="btn btn-secondary">Dry Run</button>',
    '<button type="submit" form="update-system-form" name="update_action" value="update_now" class="btn btn-danger">Update Now</button>',
];
?>

<?= Header::render([
    'title' => 'Update Raven',
    'summary' => 'Compare this Raven install against a git source, dry run the overlay, or update the managed package files in place.',
    'help_url' => $panelBase . '/docs/updates',
]) ?>

<?= Toolbar::render([
    'items' => $updateToolbarItems,
    'class' => 'rvnp-editor-actions',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<form id="update-system-form" method="post" action="<?= e($panelBase) ?>/update/action" class="mb-0">
    <?= $csrfField ?>
    <section class="card">
        <div class="card-body">
            <h2 class="mb-3">Source</h2>
            <div class="row g-3">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="update_source_mode">Source</label>
                    <select class="form-select" id="update_source_mode" name="update_source_mode">
                        <?php foreach ($updateSourceModes as $modeKey => $modeLabel): ?>
                            <option value="<?= e($modeKey) ?>"<?= $selectedMode === $modeKey ? ' selected' : '' ?>><?= e($modeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-5" data-update-source-field="github_custom"<?= $selectedMode === 'github_custom' ? '' : ' style="display:none;"' ?>>
                    <label class="form-label" for="update_source_github_repo">Github Repo</label>
                    <input
                        class="form-control font-monospace"
                        id="update_source_github_repo"
                        name="update_source_github_repo"
                        value="<?= e($githubRepo) ?>"
                        placeholder="owner/repo"
                    >
                </div>
                <div class="col-12 col-lg-5" data-update-source-field="repo_custom"<?= $selectedMode === 'repo_custom' ? '' : ' style="display:none;"' ?>>
                    <label class="form-label" for="update_source_repo_url">Custom Repo URL</label>
                    <input
                        class="form-control font-monospace"
                        id="update_source_repo_url"
                        name="update_source_repo_url"
                        value="<?= e($repoUrl) ?>"
                        placeholder="https://example.com/repo.git"
                    >
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label d-block">Overwrite Override</label>
                    <div class="form-check mt-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="allow_overwrite"
                            name="allow_overwrite"
                            value="1"
                            <?= !empty($allowOverwrite) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="allow_overwrite">Allow overwrite of local core changes</label>
                    </div>
                </div>
            </div>
        </div>
    </section>
</form>

<section class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
            <h2 class="mb-0">Repository State</h2>
            <span class="badge text-bg-<?= e($statusClass) ?>"><?= e($comparisonLabel) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table rvnp-table-static align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Field</th>
                        <th scope="col">Local</th>
                        <th scope="col">Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revisionRows as $row): ?>
                        <tr>
                            <th scope="row"><?= e((string) ($row['label'] ?? '')) ?></th>
                            <td>
                                <code><?= e((string) ($row['local'] ?? '')) ?></code>
                                <?php if ((string) ($row['local_meta'] ?? '') !== ''): ?>
                                    <div class="small text-muted font-monospace"><?= e((string) $row['local_meta']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?= e((string) ($row['remote'] ?? '')) ?></code>
                                <?php if ((string) ($row['remote_meta'] ?? '') !== ''): ?>
                                    <div class="small text-muted font-monospace"><?= e((string) $row['remote_meta']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3 text-muted small">
            Checked source: <code><?= e((string) ($updateSource['source_label'] ?? '')) ?></code>
            <?php if ((int) ($comparison['local_ahead'] ?? 0) > 0 || (int) ($comparison['remote_ahead'] ?? 0) > 0): ?>
                <span class="ms-2">
                    local ahead: <b><?= e((string) ($comparison['local_ahead'] ?? 0)) ?></b>,
                    source ahead: <b><?= e((string) ($comparison['remote_ahead'] ?? 0)) ?></b>
                </span>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
            <h2 class="mb-0"><?= e(match ($resultOperation) {
                'dry_run' => 'Dry Run Plan',
                'update_now' => 'Update Result',
                default => 'Action Summary',
            }) ?></h2>
            <div class="d-flex gap-2 flex-wrap small">
                <span class="badge text-bg-primary">create: <?= e((string) ($summary['create_count'] ?? 0)) ?></span>
                <span class="badge text-bg-primary">update: <?= e((string) ($summary['update_count'] ?? 0)) ?></span>
                <span class="badge text-bg-primary">delete: <?= e((string) ($summary['delete_count'] ?? 0)) ?></span>
                <span class="badge text-bg-secondary">skip: <?= e((string) ($summary['skip_count'] ?? 0)) ?></span>
                <span class="badge text-bg-warning">overwrite: <?= e((string) ($summary['overwrite_count'] ?? 0)) ?></span>
                <span class="badge text-bg-danger">blocked: <?= e((string) ($summary['blocked_count'] ?? 0)) ?></span>
            </div>
        </div>

        <?php if ($actions === []): ?>
            <p class="text-muted mb-0">No file-level changes to show for this action.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm rvnp-table-static align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Path</th>
                            <th scope="col">Action</th>
                            <th scope="col">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actions as $action): ?>
                            <?php
                            $operation = (string) ($action['operation'] ?? 'skip');
                            $rowClass = match ($operation) {
                                'create' => 'table-primary',
                                'update' => !empty($action['blocked']) ? 'table-warning' : 'table-warning',
                                'delete' => !empty($action['blocked']) ? 'table-danger' : 'table-danger',
                                default => 'table-light',
                            };
                            ?>
                            <tr class="<?= e($rowClass) ?>">
                                <td class="font-monospace"><?= e((string) ($action['path'] ?? '')) ?></td>
                                <td><?= e(ucfirst($operation)) ?></td>
                                <td>
                                    <?= e((string) ($action['detail'] ?? '')) ?>
                                    <?php if (!empty($action['blocked'])): ?>
                                        <div class="small text-danger">Blocked unless overwrite override is enabled.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= Toolbar::render([
    'items' => $updateToolbarItems,
    'class' => 'rvnp-editor-actions',
]) ?>

<?php ob_start(); ?>
  (function () {
    var sourceMode = document.getElementById('update_source_mode');
    if (!(sourceMode instanceof HTMLSelectElement)) {
      return;
    }

    function syncSourceFields() {
      var selected = sourceMode.value;
      var fields = document.querySelectorAll('[data-update-source-field]');
      fields.forEach(function (field) {
        if (!(field instanceof HTMLElement)) {
          return;
        }

        field.style.display = field.getAttribute('data-update-source-field') === selected ? '' : 'none';
      });
    }

    sourceMode.addEventListener('change', syncSourceFields);
    syncSourceFields();
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>
