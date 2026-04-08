<?php

/**
 * RAVEN CMS
 * ~/private/tpl/auth/login.php
 * Public login helper template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

use function Raven\Support\e;

$loginPath = trim((string) ($loginPath ?? '/login'));
$registrationPath = trim((string) ($registrationPath ?? '/register'));
$registrationMode = strtolower(trim((string) ($registrationMode ?? 'closed')));
$loginIdentifierLabel = trim((string) ($loginIdentifierLabel ?? 'Email'));
$postLoginRedirectPath = trim((string) ($postLoginRedirectPath ?? '/'));
$flashSuccess = is_string($flashSuccess ?? null) ? $flashSuccess : null;
$flashError = is_string($flashError ?? null) ? $flashError : null;
?>
<section>
    <h1 class="mb-3">Login</h1>

    <?php if ($flashSuccess !== null && trim($flashSuccess) !== ''): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== null && trim($flashError) !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($loginPath) ?>" novalidate>
        <?= (string) ($csrfField ?? '') ?>
        <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
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
    <p>Need an account? <a href="<?= e($registrationPath) ?>">Register</a></p>
</section>
