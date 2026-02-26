<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/views/messages/disabled.php
 * Public-facing site-disabled message template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(503);
    exit('Service Unavailable');
}
?>
<section>
    <h2 class="h4">Site Disabled</h2>
    <p>This site is currently disabled.</p>
</section>
