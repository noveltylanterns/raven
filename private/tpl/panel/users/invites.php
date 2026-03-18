<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/users/invites.php
 * Admin panel view template for registration invite tokens.
 * Docs: https://raven.lanterns.io
 */

/** @var array<string, string> $site */
/** @var array<int, array{id: int, token: string, token_hint: string, is_reusable: int, use_count: int, expires_at: int|null, last_used_at: int|null, created_at: string, created_by_user_id: int|null}> $inviteRows */
/** @var array<int, string>|null $inviteGeneratedTokens */
/** @var string $inviteRegistrationMode */
/** @var int $inviteNowTs */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$inviteRows = is_array($inviteRows ?? null) ? $inviteRows : [];
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
?>

<header class="card">
    <div class="card-body">
        <h1>User Invites</h1>
        <p class="text-muted mb-0">Create and manage registration invite tokens for public signups.</p>
    </div>
</header>

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
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm text-start font-monospace"
                    data-invite-copy="1"
                    data-invite-copy-value="<?= e($generatedToken) ?>"
                    title="Click to copy"
                ><?= e($generatedToken) ?></button>
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
                <form method="post" action="<?= e($panelBase) ?>/users/invites/create">
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
                <form method="post" action="<?= e($panelBase) ?>/users/invites/generate">
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
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Type</th>
                            <th>Uses</th>
                            <th>Expires</th>
                            <th>Last Used</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inviteRows as $inviteRow): ?>
                            <?php
                            $inviteId = (int) ($inviteRow['id'] ?? 0);
                            $tokenValue = trim((string) ($inviteRow['token'] ?? ''));
                            $tokenHint = trim((string) ($inviteRow['token_hint'] ?? ''));
                            $legacyTokenOnly = false;
                            if ($tokenValue === '' && $tokenHint !== '') {
                                $tokenValue = $tokenHint . '...';
                                $legacyTokenOnly = true;
                            }
                            $isReusable = (int) ($inviteRow['is_reusable'] ?? 0) === 1;
                            $useCount = max(0, (int) ($inviteRow['use_count'] ?? 0));
                            $expiresAt = isset($inviteRow['expires_at']) ? (int) $inviteRow['expires_at'] : null;
                            $lastUsedAt = isset($inviteRow['last_used_at']) ? (int) $inviteRow['last_used_at'] : null;
                            $createdAt = trim((string) ($inviteRow['created_at'] ?? ''));
                            $isExpired = is_int($expiresAt) && $expiresAt > 0 && $expiresAt <= $inviteNowTs;
                            $isUsedSingle = !$isReusable && $useCount > 0;
                            $statusLabel = $isExpired ? 'Expired' : ($isUsedSingle ? 'Used' : 'Active');
                            $statusClass = $isExpired
                                ? 'text-bg-secondary'
                                : ($isUsedSingle ? 'text-bg-warning' : 'text-bg-success');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($tokenValue !== '' && !$legacyTokenOnly): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm font-monospace"
                                            data-invite-copy="1"
                                            data-invite-copy-value="<?= e($tokenValue) ?>"
                                            title="Click to copy"
                                        ><?= e($tokenValue) ?></button>
                                    <?php else: ?>
                                        <code><?= e($tokenValue !== '' ? $tokenValue : '(unavailable)') ?></code>
                                    <?php endif; ?>
                                    <?php if ($legacyTokenOnly): ?>
                                        <div class="small text-muted">Legacy row (full token not stored previously).</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $isReusable ? 'Reusable' : 'Single-use' ?></td>
                                <td><?= $useCount ?></td>
                                <td><?= e($formatTimestamp(is_int($expiresAt) && $expiresAt > 0 ? $expiresAt : null)) ?></td>
                                <td><?= e($formatTimestamp(is_int($lastUsedAt) && $lastUsedAt > 0 ? $lastUsedAt : null)) ?></td>
                                <td><?= e($createdAt !== '' ? ($createdAt . ' UTC') : 'Unknown') ?></td>
                                <td><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td class="text-center">
                                    <form method="post" action="<?= e($panelBase) ?>/users/invites/delete" onsubmit="return confirm('Delete this invite token?');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="id" value="<?= $inviteId ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Token" aria-label="Delete Token">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var typeField = document.getElementById('invite_type');
    var tokenWrap = document.getElementById('invite_token_slug_wrap');
    var tokenField = document.getElementById('invite_token_slug');
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

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var copyButton = target.closest('[data-invite-copy="1"]');
        if (!(copyButton instanceof HTMLButtonElement)) {
            return;
        }

        var value = String(copyButton.getAttribute('data-invite-copy-value') || '').trim();
        if (value === '') {
            return;
        }

        void copyTextValue(value).then(function (copied) {
            showCopyTooltip(copyButton, copied);
        });
    });
})();
</script>
