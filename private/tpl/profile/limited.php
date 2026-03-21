<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profile/limited.php
 * Limited public profile template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
{if profile:display_name_resolved}
    <h1 class="mb-3">{profile:display_name_resolved}</h1>
{/if}
{if not profile:display_name_resolved}
    <h1 class="mb-3">Profile</h1>
{/if}
    <p class="text-muted">Limited profile view.</p>
</section>
