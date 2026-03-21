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
    <h1 class="mb-3">Group: {group:name}</h1>
{/if}
{if not group:name}
    <h1 class="mb-3">Group</h1>
{/if}

{if group:slug}
    <p class="text-muted">Slug: {group:slug}</p>
{/if}
    <p class="text-muted">{group:member_count} Users</p>

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
    <p>No members are assigned to this group.</p>
{/if}
</section>
