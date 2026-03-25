<?php

/**
 * RAVEN CMS
 * ~/private/tpl/feeds/atom.php
 * Core fallback Atom feed template.
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
<feed xmlns="http://www.w3.org/2005/Atom">
    <title><?= $xml((string) ($feed['title'] ?? 'Raven Atom Feed')) ?></title>
    <id><?= $xml((string) ($feed['url'] ?? '')) ?></id>
    <link href="<?= $xml((string) ($feed['site_url'] ?? ($site['url'] ?? ''))) ?>" rel="alternate" />
    <link href="<?= $xml((string) ($feed['url'] ?? '')) ?>" rel="self" />
    <updated><?= $xml((string) ($feed['updated_atom'] ?? gmdate(DATE_ATOM))) ?></updated>
    <generator>Raven CMS</generator>
    <subtitle><?= $xml((string) ($feed['description'] ?? 'Latest pages.')) ?></subtitle>
<?php foreach ($items as $item): ?>
    <?php if (!is_array($item)) { continue; } ?>
    <entry>
        <title><?= $xml((string) ($item['feed_title'] ?? 'Untitled')) ?></title>
        <id><?= $xml((string) ($item['absolute_url'] ?? '')) ?></id>
        <link href="<?= $xml((string) ($item['absolute_url'] ?? '')) ?>" />
        <updated><?= $xml((string) ($item['atom_published_at'] ?? gmdate(DATE_ATOM))) ?></updated>
        <published><?= $xml((string) ($item['atom_published_at'] ?? gmdate(DATE_ATOM))) ?></published>
<?php if (trim((string) ($item['feed_description'] ?? '')) !== ''): ?>
        <summary><?= $xml((string) ($item['feed_description'] ?? '')) ?></summary>
<?php endif; ?>
    </entry>
<?php endforeach; ?>
</feed>
