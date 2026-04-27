<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/auth/register.php
 * Public registration template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

use function Raven\Lib\Security\e;

$registrationMode = strtolower(trim((string) ($registrationMode ?? 'closed')));
$registrationClosed = (bool) ($registrationClosed ?? ($registrationMode === 'closed'));
$registrationInvite = (bool) ($registrationInvite ?? ($registrationMode === 'invite'));
$loginIdentifierMode = strtolower(trim((string) ($loginIdentifierMode ?? 'email')));
$usernameRequired = (bool) ($usernameRequired ?? ($loginIdentifierMode === 'username'));
$loginPath = trim((string) ($loginPath ?? '/login'));
$captchaMarkup = is_string($captchaMarkup ?? null) ? $captchaMarkup : '';
$flashSuccess = is_string($flashSuccess ?? null) ? $flashSuccess : null;
$flashError = is_string($flashError ?? null) ? $flashError : null;
?>
<section>
<h1>Register</h1>
<?php if ($flashSuccess !== null && trim($flashSuccess) !== ''): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== null && trim($flashError) !== ''): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if ($registrationClosed): ?>
<div class="alert alert-danger">Registration is currently closed.</div>
<?php endif; ?>

<?php if (!$registrationClosed): ?>
<form method="post" action="/register" novalidate>
    <?= (string) ($csrfField ?? '') ?>
    <?php if ($usernameRequired): ?>
    <div class="mb-3">
        <label class="form-label" for="register_username">Username</label>
        <input id="register_username" name="username" type="text" class="form-control" required>
    </div>
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-label" for="register_display_name">Display Name</label>
        <input id="register_display_name" name="display_name" type="text" class="form-control">
    </div>
    <div class="mb-3">
        <label class="form-label" for="register_email">Email</label>
        <input id="register_email" name="email" type="email" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="register_password">Password</label>
        <input id="register_password" name="password" type="password" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="register_password_confirm">Confirm Password</label>
        <input id="register_password_confirm" name="password_confirm" type="password" class="form-control" required>
    </div>
    <?php if ($registrationInvite): ?>
    <div class="mb-3">
        <label class="form-label" for="register_invite_token">Invite Token</label>
        <input id="register_invite_token" name="invite_token" type="text" class="form-control" required>
    </div>
    <?php endif; ?>
    <?= $captchaMarkup ?>
    <button type="submit" class="btn btn-primary">Create Account</button>
</form>
<?php endif; ?>
<hr>
<p><a href="<?= e($loginPath) ?>">Back to Login</a></p>
</section>
