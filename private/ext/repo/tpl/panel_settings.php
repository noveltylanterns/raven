<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/tpl/panel_settings.php
 * Repositories extension global settings view.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Lib\View\Panel\Header;
use Raven\Lib\View\Panel\Toolbar;
use function Raven\Lib\Security\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Repositories'));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
$repoSettingsHeaderActions = [];
if ($extensionDocsUrl !== '') {
    $repoSettingsHeaderActions[] = '<a href="' . e($extensionDocsUrl) . '" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">'
        . '<i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation'
        . '</a>';
}
$repoSettingsToolbarItems = [
    '<button type="submit" form="repoSettingsForm" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Settings</button>',
    '<a href="' . e($logsPath) . '" class="btn btn-primary"><i class="bi bi-clipboard2-pulse me-2" aria-hidden="true"></i>View Logs</a>',
    '<a href="' . e($indexPath) . '" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Repos</a>',
];
?>
<?= Header::render([
    'title' => $extensionName !== '' ? $extensionName : 'Repositories',
    'title_class' => 'mb-0',
    'subheading_html' => 'by ' . e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown'),
    'summary' => $extensionDescription !== '' ? $extensionDescription : 'Mirror Git repositories into Raven with optional public browsing.',
    'actions' => $repoSettingsHeaderActions,
    'card_class' => 'card mb-3',
]) ?>

<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if (($schedulerMode ?? 'always') === 'off'): ?>
    <div class="alert alert-danger">
        Raven's fallback scheduler is disabled in <a href="<?= e($configurationPath) ?>" class="alert-link">System Configuration</a>. Enable <code>site.scheduler</code> there, or point server cron at <code>php private/bin/rvn-cron run</code> so repository auto-updates can execute.
    </div>
<?php elseif (!$schedulerAvailable): ?>
    <div class="alert alert-danger">
        Scheduler-backed auto-update execution is unavailable on this Raven build. Repository auto-updates will not run until core scheduler support is restored.
    </div>
<?php endif; ?>

<?= Toolbar::render([
    'items' => $repoSettingsToolbarItems,
    'tag' => 'div',
    'class' => 'd-flex flex-wrap justify-content-end gap-2 mb-3',
]) ?>

<div class="card mb-3">
    <div class="card-body">
        <h3>Global Settings</h3>
        <form method="post" action="<?= e($settingsPath) ?>" id="repoSettingsForm">
            <?= $csrfField ?>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="repo_default_visibility" class="form-label">Default visibility</label>
                        <select class="form-select" id="repo_default_visibility" name="default_visibility">
                            <?php foreach ($visibilityOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string) ($settings['default_visibility'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="repo_update_frequency" class="form-label">Default update frequency</label>
                        <select class="form-select" id="repo_update_frequency" name="update_frequency">
                            <?php foreach ($frequencyOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string) ($settings['update_frequency'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="repo_auto_update_enabled" name="auto_update_enabled" value="1"<?= !empty($settings['auto_update_enabled']) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="repo_auto_update_enabled">Enable auto-update intent globally</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="repo_log_prune_days" class="form-label">Log pruning lifecycle (days)</label>
                        <input type="number" class="form-control" id="repo_log_prune_days" name="log_prune_days" min="1" max="3650" value="<?= e((string) ($settings['log_prune_days'] ?? 30)) ?>">
                    </div>
                    <h5>Enabled log events</h5>
                    <?php foreach ($logEventOptions as $event => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="repo_log_event_<?= e($event) ?>" name="log_events[<?= e($event) ?>]" value="1"<?= !empty($settings['log_events'][$event]) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="repo_log_event_<?= e($event) ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<?= Toolbar::render([
    'items' => $repoSettingsToolbarItems,
    'tag' => 'div',
    'class' => 'd-flex flex-wrap justify-content-end gap-2 mb-3',
]) ?>
