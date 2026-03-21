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
{if meta:apple_touch_icon}
    <link rel="apple-touch-icon" href="{meta:apple_touch_icon}">
{/if}
{if site:current_url}
    <link rel="canonical" href="{site:current_url}">
{/if}
    <link rel="icon" type="image/png" href="{site:theme_url}/img/favicon.png">
    <link rel="stylesheet" href="{site:theme_url}/css/style.css">
    <meta charset="utf-8">
{if meta:description}
    <meta name="description" content="{meta:description}">
{/if}
{if meta:robots}
    <meta name="robots" content="{meta:robots}">
{/if}
    <meta name="viewport" content="width=device-width, initial-scale=1">
{if meta:description}
    <meta property="og:description" content="{meta:description}">
{/if}
{if meta:og_image}
    <meta property="og:image" content="{meta:og_image}">
{/if}
    <meta property="og:locale" content="{meta:og_locale}">
    <meta property="og:site_name" content="{site:name}">
    <meta property="og:title" content="{meta:document_title}">
    <meta property="og:type" content="{meta:og_type}">
{if site:current_url}
    <meta property="og:url" content="{site:current_url}">
{/if}
{if meta:x_card}
    <meta property="twitter:card" content="{meta:x_card}">
{/if}
{if meta:x_creator}
    <meta property="twitter:creator" content="{meta:x_creator}">
{/if}
{if meta:description}
    <meta property="twitter:description" content="{meta:description}">
{/if}
{if meta:x_image}
    <meta property="twitter:image" content="{meta:x_image}">
{/if}
{if meta:x_site}
    <meta property="twitter:site" content="{meta:x_site}">
{/if}
    <meta property="twitter:title" content="{meta:document_title}">
{if site:current_url}
    <meta property="twitter:url" content="{site:current_url}">
{/if}
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
