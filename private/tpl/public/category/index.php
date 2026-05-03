<?php

/**
 * RAVEN CMS
 * ~/private/tpl/public/category/index.php
 * Public-facing view template for site output.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    <h1 class="mb-3">Category: {category:name}</h1>

{if pages}
    <ul class="list-group mt-3 mb-3">
        {each pages}
        <li class="list-group-item">
            <a href="{item:url}">{item:title}</a>
        </li>
        {/each}
    </ul>
{/if}
{if not pages}
    <p>No pages found in this category yet.</p>
{/if}

{if pagination:links}
    <nav aria-label="Category pagination">
        <ul class="pagination">
            {each pagination:links}
            {if item:is_current}
            <li class="page-item active"><a class="page-link" href="{item:href}">{item:label}</a></li>
            {/if}
            {if not item:is_current}
            <li class="page-item"><a class="page-link" href="{item:href}">{item:label}</a></li>
            {/if}
            {/each}
        </ul>
    </nav>
{/if}

</section>
