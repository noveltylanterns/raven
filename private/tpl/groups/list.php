<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/groups/list.php
 * Public group member list template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<section>
    {if group:name}
    <h2 class="h4 mb-2">Group: {group:name}</h2>
    {/if}
    {if not group:name}
    <h2 class="h4 mb-2">Group</h2>
    {/if}

    {if group:slug}
    <p class="text-muted mb-2">Slug: {group:slug}</p>
    {/if}
    <p class="text-muted mb-3">{group:member_count_resolved} Users</p>

    {if members}
    <ul class="list-group">
        {each members}
        <li class="list-group-item d-flex align-items-center gap-2">
            {if item:has_avatar}
            <img
                src="{item:avatar_thumb_url}"
                onerror="this.onerror=null;this.src='{item:avatar_url}';"
                alt="{item:display_name_resolved}"
                width="32"
                height="32"
                class="rounded-circle"
            >
            {/if}
            <div>
                {if item:display_name_resolved}
                <div>{item:display_name_resolved}</div>
                {/if}
                {if not item:display_name_resolved}
                <div>User</div>
                {/if}

                {if item:username}
                <small class="text-muted">@{item:username}</small>
                {/if}
            </div>
        </li>
        {/each}
    </ul>
    {/if}
    {if not members}
    <p class="mb-0">No members are assigned to this group.</p>
    {/if}
</section>
