<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/home.php
 * Public-facing view template for site output.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<article>
{if page:title_show}
    <h1 class="mb-3">{page:title}</h1>
{/if}
{if page:content}
{each page:content}
    <div{if item:css_id} id="{item:css_id}"{/if} class="{item:class} mt-3">{raw:item:html}</div>
{/each}
{/if}
</article>
