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

$siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
if ($siteName === '') {
    $siteName = 'Raven CMS';
}
$metaTitle = trim((string) ($meta['title'] ?? ''));
$documentTitle = $metaTitle === '' ? $siteName : ($metaTitle . ' [' . $siteName . ']');
?>
<!doctype html>
<html lang="en">
<head>

<title>{meta:title} [{site:name}]</title>
<link rel="canonical" href="{meta:url}">
<link rel="icon" type="image/png" href="{theme:url}/img/favicon.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{meta:desc}">
<meta name="robots" content="{meta:robots}">
<meta property="og:description" content="{meta:desc}">
<meta property="og:image" content="{meta:image}">
<meta property="og:locale" content="{meta:og_locale}">
<meta property="og:site_name" content="{site:name}">
<meta property="og:title" content="{meta:title} [{site:name}]">
<meta property="og:type" content="{meta:og_type}">
<meta property="og:url" content="{meta:url}">
<meta property="twitter:card" content="{meta:x_card}">
<meta property="twitter:creator" content="{meta:x_creator}">
<meta property="twitter:description" content="{meta:desc}">
<meta property="twitter:image" content="{meta:image}">
<meta property="twitter:site" content="{meta:x_site}">
<meta property="twitter:title" content="{meta:title} [{site:name}]">
<meta property="twitter:url" content="{meta:url}">
<link rel="apple-touch-icon" href="{meta:apple_touch_icon}">
<link rel="stylesheet" href="{theme:url}/css/style.css">

</head>
<body>

<nav class="navbar bg-dark" data-bs-theme="dark">
    <div class="container">
        <a href="{site:url}" class="navbar-brand">
            <img src="{theme:url}/img/logo-white.png" alt="{site:name}" height="30" class="d-inline-block align-text-top">
            {site:name}
        </a>
    </div>
</nav>

<main class="container">
{raw:content}
</main>

<script src="/bootstrap.bundle.min.js"></script>
</body>
</html>
