<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/groups/index.php
 * Group-route unavailable placeholder template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
{if group_denied}
<section>
    <h2>Permission Denied</h2>
    <p>You do not have permission to access this page.</p>
</section>
{/if}
{if not group_denied}
<section>
    <h2>Not Found</h2>
    <p>The requested page could not be found.</p>
</section>
{/if}
