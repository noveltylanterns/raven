<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/status/404.php
 * Panel-wrapped not-found status template.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Rendered inside the panel wrapper so authenticated users see
// consistent chrome on missing/disabled panel routes.

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section class="card">
    <div class="card-body">
        <h1 class="mb-3">Not Found</h1>
        <p class="text-muted mb-0">The requested page could not be found or is not available.</p>
    </div>
</section>
