<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/groups/limited.php
 * Limited public group template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    {if group:name}
    <h2>Group: {group:name}</h2>
    {/if}
    {if not group:name}
    <h2>Group</h2>
    {/if}

    {if group:slug}
    <p class="text-muted mb-2">Slug: {group:slug}</p>
    {/if}
    <p class="text-muted mb-2">{group:member_count} Users</p>
    <p class="text-muted mb-0">Limited group view.</p>
</section>
