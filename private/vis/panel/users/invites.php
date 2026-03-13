<?php

/**
 * RAVEN CMS
 * ~/private/vis/panel/users/invites.php
 * Admin panel view template for registration invite tokens.
 * Docs: https://raven.lanterns.io
 */

/** @var array<string, string> $site */
/** @var array<int, array{id: int, token_hint: string, is_reusable: int, use_count: int, expires_at: int|null, last_used_at: int|null, created_at: string, created_by_user_id: int|null}> $inviteRows */
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
        <p class="text-muted mb-2">Copy these now. Full token values are shown once.</p>
        <pre class="mb-0 p-3 border rounded bg-light-subtle"><?= e(implode("\n", $inviteGeneratedTokens)) ?></pre>
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
                            <th>ID</th>
                            <th>Token Hint</th>
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
                            $tokenHint = trim((string) ($inviteRow['token_hint'] ?? ''));
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
                                <td><?= $inviteId ?></td>
                                <td><code><?= e($tokenHint !== '' ? ($tokenHint . '…') : '(hidden)') ?></code></td>
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
