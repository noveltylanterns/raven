<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/channel/index.php
 * Public-facing channel landing template for site output.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<article>
{if page:title_show}
    <h1>{page:title}</h1>
{/if}
{if channel:slug}
    <p class="text-muted small">Channel: {channel:slug}</p>
{/if}
{if page:content}
{each page:content}
    <div{if item:css_id} id="{item:css_id}"{/if} class="{item:class}">{raw:item:html}</div>
{/each}
{/if}
</article>
