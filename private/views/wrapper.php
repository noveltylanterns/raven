<?php

/**
 * RAVEN CMS
 * ~/private/views/wrapper.php
 * Shared layout template for rendered views.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Layout wraps child content and shared navigation/theme chrome.

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

/** @var array<string, string> $site */
/** @var string $content */
/** @var array<string, mixed>|null $page */
/** @var array<string, mixed>|null $category */
/** @var array<string, mixed>|null $tag */
/** @var array<string, mixed>|null $profile */
/** @var array<string, mixed>|null $group */
/** @var array<string, mixed>|null $pagination */

use function Raven\Core\Support\e;

$publicThemeCss = trim((string) ($site['public_theme_css'] ?? $site['public_theme'] ?? 'raven'));
if ($publicThemeCss === '') {
    $publicThemeCss = 'raven';
}
$siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
if ($siteName === '') {
    $siteName = 'Raven CMS';
}
$twitterCard = trim((string) ($site['twitter_card'] ?? ''));
$twitterSite = trim((string) ($site['twitter_site'] ?? ''));
if ($twitterSite === '') {
    $twitterSite = $siteName;
}
$twitterCreator = trim((string) ($site['twitter_creator'] ?? ''));
$twitterImage = trim((string) ($site['twitter_image'] ?? ''));
$openGraphImage = trim((string) ($site['og_image'] ?? ''));
$appleTouchIcon = trim((string) ($site['apple_touch_icon'] ?? ''));
$currentUrl = trim((string) ($site['current_url'] ?? ''));
$robots = trim((string) ($site['robots'] ?? ''));
$openGraphType = trim((string) ($site['og_type'] ?? 'website'));
if ($openGraphType === '') {
    $openGraphType = 'website';
}
$openGraphLocale = trim((string) ($site['og_locale'] ?? 'en_US'));
if ($openGraphLocale === '') {
    $openGraphLocale = 'en_US';
}
$viewTitle = '';
$metaDescription = '';
$pageNumber = 1;
if (isset($pagination) && is_array($pagination)) {
    $pageNumber = max(1, (int) ($pagination['current'] ?? 1));
}

if (isset($page) && is_array($page)) {
    $viewTitle = trim((string) ($page['title'] ?? ''));
    $metaDescription = trim((string) ($page['description'] ?? ''));
} elseif (isset($category) && is_array($category)) {
    $categoryName = trim((string) ($category['name'] ?? ''));
    if ($categoryName !== '') {
        $viewTitle = 'Category: ' . $categoryName;
        if ($pageNumber > 1) {
            $viewTitle .= ' (Page ' . $pageNumber . ')';
        }

        $metaDescription = trim((string) ($category['description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = 'Browse pages in category ' . $categoryName . '.';
        }
    }
} elseif (isset($tag) && is_array($tag)) {
    $tagName = trim((string) ($tag['name'] ?? ''));
    if ($tagName !== '') {
        $viewTitle = 'Tag: ' . $tagName;
        if ($pageNumber > 1) {
            $viewTitle .= ' (Page ' . $pageNumber . ')';
        }

        $metaDescription = 'Browse pages tagged ' . $tagName . '.';
    }
} elseif (isset($profile) && is_array($profile)) {
    $profileName = trim((string) ($profile['display_name'] ?? ''));
    if ($profileName === '') {
        $profileName = trim((string) ($profile['username'] ?? ''));
    }
    if ($profileName !== '') {
        $viewTitle = 'Profile: ' . $profileName;
        $metaDescription = 'Public profile for ' . $profileName . '.';
    }
} elseif (isset($group) && is_array($group)) {
    $groupName = trim((string) ($group['name'] ?? ''));
    if ($groupName !== '') {
        $viewTitle = 'Group: ' . $groupName;
        $metaDescription = 'Members in group ' . $groupName . '.';
    }
}

if ($viewTitle === '' && http_response_code() === 404) {
    $viewTitle = 'Not Found';
    if ($metaDescription === '') {
        $metaDescription = 'The requested page could not be found.';
    }
}

$documentTitle = $viewTitle === '' ? $siteName : ($viewTitle . ' [' . $siteName . ']');
$twitterTitle = $documentTitle;
?>
<!doctype html>
<html lang="en">
<head>
    <title><?= e($documentTitle) ?></title>
<?php if ($appleTouchIcon !== ''): ?>
    <link rel="apple-touch-icon" href="<?= e($appleTouchIcon) ?>">
<?php endif; ?>
<?php if ($currentUrl !== ''): ?>
    <link rel="canonical" href="<?= e($currentUrl) ?>">
<?php endif; ?>
    <link rel="icon" type="image/png" href="/theme/<?= e($publicThemeCss) ?>/img/favicon.png">
    <link rel="stylesheet" href="/theme/<?= e($publicThemeCss) ?>/css/style.css">
    <meta charset="utf-8">
<?php if ($metaDescription !== ''): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
<?php if ($robots !== ''): ?>
    <meta name="robots" content="<?= e($robots) ?>">
<?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($metaDescription !== ''): ?>
    <meta property="og:description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
<?php if ($openGraphImage !== ''): ?>
    <meta property="og:image" content="<?= e($openGraphImage) ?>">
<?php endif; ?>
    <meta property="og:locale" content="<?= e($openGraphLocale) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($documentTitle) ?>">
    <meta property="og:type" content="<?= e($openGraphType) ?>">
<?php if ($currentUrl !== ''): ?>
    <meta property="og:url" content="<?= e($currentUrl) ?>">
<?php endif; ?>
<?php if ($twitterCard !== ''): ?>
    <meta property="twitter:card" content="<?= e($twitterCard) ?>">
<?php endif; ?>
<?php if ($twitterCreator !== ''): ?>
    <meta property="twitter:creator" content="<?= e($twitterCreator) ?>">
<?php endif; ?>
<?php if ($metaDescription !== ''): ?>
    <meta property="twitter:description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
<?php if ($twitterImage !== ''): ?>
    <meta property="twitter:image" content="<?= e($twitterImage) ?>">
<?php endif; ?>
<?php if ($twitterSite !== ''): ?>
    <meta property="twitter:site" content="<?= e($twitterSite) ?>">
<?php endif; ?>
    <meta property="twitter:title" content="<?= e($twitterTitle) ?>">
<?php if ($currentUrl !== ''): ?>
    <meta property="twitter:url" content="<?= e($currentUrl) ?>">
<?php endif; ?>
</head>
<body>
<div class="site-shell">
    <header class="site-header">
        <h1 class="h3 mb-1"><?= e($site['name']) ?></h1>
        <p class="site-subtitle">Domain: <?= e($site['domain']) ?></p>
    </header>

    <main>
        <?= $content ?>
    </main>
</div>
<script src="/bootstrap.bundle.min.js"></script>
</body>
</html>
