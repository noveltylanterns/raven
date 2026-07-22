<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/status/disabled.php
 * Public-facing site-disabled status template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(503);
    exit('Service Unavailable');
}
?>
<section>
    <h1 class="microhead">Site Disabled</h1>
    <p>This site is currently disabled.</p>
</section>
