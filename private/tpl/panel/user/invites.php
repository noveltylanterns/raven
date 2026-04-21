<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/user/invites.php
 * Admin panel view template for registration invite tokens.
 * Docs: https://raven.lanterns.io
 */

/** @var array<string, string> $site */
/** @var array<int, array{id: int, token: string, hint: string, reusable: int, uses: int, expires: int|null, last_used: int|null, created: string, creator: int|null}> $inviteRows */
/** @var array<int, array{label: string, edit_url: string}> $inviteCreatorMap */
/** @var array<int, string>|null $inviteGeneratedTokens */
/** @var string $inviteRegistrationMode */
/** @var int $inviteNowTs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$inviteRows = is_array($inviteRows ?? null) ? $inviteRows : [];
$inviteCreatorMap = is_array($inviteCreatorMap ?? null) ? $inviteCreatorMap : [];
$inviteGeneratedTokens = is_array($inviteGeneratedTokens ?? null) ? $inviteGeneratedTokens : null;
$inviteRegistrationMode = strtolower(trim((string) ($inviteRegistrationMode ?? 'closed')));
if (!in_array($inviteRegistrationMode, ['open', 'invite', 'closed'], true)) {
    $inviteRegistrationMode = 'closed';
}
$inviteNowTs = max(0, (int) ($inviteNowTs ?? time()));
$formatTimestamp = static function (?int $value): string {
    if (!is_int($value) || $value <= 0) {
        return 'Never';
    }

    return gmdate('Y-m-d H:i:s', $value) . ' UTC';
};
$invitesSearchId = 'invites-filter-search';
$invitesLifespanFilterId = 'invites-filter-lifespan';
$invitesTypeFilterId = 'invites-filter-type';
$invitesTableId = 'invites-table';
$invitesBodyId = $invitesTableId . '-body';
$invitesCountId = 'invites-filter-count';
$invitesEmptyId = 'invites-filter-empty';
?>

<?= Header::render([
    'title' => 'Invite Tokens',
    'summary' => 'Create and manage registration invite tokens for public signups.',
]) ?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if ($inviteGeneratedTokens !== null): ?>
<section class="card">
    <div class="card-body">
        <h2 class="h5 mb-3">Generated Tokens</h2>
        <p class="text-muted mb-2">Click a token to copy.</p>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($inviteGeneratedTokens as $generatedToken): ?>
                <?php $generatedToken = trim((string) $generatedToken); ?>
                <?php if ($generatedToken === ''): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <code
                    class="d-block"
                    role="button"
                    tabindex="0"
                    data-invite-copy="1"
                    data-invite-copy-value="<?= e($generatedToken) ?>"
                    title="Click to copy"
                ><?= e($generatedToken) ?></code>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <p class="mb-3">
            Registration mode:
            <span class="badge text-bg-secondary"><?= e(strtoupper($inviteRegistrationMode)) ?></span>
        </p>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <h2 class="h5">Create Token</h2>
                <form method="post" action="<?= e($panelBase) ?>/user/invites/create">
                    <?= $csrfField ?>
                    <div class="form-group">
                        <label class="form-label" for="invite_type">Token Type</label>
                        <select class="form-select" id="invite_type" name="invite_type" required>
                            <option value="single">Single-use</option>
                            <option value="reusable">Reusable</option>
                        </select>
                    </div>
                    <div class="form-group" id="invite_token_slug_wrap">
                        <label class="form-label" for="invite_token_slug">Token Slug (Optional For Single-use)</label>
                        <input class="form-control" id="invite_token_slug" name="token_slug" type="text" maxlength="64" placeholder="Leave blank for random token">
                        <div class="form-text">If left blank, a random token is generated.</div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="invite_expires_at">Expires At (Optional)</label>
                        <input class="form-control" id="invite_expires_at" name="expires_at" type="datetime-local">
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-ticket-perforated me-2" aria-hidden="true"></i>Create Token</button>
                    </div>
                </form>
            </div>
            <div class="col-12 col-lg-6">
                <h2 class="h5">Generate Single-use Batch</h2>
                <form method="post" action="<?= e($panelBase) ?>/user/invites/generate">
                    <?= $csrfField ?>
                    <div class="form-group">
                        <label class="form-label" for="generate_count">Count</label>
                        <input class="form-control" id="generate_count" name="count" type="number" min="1" max="100" value="10" required>
                        <div class="form-text">Generate 1-100 randomized single-use tokens.</div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="generate_expires_at">Expires At (Optional)</label>
                        <input class="form-control" id="generate_expires_at" name="expires_at" type="datetime-local">
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-list-check me-2" aria-hidden="true"></i>Generate Tokens</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-body">
        <h2 class="h5 mb-3">Existing Tokens</h2>
        <?php if ($inviteRows === []): ?>
            <p class="text-muted mb-0">No invite tokens found.</p>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-0" for="<?= e($invitesSearchId) ?>">Search</label>
                    <input
                        id="<?= e($invitesSearchId) ?>"
                        type="search"
                        class="form-control form-control-sm"
                        placeholder="Filter by token code or user id..."
                    >
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-0" for="<?= e($invitesLifespanFilterId) ?>">Lifespan</label>
                    <select id="<?= e($invitesLifespanFilterId) ?>" class="form-select form-select-sm">
                        <option value="">All Lifespans</option>
                        <option value="expires">Expires</option>
                        <option value="immortal">Immortal</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-0" for="<?= e($invitesTypeFilterId) ?>">Type</label>
                    <select id="<?= e($invitesTypeFilterId) ?>" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="single-use">Single-use</option>
                        <option value="reusable">Reusable</option>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2" id="<?= e($invitesCountId) ?>"></div>
            <div class="table-responsive">
                <table id="<?= e($invitesTableId) ?>" class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="text-center" style="width: 1%;"><span class="visually-hidden">State</span></th>
                            <th scope="col">Token Code</th>
                            <th scope="col">Type</th>
                            <th scope="col">Generated By</th>
                            <th scope="col" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="<?= e($invitesBodyId) ?>">
                        <?php foreach ($inviteRows as $inviteRow): ?>
                            <?php
                            $inviteId = (int) ($inviteRow['id'] ?? 0);
                            $detailsId = 'invite-token-details-' . $inviteId;
                            $tokenValue = trim((string) ($inviteRow['token'] ?? ''));
                            $tokenHint = trim((string) ($inviteRow['hint'] ?? ''));
                            $legacyTokenOnly = false;
                            if ($tokenValue === '' && $tokenHint !== '') {
                                $tokenValue = $tokenHint . '...';
                                $legacyTokenOnly = true;
                            }
                            $isReusable = (int) ($inviteRow['reusable'] ?? 0) === 1;
                            $useCount = max(0, (int) ($inviteRow['uses'] ?? 0));
                            $expiresAt = isset($inviteRow['expires']) ? (int) $inviteRow['expires'] : null;
                            $lastUsedAt = isset($inviteRow['last_used']) ? (int) $inviteRow['last_used'] : null;
                            $createdAt = trim((string) ($inviteRow['created'] ?? ''));
                            $createdByUserId = isset($inviteRow['creator']) ? (int) $inviteRow['creator'] : null;
                            $creatorLabel = '-';
                            $creatorEditUrl = '';
                            if ($createdByUserId !== null && $createdByUserId > 0) {
                                $creatorMapRow = $inviteCreatorMap[$createdByUserId] ?? null;
                                if (is_array($creatorMapRow)) {
                                    $mappedLabel = trim((string) ($creatorMapRow['label'] ?? ''));
                                    $mappedEditUrl = trim((string) ($creatorMapRow['edit_url'] ?? ''));
                                    if ($mappedLabel !== '') {
                                        $creatorLabel = $mappedLabel;
                                    }
                                    if ($mappedEditUrl !== '') {
                                        $creatorEditUrl = $mappedEditUrl;
                                    }
                                } else {
                                    $creatorLabel = 'User #' . $createdByUserId;
                                }
                            }
                            $isExpired = is_int($expiresAt) && $expiresAt > 0 && $expiresAt <= $inviteNowTs;
                            $isUsedSingle = !$isReusable && $useCount > 0;
                            $lifespanKey = is_int($expiresAt) && $expiresAt > 0 ? 'expires' : 'immortal';
                            $statusLabel = $isExpired ? 'Expired' : ($isUsedSingle ? 'Used' : 'Active');
                            $statusClass = $isExpired
                                ? 'text-bg-secondary'
                                : ($isUsedSingle ? 'text-bg-warning' : 'text-bg-success');
                            $searchableText = implode(' ', [
                                $tokenValue,
                                $tokenHint,
                                $createdByUserId !== null && $createdByUserId > 0 ? (string) $createdByUserId : '',
                                $createdByUserId !== null && $createdByUserId > 0 ? ('userid ' . $createdByUserId) : '',
                                $createdByUserId !== null && $createdByUserId > 0 ? ('user id ' . $createdByUserId) : '',
                            ]);
                            ?>
                            <tr
                                data-summary-row="1"
                                data-details-id="<?= e($detailsId) ?>"
                                data-filter-search="<?= e($searchableText) ?>"
                                data-lifespan="<?= e($lifespanKey) ?>"
                                tabindex="0"
                                role="button"
                                aria-expanded="false"
                                aria-controls="<?= e($detailsId) ?>"
                                style="cursor: pointer;"
                            >
                                <td class="text-center">
                                    <i class="bi bi-chevron-down js-row-state-icon" aria-hidden="true"></i>
                                </td>
                                <td>
                                    <?php if ($tokenValue !== '' && !$legacyTokenOnly): ?>
                                        <code
                                            role="button"
                                            tabindex="0"
                                            data-invite-copy="1"
                                            data-invite-copy-value="<?= e($tokenValue) ?>"
                                            title="Click to copy"
                                        ><?= e($tokenValue) ?></code>
                                    <?php else: ?>
                                        <code><?= e($tokenValue !== '' ? $tokenValue : '(unavailable)') ?></code>
                                    <?php endif; ?>
                                    <?php if ($legacyTokenOnly): ?>
                                        <div class="small text-muted">Legacy row (full token not stored previously).</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $isReusable ? 'Reusable' : 'Single-use' ?></td>
                                <td>
                                    <?php if ($creatorEditUrl !== '' && $creatorLabel !== '-'): ?>
                                        <a href="<?= e($creatorEditUrl) ?>"><?= e($creatorLabel) ?></a>
                                    <?php else: ?>
                                        <?= e($creatorLabel) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" action="<?= e($panelBase) ?>/user/invites/delete" class="d-inline m-0" onsubmit="return confirm('Delete this invite token?');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="id" value="<?= $inviteId ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Token" aria-label="Delete Token">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            <span class="visually-hidden">Delete Token</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <tr data-details-row-for="<?= e($detailsId) ?>">
                                <td colspan="5" class="p-0 border-0">
                                    <div
                                        id="<?= e($detailsId) ?>"
                                        class="collapse js-invite-details"
                                        data-bs-parent="#<?= e($invitesBodyId) ?>"
                                    >
                                        <div class="p-3 mb-0 border-bottom">
                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <h5 class="h6 mb-2">Token Details</h5>
                                                    <div class="small mb-1"><strong>Status:</strong> <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
                                                    <div class="small mb-1"><strong>Uses:</strong> <?= $useCount ?></div>
                                                    <div class="small mb-1"><strong>Expires:</strong> <?= e($formatTimestamp(is_int($expiresAt) && $expiresAt > 0 ? $expiresAt : null)) ?></div>
                                                    <div class="small mb-1"><strong>Last Used:</strong> <?= e($formatTimestamp(is_int($lastUsedAt) && $lastUsedAt > 0 ? $lastUsedAt : null)) ?></div>
                                                    <div class="small mb-0"><strong>Created:</strong> <?= e($createdAt !== '' ? ($createdAt . ' UTC') : 'Unknown') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($invitesEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No invite tokens match the current filter.</p>
        <?php endif; ?>
    </div>
</section>

<?php ob_start(); ?>
  #<?= e($invitesTableId) ?> tbody tr[data-details-row-for]:hover > td {
    background-color: transparent !important;
  }
<?php Footer::pushStyle((string) ob_get_clean()); ?>

<?php ob_start(); ?>
(function () {
    var typeField = document.getElementById('invite_type');
    var tokenWrap = document.getElementById('invite_token_slug_wrap');
    var tokenField = document.getElementById('invite_token_slug');
    var table = document.getElementById('<?= e($invitesTableId) ?>');
    var searchInput = document.getElementById('<?= e($invitesSearchId) ?>');
    var lifespanFilter = document.getElementById('<?= e($invitesLifespanFilterId) ?>');
    var typeFilter = document.getElementById('<?= e($invitesTypeFilterId) ?>');
    var countLabel = document.getElementById('<?= e($invitesCountId) ?>');
    var emptyMessage = document.getElementById('<?= e($invitesEmptyId) ?>');
    var summaryRows = [];

    if (typeField instanceof HTMLSelectElement && tokenWrap instanceof HTMLElement && tokenField instanceof HTMLInputElement) {
        function syncManualField() {
            var isSingle = typeField.value === 'single';
            tokenWrap.style.display = isSingle ? '' : 'none';
            tokenField.disabled = !isSingle;
            if (!isSingle) {
                tokenField.value = '';
            }
        }

        typeField.addEventListener('change', syncManualField);
        syncManualField();
    }

    function hasBootstrapCollapse() {
        return !!(window.bootstrap && typeof window.bootstrap.Collapse === 'function');
    }

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function setRowState(row, isExpanded) {
        row.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        var stateIcon = row.querySelector('.js-row-state-icon');
        if (stateIcon instanceof HTMLElement) {
            stateIcon.classList.toggle('bi-chevron-up', isExpanded);
            stateIcon.classList.toggle('bi-chevron-down', !isExpanded);
        }
    }

    function closeDetailsPanel(panel) {
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        if (hasBootstrapCollapse()) {
            window.bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).hide();
            return;
        }

        panel.classList.remove('show');
    }

    function showCopyTooltip(element, copied) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        var originalTitle = String(element.getAttribute('data-copy-title') || element.getAttribute('title') || 'Click to copy');
        element.setAttribute('data-copy-title', originalTitle);
        var message = copied ? 'Copied!' : 'Copy failed';

        if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
            element.setAttribute('title', message);
            window.setTimeout(function () {
                element.setAttribute('title', originalTitle);
            }, 900);
            return;
        }

        var tooltip = window.bootstrap.Tooltip.getOrCreateInstance(element, {
            trigger: 'manual',
            placement: 'top',
            title: originalTitle
        });
        if (typeof tooltip.setContent === 'function') {
            tooltip.setContent({ '.tooltip-inner': message });
        } else {
            element.setAttribute('data-bs-original-title', message);
        }
        tooltip.show();

        window.setTimeout(function () {
            if (typeof tooltip.setContent === 'function') {
                tooltip.setContent({ '.tooltip-inner': originalTitle });
            } else {
                element.setAttribute('data-bs-original-title', originalTitle);
            }
            tooltip.hide();
        }, 900);
    }

    async function copyTextValue(value) {
        var text = String(value || '').trim();
        if (text === '') {
            return false;
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (error) {
                // Fall back to legacy copy path.
            }
        }

        var fallback = document.createElement('textarea');
        fallback.value = text;
        fallback.setAttribute('readonly', 'readonly');
        fallback.style.position = 'absolute';
        fallback.style.left = '-9999px';
        document.body.appendChild(fallback);
        fallback.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } finally {
            document.body.removeChild(fallback);
        }
        return copied;
    }

    if (table instanceof HTMLTableElement && searchInput instanceof HTMLInputElement) {
        var rows = table.tBodies.length > 0 ? Array.prototype.slice.call(table.tBodies[0].rows) : [];
        summaryRows = rows.filter(function (row) {
            return row.getAttribute('data-summary-row') === '1';
        });
        var totalRows = summaryRows.length;

        var applyFilters = function () {
            var query = normalize(searchInput.value);
            var selectedLifespan = '';
            if (lifespanFilter instanceof HTMLSelectElement) {
                selectedLifespan = normalize(lifespanFilter.value);
            }
            var selectedType = '';
            if (typeFilter instanceof HTMLSelectElement) {
                selectedType = normalize(typeFilter.value);
            }
            var visibleRows = 0;

            summaryRows.forEach(function (row) {
                var haystack = normalize(row.getAttribute('data-filter-search') || '');
                var rowLifespan = normalize(row.getAttribute('data-lifespan'));
                var rowTypeCell = row.cells.length > 2 ? row.cells[2] : null;
                var rowType = normalize(rowTypeCell instanceof HTMLTableCellElement ? rowTypeCell.textContent : '');
                var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
                var matchesLifespan = selectedLifespan === '' || rowLifespan === selectedLifespan;
                var matchesType = selectedType === '' || rowType === selectedType;
                var visible = matchesQuery && matchesLifespan && matchesType;
                var detailsId = String(row.getAttribute('data-details-id') || '');
                var detailsRow = detailsId === '' ? null : table.querySelector('tr[data-details-row-for="' + detailsId + '"]');

                row.classList.toggle('d-none', !visible);
                if (detailsRow) {
                    detailsRow.classList.toggle('d-none', !visible);
                }

                if (!visible && detailsRow) {
                    var detailsPanel = detailsRow.querySelector('.js-invite-details');
                    closeDetailsPanel(detailsPanel);
                    setRowState(row, false);
                }

                if (visible) {
                    visibleRows += 1;
                }
            });

            if (countLabel instanceof HTMLElement) {
                countLabel.textContent = 'Showing ' + visibleRows + ' of ' + totalRows + ' tokens on this page.';
            }

            if (emptyMessage instanceof HTMLElement) {
                emptyMessage.classList.toggle('d-none', visibleRows > 0);
            }
        };

        searchInput.addEventListener('input', applyFilters);
        if (lifespanFilter instanceof HTMLSelectElement) {
            lifespanFilter.addEventListener('change', applyFilters);
        }
        if (typeFilter instanceof HTMLSelectElement) {
            typeFilter.addEventListener('change', applyFilters);
        }
        applyFilters();
    } else if (countLabel instanceof HTMLElement) {
        countLabel.textContent = 'Showing <?= (int) count($inviteRows) ?> of <?= (int) count($inviteRows) ?> tokens on this page.';
    }

    function bindBootstrapCollapseStateSync() {
        if (!hasBootstrapCollapse()) {
            return;
        }

        summaryRows.forEach(function (row) {
            var detailsId = String(row.getAttribute('data-details-id') || '');
            if (detailsId === '') {
                return;
            }

            var detailsPanel = document.getElementById(detailsId);
            if (!(detailsPanel instanceof HTMLElement)) {
                return;
            }

            if (detailsPanel.getAttribute('data-rvn-collapse-sync') === '1') {
                return;
            }

            detailsPanel.setAttribute('data-rvn-collapse-sync', '1');
            detailsPanel.addEventListener('show.bs.collapse', function () {
                setRowState(row, true);
            });
            detailsPanel.addEventListener('hide.bs.collapse', function () {
                setRowState(row, false);
            });
        });
    }

    summaryRows.forEach(function (row) {
        var detailsId = String(row.getAttribute('data-details-id') || '');
        if (detailsId === '') {
            return;
        }

        var detailsPanel = document.getElementById(detailsId);
        if (!(detailsPanel instanceof HTMLElement)) {
            return;
        }

        var togglePanel = function () {
            if (hasBootstrapCollapse()) {
                bindBootstrapCollapseStateSync();
                window.bootstrap.Collapse.getOrCreateInstance(detailsPanel, { toggle: false }).toggle();
                return;
            }

            detailsPanel.classList.toggle('show');
            setRowState(row, detailsPanel.classList.contains('show'));
        };

        row.addEventListener('click', function (event) {
            var target = event.target;
            if (target instanceof Element && target.closest('a, button, input, select, textarea, label, form, [data-invite-copy="1"]')) {
                return;
            }
            togglePanel();
        });

        row.addEventListener('keydown', function (event) {
            if (!(event instanceof KeyboardEvent)) {
                return;
            }
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            event.preventDefault();
            togglePanel();
        });
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var copyTarget = target.closest('[data-invite-copy="1"]');
        if (!(copyTarget instanceof HTMLElement)) {
            return;
        }

        var value = String(copyTarget.getAttribute('data-invite-copy-value') || '').trim();
        if (value === '') {
            return;
        }

        void copyTextValue(value).then(function (copied) {
            showCopyTooltip(copyTarget, copied);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (!(event instanceof KeyboardEvent)) {
            return;
        }
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var copyTarget = target.closest('[data-invite-copy="1"]');
        if (!(copyTarget instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        var value = String(copyTarget.getAttribute('data-invite-copy-value') || '').trim();
        if (value === '') {
            return;
        }
        void copyTextValue(value).then(function (copied) {
            showCopyTooltip(copyTarget, copied);
        });
    });
})();
<?php Footer::pushScript((string) ob_get_clean()); ?>
