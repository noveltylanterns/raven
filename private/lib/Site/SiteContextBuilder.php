<?php

declare(strict_types=1);

namespace Raven\Lib\Site;

use Raven\Lib\Config\Config;

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
        string $siteUrl,
        string $currentUrl,
        string $publicTheme,
        string $publicThemeActive,
        string $metaImage
    ): array {
        $themeUrl = $this->themeUrl($siteUrl, $publicThemeActive);

        return array_merge($this->publicMetaBase($config, $publicTheme, $publicThemeActive), [
            'url' => $siteUrl,
            'current_url' => $currentUrl,
            'theme_url' => $themeUrl,
            'meta_image' => $metaImage,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function publicFallback(Config $config, string $publicTheme, string $publicThemeActive): array
    {
        $siteUrl = $this->siteUrlFromConfig($config);

        return array_merge($this->publicMetaBase($config, $publicTheme, $publicThemeActive), [
            'url' => $siteUrl,
            'current_url' => '',
            'theme_url' => $this->themeUrl($siteUrl, $publicThemeActive),
            'meta_image' => $this->defaultMetaImageFromConfig($config),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function publicMetaBase(Config $config, string $publicTheme, string $publicThemeActive): array
    {
        return [
            'name' => (string) $config->get('site.name', 'Raven CMS'),
            'protocol' => $this->siteProtocolFromConfig($config),
            'domain' => (string) $config->get('site.domain', 'localhost'),
            'panel_path' => (string) $config->get('panel.path', 'panel'),
            'apple_touch_icon' => trim((string) $config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $config->get('meta.twitter.creator', '')),
            'og_type' => trim((string) $config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $config->get('meta.opengraph.locale', 'en_US')),
            'theme' => $publicTheme,
            'theme_active' => $publicThemeActive,
        ];
    }

    private function defaultMetaImageFromConfig(Config $config): string
    {
        return trim((string) $config->get('meta.image', ''));
    }

    private function siteProtocolFromConfig(Config $config): string
    {
        $protocol = strtolower(trim((string) $config->get('site.protocol', 'https')));
        return in_array($protocol, ['http', 'https'], true) ? $protocol : 'https';
    }

    private function siteUrlFromConfig(Config $config): string
    {
        $domain = trim((string) $config->get('site.domain', 'localhost'));
        if ($domain === '') {
            $domain = 'localhost';
        }

        $path = '';

        if (str_contains($domain, '://')) {
            $parsedHost = trim((string) parse_url($domain, PHP_URL_HOST));
            $parsedPort = parse_url($domain, PHP_URL_PORT);
            $parsedPath = (string) parse_url($domain, PHP_URL_PATH);
            if ($parsedHost !== '') {
                $domain = $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
                $path = '/' . trim($parsedPath, '/');
                $path = $path === '/' ? '' : $path;
            } else {
                $domain = 'localhost';
            }
        } else {
            if (str_contains($domain, '/')) {
                $parts = explode('/', $domain, 2);
                $domain = (string) ($parts[0] ?? '');
                $path = '/' . trim((string) ($parts[1] ?? ''), '/');
                $path = $path === '/' ? '' : $path;
            }

            $domain = preg_replace('/[\/?#].*$/', '', $domain) ?? $domain;
            $domain = trim($domain);
            if ($domain === '') {
                $domain = 'localhost';
            }
        }

        return $this->siteProtocolFromConfig($config) . '://' . $domain . $path;
    }

    private function themeUrl(string $siteUrl, string $themeCssSlug): string
    {
        $themeCssSlug = trim($themeCssSlug);
        if ($themeCssSlug === '') {
            $themeCssSlug = 'raven';
        }

        return rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeCssSlug);
    }
}
