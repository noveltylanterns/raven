<?php

/**
 * RAVEN CMS
 * ~/private/tpl/public/feeds/rss.php
 * Core fallback RSS feed template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

$feed = is_array($feed ?? null) ? $feed : [];
$items = is_array($feed['items'] ?? null) ? $feed['items'] : [];
$xml = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= $xml((string) ($feed['title'] ?? 'Raven RSS Feed')) ?></title>
    <link><?= $xml((string) ($feed['site_url'] ?? ($site['url'] ?? ''))) ?></link>
    <description><?= $xml((string) ($feed['description'] ?? 'Latest pages.')) ?></description>
    <generator>Raven CMS</generator>
    <lastBuildDate><?= $xml((string) ($feed['updated_rss'] ?? gmdate(DATE_RSS))) ?></lastBuildDate>
    <atom:link href="<?= $xml((string) ($feed['url'] ?? '')) ?>" rel="self" type="application/rss+xml" />
<?php foreach ($items as $item): ?>
    <?php if (!is_array($item)) { continue; } ?>
    <item>
        <title><?= $xml((string) ($item['feed_title'] ?? 'Untitled')) ?></title>
        <link><?= $xml((string) ($item['absolute_url'] ?? '')) ?></link>
        <guid isPermaLink="true"><?= $xml((string) ($item['absolute_url'] ?? '')) ?></guid>
        <pubDate><?= $xml((string) ($item['rss_published_at'] ?? gmdate(DATE_RSS))) ?></pubDate>
<?php if (trim((string) ($item['feed_description'] ?? '')) !== ''): ?>
        <description><?= $xml((string) ($item['feed_description'] ?? '')) ?></description>
<?php endif; ?>
    </item>
<?php endforeach; ?>
</channel>
</rss>
