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

    {if galleryImages}
    <section class="mt-4">
        <div class="row g-3">
            {each galleryImages}
            <div class="col-12 col-md-6 col-lg-4">
                <figure class="mb-0">
                    <a href="{item:full_url}">
                        <img src="{item:image_url}" class="img-fluid rounded border" alt="{item:alt_text}">
                    </a>
                    {if item:caption}
                    <figcaption class="small text-muted mt-2">{item:caption}</figcaption>
                    {/if}
                </figure>
            </div>
            {/each}
        </div>
    </section>
    {/if}
</article>
