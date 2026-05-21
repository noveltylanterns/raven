<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profile/limited.php
 * Limited public profile template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
{if profile:name}
    <h1 class="mb-3">{profile:name}</h1>
{else}
    <h1 class="mb-3">Profile</h1>
{/if}
    <p class="text-muted">Limited profile view.</p>
</section>
