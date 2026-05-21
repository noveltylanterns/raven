<?php

/**
 * RAVEN CMS
 * ~/private/tpl/public/status/disabled.php
 * Core fallback site-disabled status template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(503);
    exit('Service Unavailable');
}
?>
<section>
    <h1 class="mb-3">Site Disabled</h1>
    <p>This site is currently disabled.</p>
</section>
