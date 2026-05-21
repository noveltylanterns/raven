<?php

/**
 * RAVEN CMS
 * ~/private/tpl/public/status/404.php
 * Public-facing not-found status template fallback.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    <h1 class="mb-3">Not Found</h1>
    <p>The requested page could not be found.</p>
</section>
