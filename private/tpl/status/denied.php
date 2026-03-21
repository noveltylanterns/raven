<?php

/**
 * RAVEN CMS
 * ~/private/tpl/status/denied.php
 * Public-facing permission-denied status template fallback.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section>
    <h1 class="mb-3">Permission Denied</h1>
    <p>You do not have permission to access this page.</p>
</section>
