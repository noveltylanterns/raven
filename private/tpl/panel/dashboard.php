<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/dashboard.php
 * Admin panel dashboard/stub view template.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $user */
/** @var bool $canManageUsers */
/** @var bool $canManageGroups */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $section */

use Raven\Lib\View\Panel\Header;
use function Raven\Lib\Security\e;

?>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php
$dashboardHeaderConfig = [];
if ($section === 'dashboard') {
    $dashboardHeaderConfig = [
        'title' => 'Dashboard',
        'intro_html' => '<p class="mb-1">Logged in as: <strong>' . e((string) ($user['email'] ?? 'unknown')) . '</strong></p>',
        'summary_html' => 'Welcome to <b>Raven CMS</b>. Use the navigation to browse your system. Full dashboard coming soon.',
        'summary_class' => 'text-muted',
    ];
} else {
    $dashboardHeaderBodyHtml = '';
    if (($section === 'user' && !$canManageUsers) || ($section === 'group' && !$canManageGroups)) {
        $dashboardHeaderBodyHtml = '<p class="text-danger mt-2 mb-0">Manage Users or Manage Groups permission is required for this section.</p>';
    }
    $dashboardHeaderConfig = [
        'title' => $section,
        'summary' => 'This section is scaffolded and will be implemented in the next pass.',
        'body_html' => $dashboardHeaderBodyHtml,
    ];
}
?>
<?= Header::render($dashboardHeaderConfig) ?>
