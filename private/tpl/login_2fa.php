<?php

/**
 * RAVEN CMS
 * ~/private/tpl/login_2fa.php
 * Public two-factor verification helper template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

use function Raven\Core\Support\e;

$verifyPath = trim((string) ($verifyPath ?? '/login/2fa'));
$loginPath = trim((string) ($loginPath ?? '/login'));
$methodLabel = trim((string) ($methodLabel ?? 'Verification Code'));
$flashSuccess = is_string($flashSuccess ?? null) ? $flashSuccess : null;
$flashError = is_string($flashError ?? null) ? $flashError : null;
?>
<section class="card">
    <div class="card-body">
        <h2 class="h4 mb-3">Two-Factor Verification</h2>

        <?php if ($flashSuccess !== null && trim($flashSuccess) !== ''): ?>
        <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== null && trim($flashError) !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e($verifyPath) ?>" novalidate>
            <?= (string) ($csrfField ?? '') ?>
            <div class="mb-3">
                <label class="form-label" for="verification_code"><?= e($methodLabel) ?></label>
                <input id="verification_code" name="verification_code" type="text" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Verify</button>
        </form>

        <hr>
        <p class="mb-0"><a href="<?= e($loginPath) ?>">Back to Login</a></p>
    </div>
</section>
