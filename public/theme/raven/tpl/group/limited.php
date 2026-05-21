<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/group/limited.php
 * Limited public group template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
{if group:name}
    <h1>Group: {group:name}</h1>
{else}
    <h1>Group</h1>
{/if}

{if group:slug}
    <p class="text-muted">Slug: {group:slug}</p>
{/if}
    <p class="text-muted">{group:count} Users</p>
    <p class="text-muted">Limited group view.</p>
</section>
