<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/messages/denied.php
 * Public-facing permission-denied message template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section>
    <h2 class="h4">Permission Denied</h2>
    <p>You do not have permission to access this page.</p>
</section>
