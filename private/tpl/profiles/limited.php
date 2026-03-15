<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profiles/limited.php
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
    <h2 class="h4 mb-2">{profile:display_name_resolved}</h2>
    {/if}
    {if not profile:display_name_resolved}
    <h2 class="h4 mb-2">Profile</h2>
    {/if}
    <p class="text-muted mb-0">Limited profile view.</p>
</section>
