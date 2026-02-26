<?php

/**
 * RAVEN CMS
 * ~/private/views/messages/404.php
 * Public-facing not-found message template fallback.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    <h2 class="h4">Not Found</h2>
    <p>The requested page could not be found.</p>
</section>
