<?php

/**
 * RAVEN CMS
 * ~/private/tpl/login.php
 * Public login helper template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

use function Raven\Core\Support\e;

$panelLoginPath = trim((string) ($panelLoginPath ?? '/panel/login'));
$registrationPath = trim((string) ($registrationPath ?? '/register'));
$registrationMode = strtolower(trim((string) ($registrationMode ?? 'closed')));
$loginIdentifierLabel = trim((string) ($loginIdentifierLabel ?? 'Email'));
$flashSuccess = is_string($flashSuccess ?? null) ? $flashSuccess : null;
$flashError = is_string($flashError ?? null) ? $flashError : null;
?>
<section class="card">
    <div class="card-body">
        <h2 class="h4 mb-3">Login</h2>
        <p class="text-muted">Use this form to access dashboard-enabled accounts.</p>

        <?php if ($flashSuccess !== null && trim($flashSuccess) !== ''): ?>
        <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== null && trim($flashError) !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e($panelLoginPath) ?>" novalidate>
            <?= (string) ($csrfField ?? '') ?>
            <div class="mb-3">
                <label class="form-label" for="identifier"><?= e($loginIdentifierLabel) ?></label>
                <input id="identifier" name="identifier" type="text" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input id="password" name="password" type="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>

        <hr>
        <p class="mb-0">
            Need an account?
            <a href="<?= e($registrationPath) ?>">Register</a>
            <span class="text-muted">(mode: <?= e($registrationMode) ?>)</span>
        </p>
    </div>
</section>
