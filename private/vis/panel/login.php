<?php

/**
 * RAVEN CMS
 * ~/private/vis/panel/login.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $error */
/** @var string|null $loginIdentifierMode */
/** @var string|null $loginIdentifierLabel */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$loginIdentifierMode = in_array(strtolower((string) ($loginIdentifierMode ?? 'email')), ['email', 'username'], true)
    ? strtolower((string) ($loginIdentifierMode ?? 'email'))
    : 'email';
$loginIdentifierLabel = trim((string) ($loginIdentifierLabel ?? ''));
if ($loginIdentifierLabel === '') {
    $loginIdentifierLabel = $loginIdentifierMode === 'email' ? 'Email' : 'Username';
}
$loginIdentifierInputType = $loginIdentifierMode === 'email' ? 'email' : 'text';
?>
<div class="rvnp-login-shell">
    <div class="card rvnp-login-card">
        <div class="card-body">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e($panelBase) ?>/login" novalidate>
                <?= $csrfField ?>
                <div class="mb-3">
                    <label for="identifier" class="form-label"><?= e($loginIdentifierLabel) ?></label>
                    <input id="identifier" name="identifier" type="<?= e($loginIdentifierInputType) ?>" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input id="password" name="password" type="password" class="form-control" required>
                </div>
                <div class="text-center">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>
        </div>
    </div>
</div>
