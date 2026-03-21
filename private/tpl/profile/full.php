<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profile/full.php
 * Full public profile template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
{if profile:display_name_resolved}
    <h1 class="mb-3">{profile:display_name_resolved}</h1>
{/if}
{if not profile:display_name_resolved}
    <h1 class="mb-3">Profile</h1>
{/if}

{if profile:username}
    <p class="text-muted">@{profile:username}</p>
{/if}

{if profile:has_avatar}
    <p>
        <img
            src="{profile:avatar_thumb_url}"
            onerror="this.onerror=null;this.src='{profile:avatar_url}';"
            alt="{profile:display_name_resolved}"
            class="img-thumbnail"
            style="max-width: 160px; height: auto;"
        >
    </p>
{/if}

{if profile:contact_profiles}
    <ul class="list-unstyled mt-3">
        {each profile:contact_profiles}
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
