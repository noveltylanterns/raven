<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/pages/index.php
 * Public-facing view template for site output.
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

    <div>{raw:page:content}</div>

    {if page:extended_blocks}
    {each page:extended_blocks}
    <div{if item:css_id} id="{item:css_id}"{/if} class="{item:class}">{raw:item:html}</div>
    {/each}
    {/if}
</article>
