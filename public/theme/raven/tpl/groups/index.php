<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/groups/index.php
 * Group-route unavailable placeholder template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
{if group_denied}{redirect:denied}{/if}
{if not group_denied}{redirect:404}{/if}
