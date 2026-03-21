<?php

/**
 * RAVEN CMS
 * ~/public/theme/raven/tpl/wrapper.php
 * Shared layout template for rendered views.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}
?>
<!doctype html>
<html lang="en">
<head>
<title>{meta:document_title}</title>
<link rel="icon" type="image/png" href="{theme:url}/img/favicon.png">
<link rel="stylesheet" href="{theme:url}/css/style.css">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{meta:apple_touch_icon}
{meta:canonical}
{if meta:desc}<meta name="description" content="{meta:desc}">
{/if}
{meta:robots}
{if meta:desc}<meta property="og:description" content="{meta:desc}">
{/if}
{if meta:image}<meta property="og:image" content="{meta:image}">
{/if}
{meta:og_locale}
<meta property="og:site_name" content="{site:name}">
<meta property="og:title" content="{meta:document_title}">
{meta:og_type}
{meta:og_url}
{meta:x_card}
{meta:x_creator}
{if meta:desc}<meta property="twitter:description" content="{meta:desc}">
{/if}
{if meta:image}<meta property="twitter:image" content="{meta:image}">
{/if}
{meta:x_site}
<meta property="twitter:title" content="{meta:document_title}">
{meta:x_url}

</head>
<body>

<header>
    <h1><a href="{site:url}" title="{site:name}">{site:name}</a></h1>
</header>

<main class="container">
{raw:content}
</main>

<script src="/bootstrap.bundle.min.js"></script>
</body>
</html>
