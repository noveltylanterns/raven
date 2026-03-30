<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/status/denied.php
 * Panel-wrapped permission-denied status template.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Rendered inside the panel wrapper so authenticated users see
// consistent chrome when they lack permission for a panel route.

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
    <div class="card-body">
        <h1 class="mb-3">Permission Denied</h1>
        <p class="text-muted mb-0">You do not have permission to access this page.</p>
    </div>
</section>
