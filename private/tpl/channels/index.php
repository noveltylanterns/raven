<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/channels/index.php
 * Public-facing channel landing template for site output.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<article>
    {if page:display_title_resolved}
    <h2>{page:title}</h2>
    {/if}
    {if page:channel_slug}
    <p class="text-muted small mb-0">Channel: {page:channel_slug}</p>
    {/if}

    <div>{raw:page:content}</div>

    {if page:extended_blocks}
    {each page:extended_blocks}
    <div{if item:css_id} id="{item:css_id}"{/if} class="{item:class}">{raw:item:html}</div>
    {/each}
    {/if}
</article>
