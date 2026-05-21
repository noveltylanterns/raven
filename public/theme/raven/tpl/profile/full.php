<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profile/full.php
 * Full public profile template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
{if profile:name}
    <h1>{profile:name}</h1>
{else}
    <h1>Profile</h1>
{/if}

{if profile:username}
    <p class="text-muted">@{profile:username}</p>
{/if}

{if profile:avatar}
    <p>
        <img
            src="{profile:avatar_thumb}"
            onerror="this.onerror=null;this.src='{profile:avatar_full}';"
            alt="{profile:name}"
            class="img-thumbnail"
            style="max-width: 160px; height: auto;"
        >
    </p>
{/if}

{if profile:contacts}
    <ul class="list-unstyled mt-3">
        {each profile:contacts}
        <li>
            {if item:label}
            <strong>{item:label}:</strong>
            {/if}

            {if item:href}
            <a href="{item:href}"{if item:is_external} target="_blank" rel="noopener noreferrer"{/if}>{item:value}</a>
            {/if}
            {if not item:href}
            <span>{item:value}</span>
            {/if}
        </li>
        {/each}
    </ul>
{/if}
</section>
