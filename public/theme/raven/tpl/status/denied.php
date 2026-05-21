<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/status/denied.php
 * Public-facing permission-denied status template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section>
    <h1>Permission Denied</h1>
    <p>You do not have permission to access this page.</p>
</section>
