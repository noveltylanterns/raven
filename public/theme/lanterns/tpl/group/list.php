<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/group/list.php
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
    <h1>Group: {group:name}</h1>
{else}
    <h1>Group</h1>
{/if}

{if group:slug}
    <p class="text-muted">Slug: {group:slug}</p>
{/if}
    <p class="text-muted">{group:count} Users</p>

{if members}
    <ul class="list-group">
        {each members}
        <li class="list-group-item d-flex align-items-center gap-2">
            {if item:avatar}
            <img
                src="{item:avatar_thumb}"
                onerror="this.onerror=null;this.src='{item:avatar_full}';"
                alt="{item:name}"
                width="32"
                height="32"
                class="rounded-circle"
            >
            {/if}
            <div>
                {if item:name}
                <div>{item:name}</div>
                {else}
                <div>User</div>
                {/if}

                {if item:username}
                <small class="text-muted">@{item:username}</small>
                {/if}
            </div>
        </li>
        {/each}
    </ul>
{else}
    <p>No members are assigned to this group.</p>
{/if}
</section>
