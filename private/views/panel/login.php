<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/login.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
?>
<div class="raven-panel-login-shell">
    <div class="card raven-panel-login-card">
        <div class="card-body">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e($panelBase) ?>/login" novalidate>
                <?= $csrfField ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input id="username" name="username" type="text" class="form-control" required>
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
