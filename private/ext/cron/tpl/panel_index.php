<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/tpl/panel_index.php
 * Scheduled Tasks extension panel index view.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Scheduled Tasks'));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
$taskCount = 0;
$enabledCount = 0;
$dueCount = 0;
foreach ($tasks as $task) {
    if (trim((string) ($task['slug'] ?? '')) === '' && trim((string) ($task['command'] ?? '')) === '' && trim((string) ($task['label'] ?? '')) === '') {
        continue;
    }
    $taskCount++;
    if (!empty($task['enabled'])) {
        $enabledCount++;
    }
    if (!empty($task['enabled']) && !empty($task['overdue'])) {
        $dueCount++;
    }
}
$formatTimestamp = static function (?int $timestamp): string {
    if (!is_int($timestamp) || $timestamp <= 0) {
        return 'Never';
    }
    return gmdate('Y-m-d H:i', $timestamp) . ' UTC';
};
$taskListId = 'cron-task-list';
$taskAddButtonId = 'cron-task-add';
$taskTemplateId = 'cron-task-template';
?>
<header class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1 class="mb-0"><?= e($extensionName !== '' ? $extensionName : 'Scheduled Tasks') ?></h1>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
        <h5>by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h5>
        <p class="text-muted mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Manage custom jobs that Raven feeds into its built-in scheduler.') ?></p>
    </div>
</header>

<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if (($schedulerMode ?? 'always') === 'off'): ?>
    <div class="alert alert-danger">
        Raven's fallback scheduler is disabled in <a href="<?= e($configurationPath) ?>" class="alert-link">System Configuration</a>. Enable <code>site.scheduler</code> there, or point server cron at <code>php private/bin/rvn-cron run</code> so these scheduled tasks can execute.
    </div>
<?php elseif (!$schedulerAvailable): ?>
    <div class="alert alert-danger">
        Scheduler registry is unavailable on this Raven build. Saved tasks will not execute until core scheduler support is restored.
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <button type="submit" form="cron-settings-form" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Tasks</button>
</div>

<form id="cron-settings-form" method="post" action="<?= e($savePath) ?>">
    <?= $csrfField ?>

    <div class="card mb-3">
        <div class="card-body">
            <h2>Task Registry</h2>
            <p class="small text-muted mb-3">Each row becomes one scheduler job. Raven stores the list in <code><?= e($storagePath) ?></code> and runs commands from the project root through <code>/bin/bash -lc</code>.</p>

            <div class="row g-3 small mb-3">
                <div class="col-md-4"><strong>Saved Tasks</strong><br><?= e((string) $taskCount) ?></div>
                <div class="col-md-4"><strong>Enabled Tasks</strong><br><?= e((string) $enabledCount) ?></div>
                <div class="col-md-4"><strong>Due Now</strong><br><?= e((string) $dueCount) ?></div>
            </div>

            <div class="alert alert-info small">
                <?php if (($schedulerMode ?? 'always') === 'always'): ?>
                    Raven's fallback scheduler is active for both public and panel traffic. A server cron pointed at <code>php private/bin/rvn-cron run</code> is still recommended for low-traffic installs.
                <?php elseif (($schedulerMode ?? 'always') === 'panel'): ?>
                    Raven's fallback scheduler is active on panel traffic only. If panel visits are irregular, point server cron at <code>php private/bin/rvn-cron run</code>.
                <?php else: ?>
                    These tasks will only run when you invoke <code>php private/bin/rvn-cron run</code> manually or from server cron, unless you re-enable <code>site.scheduler</code>.
                <?php endif; ?>
            </div>

            <div id="<?= e($taskListId) ?>">
                <?php foreach ($tasks as $index => $task): ?>
                    <?php
                    $taskSlug = trim((string) ($task['slug'] ?? ''));
                    $taskLabel = trim((string) ($task['label'] ?? ''));
                    $taskCommand = trim((string) ($task['command'] ?? ''));
                    $taskInterval = (int) ($task['interval'] ?? 300);
                    $taskEnabled = !empty($task['enabled']);
                    $lastRun = is_int($task['last_run'] ?? null) ? $task['last_run'] : null;
                    $nextDue = is_int($task['next_due'] ?? null) ? $task['next_due'] : null;
                    $overdue = !empty($task['overdue']);
                    ?>
                    <div class="border rounded p-2 mb-2" data-cron-task-row="1">
                        <div class="row g-2 align-items-end pb-2">
                            <div class="col-md-4">
                                <label class="form-label">Label</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-cron-task-key="label"
                                    name="tasks[<?= (int) $index ?>][label]"
                                    maxlength="120"
                                    value="<?= e($taskLabel) ?>"
                                    placeholder="Nightly cleanup"
                                >
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Job Slug</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-cron-task-key="slug"
                                    name="tasks[<?= (int) $index ?>][slug]"
                                    maxlength="120"
                                    value="<?= e($taskSlug) ?>"
                                    placeholder="nightly-cleanup"
                                >
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Every (seconds)</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    data-cron-task-key="interval"
                                    name="tasks[<?= (int) $index ?>][interval]"
                                    min="1"
                                    step="1"
                                    value="<?= e((string) $taskInterval) ?>"
                                >
                            </div>
                            <div class="col-md-2">
                                <div class="form-check mb-2">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        data-cron-task-key="enabled"
                                        name="tasks[<?= (int) $index ?>][enabled]"
                                        value="1"
                                        <?= $taskEnabled ? ' checked' : '' ?>
                                    >
                                    <label class="form-check-label">Enabled</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md pe-md-0">
                                <label class="form-label">Command</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    data-cron-task-key="command"
                                    name="tasks[<?= (int) $index ?>][command]"
                                    maxlength="4000"
                                    value="<?= e($taskCommand) ?>"
                                    placeholder="php private/bin/rvn-sys extensions"
                                >
                            </div>
                            <div class="col-auto ps-md-0 d-flex align-items-end">
                                <button type="button" class="btn btn-danger ms-2" data-cron-task-remove="1" aria-label="Remove scheduled task row">
                                    <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            <?php if ($taskSlug === '' || $taskCommand === ''): ?>
                                Unsaved row. Leave label or slug blank and Raven will derive the missing side when possible.
                            <?php elseif (!$taskEnabled): ?>
                                Disabled. Scheduler will keep the saved row, but it will not register this job until you enable it again.
                            <?php else: ?>
                                Last run: <?= e($formatTimestamp($lastRun)) ?>.
                                Next due: <?= e($nextDue !== null ? $formatTimestamp($nextDue) : 'Due on first scheduler pass') ?>.
                                <?= $overdue ? 'This task is currently due.' : 'This task is not due yet.' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-primary btn-sm" id="<?= e($taskAddButtonId) ?>">Add Scheduled Task</button>
        </div>
    </div>
</form>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <button type="submit" form="cron-settings-form" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Tasks</button>
</div>

<template id="<?= e($taskTemplateId) ?>">
    <div class="border rounded p-2 mb-2" data-cron-task-row="1">
        <div class="row g-2 align-items-end pb-2">
            <div class="col-md-4">
                <label class="form-label">Label</label>
                <input type="text" class="form-control" data-cron-task-key="label" maxlength="120" value="" placeholder="Nightly cleanup">
            </div>
            <div class="col-md-3">
                <label class="form-label">Job Slug</label>
                <input type="text" class="form-control" data-cron-task-key="slug" maxlength="120" value="" placeholder="nightly-cleanup">
            </div>
            <div class="col-md-3">
                <label class="form-label">Every (seconds)</label>
                <input type="number" class="form-control" data-cron-task-key="interval" min="1" step="1" value="300">
            </div>
            <div class="col-md-2">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" data-cron-task-key="enabled" value="1" checked>
                    <label class="form-check-label">Enabled</label>
                </div>
            </div>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-md pe-md-0">
                <label class="form-label">Command</label>
                <input type="text" class="form-control" data-cron-task-key="command" maxlength="4000" value="" placeholder="php private/bin/rvn-sys extensions">
            </div>
            <div class="col-auto ps-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-cron-task-remove="1" aria-label="Remove scheduled task row">
                    <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="small text-muted mt-2">New task row. Raven will register it after you save.</div>
    </div>
</template>

<script>
  (function () {
    var list = document.getElementById('<?= e($taskListId) ?>');
    var addButton = document.getElementById('<?= e($taskAddButtonId) ?>');
    var template = document.getElementById('<?= e($taskTemplateId) ?>');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-cron-task-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var labelField = row.querySelector('[data-cron-task-key="label"]');
        var slugField = row.querySelector('[data-cron-task-key="slug"]');
        var intervalField = row.querySelector('[data-cron-task-key="interval"]');
        var enabledField = row.querySelector('[data-cron-task-key="enabled"]');
        var commandField = row.querySelector('[data-cron-task-key="command"]');

        if (labelField instanceof HTMLInputElement) {
          labelField.name = 'tasks[' + index + '][label]';
        }
        if (slugField instanceof HTMLInputElement) {
          slugField.name = 'tasks[' + index + '][slug]';
        }
        if (intervalField instanceof HTMLInputElement) {
          intervalField.name = 'tasks[' + index + '][interval]';
        }
        if (enabledField instanceof HTMLInputElement) {
          enabledField.name = 'tasks[' + index + '][enabled]';
        }
        if (commandField instanceof HTMLInputElement) {
          commandField.name = 'tasks[' + index + '][command]';
        }
      });
    }

    function appendRow() {
      var fragment = template.content.cloneNode(true);
      list.appendChild(fragment);
      reindexRows();
    }

    function ensureAtLeastOneRow() {
      if (list.querySelector('[data-cron-task-row="1"]')) {
        return;
      }
      appendRow();
    }

    addButton.addEventListener('click', function () {
      appendRow();
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var removeButton = target.closest('[data-cron-task-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }
      var row = removeButton.closest('[data-cron-task-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }
      row.remove();
      if (!list.querySelector('[data-cron-task-row="1"]')) {
        appendRow();
        return;
      }
      reindexRows();
    });

    reindexRows();
  })();
</script>
