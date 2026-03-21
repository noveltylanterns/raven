<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/status/404.php
 * Public-facing not-found status template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    <h1>Not Found</h1>
    <p>The requested page could not be found.</p>
</section>
