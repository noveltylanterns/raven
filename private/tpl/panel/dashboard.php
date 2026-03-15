<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/dashboard.php
 * Admin panel dashboard/stub view template.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $user */
/** @var bool $canManageUsers */
/** @var bool $canManageGroups */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $section */

use function Raven\Core\Support\e;

?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if ($section === 'dashboard'): ?>
<header class="card">
    <div class="card-body">
        <h1>Dashboard</h1>
        <p class="mb-1">Logged in as: <strong><?= e((string) ($user['email'] ?? 'unknown')) ?></strong></p>
        <p class="text-muted">Welcome to <b>Raven CMS</b>. Use the navigation to browse your system. Full dashboard coming soon.</p>
    </div>
</header>
<?php else: ?>
<header class="card">
    <div class="card-body">
        <h1><?= e($section) ?></h1>
        <p class="text-muted mb-0">This section is scaffolded and will be implemented in the next pass.</p>
        <?php if (($section === 'users' && !$canManageUsers) || ($section === 'groups' && !$canManageGroups)): ?>
        <p class="text-danger mt-2 mb-0">Manage Users or Manage Groups permission is required for this section.</p>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>
