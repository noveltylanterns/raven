<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/profile/index.php
 * Profile-unavailable placeholder template.
 * Docs: https://lanterns.io/raven
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
{if profile_denied}{redirect:denied}{/if}
{if not profile_denied}{redirect:404}{/if}
