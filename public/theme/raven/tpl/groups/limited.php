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
    <h1>Group: {group:name}</h1>
{/if}
{if not group:name}
    <h1>Group</h1>
{/if}

{if group:slug}
    <p class="text-muted">Slug: {group:slug}</p>
{/if}
    <p class="text-muted">{group:member_count} Users</p>
    <p class="text-muted">Limited group view.</p>
</section>
