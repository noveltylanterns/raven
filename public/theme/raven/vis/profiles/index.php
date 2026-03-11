<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/vis/profiles/index.php
 * Profile-unavailable placeholder template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
{if profile_show_denied}
<section>
    <h2 class="h4">Permission Denied</h2>
    <p>You do not have permission to access this page.</p>
</section>
{/if}
{if not profile_show_denied}
<section>
    <h2 class="h4">Not Found</h2>
    <p>The requested page could not be found.</p>
</section>
{/if}
