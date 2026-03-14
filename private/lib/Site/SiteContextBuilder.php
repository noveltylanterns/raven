<?php

declare(strict_types=1);

namespace Raven\Lib\Site;

use Raven\Core\Config;

/**
 * Shared site-context payload builder for panel/public templates.
 */
final class SiteContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function panel(
        Config $config,
        ?bool $categoryEnabled = null,
        ?bool $tagEnabled = null,
        bool $includeDomain = true
    ): array {
        $site = [
            'name' => (string) $config->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $config->get('panel.path', 'panel'),
            'panel_brand_name' => (string) $config->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $config->get('panel.brand_logo', ''),
        ];

        if ($includeDomain) {
            $site['domain'] = (string) $config->get('site.domain', 'localhost');
        }
        if ($categoryEnabled !== null) {
            $site['category_enabled'] = $categoryEnabled;
        }
        if ($tagEnabled !== null) {
            $site['tag_enabled'] = $tagEnabled;
        }

        return $site;
    }

    /**
     * @return array<string, string>
     */
    public function publicBase(
        Config $config,
        string $currentUrl,
        string $publicTheme,
        string $publicThemeCss,
        string $twitterImage,
        string $ogImage
    ): array {
        return [
            'name' => (string) $config->get('site.name', 'Raven CMS'),
            'domain' => (string) $config->get('site.domain', 'localhost'),
            'panel_path' => (string) $config->get('panel.path', 'panel'),
            'current_url' => $currentUrl,
            'apple_touch_icon' => trim((string) $config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $config->get('meta.twitter.creator', '')),
            'twitter_image' => $twitterImage,
            'og_image' => $ogImage,
            'og_type' => trim((string) $config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $config->get('meta.opengraph.locale', 'en_US')),
            'public_theme' => $publicTheme,
            'public_theme_css' => $publicThemeCss,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function publicFallback(Config $config, string $publicTheme, string $publicThemeCss): array
    {
        return [
            'name' => (string) $config->get('site.name', 'Raven CMS'),
            'domain' => (string) $config->get('site.domain', 'localhost'),
            'panel_path' => (string) $config->get('panel.path', 'panel'),
            'current_url' => '',
            'apple_touch_icon' => trim((string) $config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $config->get('meta.twitter.creator', '')),
            'twitter_image' => trim((string) $config->get('meta.twitter.image', '')),
            'og_image' => trim((string) $config->get('meta.opengraph.image', '')),
            'og_type' => trim((string) $config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $config->get('meta.opengraph.locale', 'en_US')),
            'public_theme' => $publicTheme,
            'public_theme_css' => $publicThemeCss,
        ];
    }
}
