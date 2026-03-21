<?php

/**
 * RAVEN CMS
 * ~/private/tpl/wrapper.php
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
<link rel="canonical" href="{meta:url}">
<link rel="icon" type="image/png" href="{site:url}/theme/raven/img/favicon.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{meta:desc}">
<meta name="robots" content="{meta:robots}">
<meta property="og:description" content="{meta:desc}">
<meta property="og:image" content="{meta:image}">
<meta property="og:locale" content="{meta:og_locale}">
<meta property="og:site_name" content="{site:name}">
<meta property="og:title" content="{meta:document_title}">
<meta property="og:type" content="{meta:og_type}">
<meta property="og:url" content="{meta:url}">
<meta property="twitter:card" content="{meta:x_card}">
<meta property="twitter:creator" content="{meta:x_creator}">
<meta property="twitter:description" content="{meta:desc}">
<meta property="twitter:image" content="{meta:image}">
<meta property="twitter:site" content="{meta:x_site}">
<meta property="twitter:title" content="{meta:document_title}">
<meta property="twitter:url" content="{meta:url}">
<link rel="apple-touch-icon" href="{meta:apple_touch_icon}">
<link rel="stylesheet" href="{site:url}/theme/fallback.css">

</head>
<body>

<header>
    <h1><a href="{site:url}" title="{site:name}">{site:name}</a></h1>
</header>

<main class="container">
{raw:content}
</main>

</body>
</html>
