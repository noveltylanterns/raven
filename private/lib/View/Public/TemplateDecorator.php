<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/TemplateDecorator.php
 * Public-template payload decoration helpers for public views.
 * Docs: https://lanterns.io/raven
*/

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Lib\Media\AvatarConfig;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Pagination;

/**
 * Shared template-facing payload decoration helpers for public views.
 */
final class TemplateDecorator
{
    private Config $config;
    private InputSanitizer $input;
    private AvatarConfig $avatarConfig;

    /**
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request sanitizer.
     * @param string $projectRoot Absolute project root for media-path resolution.
     * @return void
     */
    public function __construct(Config $config, InputSanitizer $input, string $projectRoot)
    {
        unset($projectRoot);
        $this->config = $config;
        $this->input = $input;
        $this->avatarConfig = new AvatarConfig($config);
    }

    /**
     * Decorates one page-list row set with template-ready URLs.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    public function decoratePageListForTemplate(array $pages): array
    {
        // Normalize each list row independently so malformed rows are skipped safely.
        foreach ($pages as $index => $page) {
            // Ignore malformed page entries that are not associative arrays.
            if (!is_array($page)) {
                continue;
            }

            $path = trim((string) ($page['url'] ?? ''));
            // Derive fallback URL path from slug/channel fields when explicit URL is absent.
            if ($path === '') {
                $slug = $this->input->slug((string) ($page['slug'] ?? ''));
                $channelPath = trim((string) ($page['channel_path'] ?? ''), '/');
                $channelSlug = $this->input->slug((string) ($page['channel_slug'] ?? ''));
                // Root pages with missing slugs resolve to site root.
                if ($slug === null || $slug === '') {
                    $path = '/';
                } elseif ($channelSlug === null || $channelSlug === '') {
                    $path = '/' . rawurlencode($slug);
                } elseif ($channelPath !== '') {
                    $segments = array_values(array_filter(
                        explode('/', $channelPath),
                        static fn (string $segment): bool => $segment !== ''
                    ));
                    // Encode hierarchy segments separately so fallback links preserve parent identity.
                    $path = '/' . implode('/', array_map(
                        static fn (string $segment): string => rawurlencode($segment),
                        $segments
                    )) . '/' . rawurlencode($slug);
                } else {
                    $path = '/' . rawurlencode($channelSlug) . '/' . rawurlencode($slug);
                }
            }

            // Fallback URLs must follow the same site-wide slash policy as controller-built links.
            $pages[$index]['url'] = PagePolicy::canonicalPath(
                $path,
                ChannelPolicy::siteRoutingUsesTrailingSlash($this->config)
            );
        }

        return $pages;
    }

    /**
     * Decorates pagination data with template-facing link metadata.
     *
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    public function decoratePaginationForTemplate(array $pagination): array
    {
        return Pagination::decorateTemplateLinks($pagination);
    }

    /**
     * Normalizes one page payload for public theme templates.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function decoratePageForTemplate(array $page): array
    {
        unset($page['content']);

        $page['desc'] = trim((string) ($page['description'] ?? ''));
        unset($page['description']);

        $page['title_show'] = !array_key_exists('display_title', $page)
            || (int) ($page['display_title'] ?? 1) === 1;
        unset($page['show_title']);

        // Keep legacy channel field normalized to a non-negative integer id.
        if (array_key_exists('channel', $page)) {
            $channelId = (int) ($page['channel'] ?? 0);
            $page['channel'] = max(0, $channelId);
        }

        $rawBlocks = is_array($page['content_blocks'] ?? null) ? $page['content_blocks'] : [];
        $renderedBlocks = [];
        $displayIndex = 0;

        // Process each rendered block row while preserving order for template output.
        foreach ($rawBlocks as $block) {
            // Ignore malformed block entries.
            if (!is_array($block)) {
                continue;
            }

            $html = trim((string) ($block['html'] ?? ''));
            // Skip blocks that rendered to empty HTML.
            if ($html === '') {
                continue;
            }

            $displayIndex++;
            $classNames = [
                'raven-page-content-block',
                'raven-page-content-block-' . $displayIndex,
            ];

            // Add spacing class to every block after the first visible block.
            if ($displayIndex > 1) {
                array_unshift($classNames, 'mt-3');
            }

            $customClass = trim((string) ($block['css_class'] ?? ''));
            // Preserve caller-provided custom classes when present.
            if ($customClass !== '') {
                $classNames[] = $customClass;
            }

            $renderedBlocks[] = [
                'html' => $html,
                'css_id' => trim((string) ($block['css_id'] ?? '')),
                'class' => trim(implode(' ', $classNames)),
            ];
        }

        unset($page['content_blocks']);
        $page['content'] = $renderedBlocks;
        return $page;
    }

    /**
     * Normalizes gallery-image payloads for public theme templates.
     *
     * @param array<int, array<string, mixed>> $galleryImages
     * @return array<int, array<string, mixed>>
     */
    public function decorateGalleryImagesForTemplate(array $galleryImages): array
    {
        // Normalize each gallery image row independently.
        foreach ($galleryImages as $index => $image) {
            // Ignore malformed image rows.
            if (!is_array($image)) {
                continue;
            }

            $variants = is_array($image['variants'] ?? null) ? $image['variants'] : [];
            $imageUrl = trim((string) (($variants['md']['url'] ?? '') ?: ($image['url'] ?? '')));
            $fullUrl = trim((string) (($variants['lg']['url'] ?? '') ?: $imageUrl));

            $galleryImages[$index]['image_url'] = $imageUrl;
            $galleryImages[$index]['full_url'] = $fullUrl;
            $galleryImages[$index]['alt_text'] = (string) ($image['alt_text'] ?? '');
            $galleryImages[$index]['caption'] = (string) ($image['caption'] ?? '');
        }

        return $galleryImages;
    }

    /**
     * Normalizes one public profile payload for theme templates.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function decorateProfileForTemplate(array $profile): array
    {
        $displayName = trim((string) ($profile['name'] ?? ''));
        $username = $this->publicTemplateUsername((string) ($profile['username'] ?? ''));
        $profile['name'] = $displayName !== '' ? $displayName : $username;
        $profile['username'] = $username;

        $avatar = $this->avatarTemplateDataFromPath((string) ($profile['avatar'] ?? ''));
        $profile['avatar_filename'] = $avatar['filename'];
        $profile['avatar_full'] = $avatar['url'];
        $profile['avatar_thumb'] = $avatar['thumb_url'];
        $profile['avatar'] = $avatar['filename'] !== '';

        $contacts = is_array($profile['contact'] ?? null) ? $profile['contact'] : [];
        $contactValues = [];
        // Normalize contact rows and collect first value per contact type.
        foreach ($contacts as $index => $contact) {
            // Ignore malformed contact rows.
            if (!is_array($contact)) {
                continue;
            }

            $href = trim((string) ($contact['href'] ?? ''));
            $contacts[$index]['is_external'] = preg_match('#^https?://#i', $href) === 1;

            $type = trim((string) ($contact['type'] ?? ''));
            // Preserve the first non-empty value for each contact type key.
            if ($type !== '' && !array_key_exists($type, $contactValues)) {
                $contactValues[$type] = trim((string) ($contact['value'] ?? ''));
            }
        }
        $profile['contacts'] = $contacts;
        $profile['contact'] = $contactValues;

        unset(
            $profile['display_name_resolved'],
            $profile['avatar_url'],
            $profile['avatar_thumb_url'],
            $profile['has_avatar'],
            $profile['contact']
        );

        return $profile;
    }

    /**
     * Normalizes one public group-member row set for theme templates.
     *
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, mixed>>
     */
    public function decorateGroupMembersForTemplate(array $members): array
    {
        // Normalize each member row independently.
        foreach ($members as $index => $member) {
            // Ignore malformed member rows.
            if (!is_array($member)) {
                continue;
            }

            $displayName = trim((string) ($member['name'] ?? ''));
            $username = $this->publicTemplateUsername((string) ($member['username'] ?? ''));
            $members[$index]['name'] = $displayName !== '' ? $displayName : $username;
            $members[$index]['username'] = $username;

            $avatar = $this->avatarTemplateDataFromPath((string) ($member['avatar'] ?? ''));
            $members[$index]['avatar_filename'] = $avatar['filename'];
            $members[$index]['avatar_full'] = $avatar['url'];
            $members[$index]['avatar_thumb'] = $avatar['thumb_url'];
            $members[$index]['avatar'] = $avatar['filename'] !== '';

            unset(
                $members[$index]['display_name_resolved'],
                $members[$index]['avatar_url'],
                $members[$index]['avatar_thumb_url'],
                $members[$index]['has_avatar']
            );
        }

        return $members;
    }

    /**
     * Normalizes one public group payload for theme templates.
     *
     * @param array<string, mixed> $group
     * @param array<int, array<string, mixed>> $members
     * @return array<string, mixed>
     */
    public function decorateGroupForTemplate(array $group, array $members): array
    {
        $group['count'] = max(count($members), (int) ($group['member_count'] ?? 0));
        unset($group['member_count']);
        return $group;
    }

    /**
     * Finalizes one public render payload into theme-template-ready data.
     *
     * @param array<string, mixed> $data
     * @param int $statusCode HTTP status code for the current render.
     * @return array<string, mixed>
     */
    public function decorateTemplateData(array $data, int $statusCode): array
    {
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];

        $siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
        // Enforce non-empty site name for downstream metadata defaults.
        if ($siteName === '') {
            $siteName = 'Raven CMS';
        }
        $site['name'] = $siteName;

        $themeData = is_array($data['theme'] ?? null) ? $data['theme'] : [];

        $theme = trim((string) ($site['theme'] ?? $themeData['slug'] ?? 'raven'));
        // Theme slug fallback prevents empty theme references in templates.
        if ($theme === '') {
            $theme = 'raven';
        }

        $themeActive = trim((string) ($site['theme_active'] ?? $themeData['active'] ?? $theme));
        // Active theme fallback prevents missing stylesheet owner slugs.
        if ($themeActive === '') {
            $themeActive = 'raven';
        }

        $siteUrl = trim((string) ($site['url'] ?? ''));
        $themeUrl = trim((string) ($site['theme_url'] ?? $themeData['url'] ?? ''));
        // Build theme asset URL lazily when absent but site URL is known.
        if ($themeUrl === '' && $siteUrl !== '') {
            $themeUrl = rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeActive);
        }
        $data['theme'] = [
            'slug' => $theme,
            'active' => $themeActive,
            'url' => $themeUrl,
        ];

        $panelData = is_array($data['panel'] ?? null) ? $data['panel'] : [];
        $panelSlug = trim((string) ($site['panel_path'] ?? $panelData['slug'] ?? 'panel'), '/');
        $panelUrl = trim((string) ($panelData['url'] ?? ''));
        // Build panel URL lazily when absent but site URL is known.
        if ($panelUrl === '' && $siteUrl !== '') {
            $panelUrl = $panelSlug === '' ? $siteUrl : (rtrim($siteUrl, '/') . '/' . $panelSlug);
        }
        $data['panel'] = [
            'slug' => $panelSlug,
            'url' => $panelUrl,
        ];

        $data['redirect'] = [
            '404' => '__RVN_TEMPLATE_REDIRECT__:status/404',
            'disabled' => '__RVN_TEMPLATE_REDIRECT__:status/disabled',
            'denied' => '__RVN_TEMPLATE_REDIRECT__:status/denied',
        ];

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $meta['apple_touch_icon'] = trim((string) ($site['apple_touch_icon'] ?? ''));
        $meta['robots'] = trim((string) ($site['robots'] ?? ''));
        $meta['image'] = trim((string) ($site['meta_image'] ?? ''));
        $meta['og_type'] = trim((string) ($site['og_type'] ?? ''));
        // Default OpenGraph type maintains valid social metadata when unset.
        if ($meta['og_type'] === '') {
            $meta['og_type'] = 'website';
        }
        $meta['og_locale'] = trim((string) ($site['og_locale'] ?? ''));
        // Default locale keeps metadata complete when unset.
        if ($meta['og_locale'] === '') {
            $meta['og_locale'] = 'en_US';
        }
        $meta['x_card'] = trim((string) ($site['twitter_card'] ?? ''));
        $meta['x_creator'] = trim((string) ($site['twitter_creator'] ?? ''));
        $meta['x_site'] = trim((string) ($site['twitter_site'] ?? ''));
        // Fall back x_site to site name when no account handle is configured.
        if ($meta['x_site'] === '') {
            $meta['x_site'] = $siteName;
        }
        $meta['url'] = $this->normalizedMetaUrl((string) ($site['current_url'] ?? ''));

        unset(
            $site['apple_touch_icon'],
            $site['meta_image'],
            $site['robots'],
            $site['og_type'],
            $site['og_locale'],
            $site['twitter_card'],
            $site['twitter_creator'],
            $site['twitter_site'],
            $site['theme'],
            $site['theme_active'],
            $site['theme_url'],
            $site['panel_path']
        );

        // Normalize legacy desc/description fields across common resource payloads.
        foreach (['page', 'category', 'tag', 'channel'] as $root) {
            // Skip entries that are not array payloads.
            if (!is_array($data[$root] ?? null)) {
                continue;
            }

            $data[$root] = $this->normalizeDescTemplateField($data[$root]);
        }

        $viewTitle = '';
        $metaDescription = '';
        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        $pageNumber = max(1, (int) ($pagination['current'] ?? 1));

        // Choose title/description from the first matching view context payload.
        if (is_array($data['page'] ?? null)) {
            $viewTitle = trim((string) ($data['page']['title'] ?? ''));
            $metaDescription = trim((string) ($data['page']['desc'] ?? ''));
        } elseif (is_array($data['category'] ?? null)) {
            $categoryName = trim((string) ($data['category']['name'] ?? ''));
            // Category metadata is generated only when category name exists.
            if ($categoryName !== '') {
                $viewTitle = 'Category: ' . $categoryName;
                // Append page marker for paginated category listings.
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['category']['desc'] ?? ''));
                // Use generic description fallback when category description is blank.
                if ($metaDescription === '') {
                    $metaDescription = 'Browse pages in category ' . $categoryName . '.';
                }
            }
        } elseif (is_array($data['tag'] ?? null)) {
            $tagName = trim((string) ($data['tag']['name'] ?? ''));
            // Tag metadata is generated only when tag name exists.
            if ($tagName !== '') {
                $viewTitle = 'Tag: ' . $tagName;
                // Append page marker for paginated tag listings.
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['tag']['desc'] ?? ''));
                // Use generic description fallback when tag description is blank.
                if ($metaDescription === '') {
                    $metaDescription = 'Browse pages tagged ' . $tagName . '.';
                }
            }
        } elseif (is_array($data['profile'] ?? null)) {
            $profileName = trim((string) ($data['profile']['name'] ?? ''));
            // Fallback to username when display name is absent.
            if ($profileName === '') {
                $profileName = trim((string) ($data['profile']['username'] ?? ''));
            }
            // Profile metadata is emitted only when an identity label exists.
            if ($profileName !== '') {
                $viewTitle = 'Profile: ' . $profileName;
                $metaDescription = 'Public profile for ' . $profileName . '.';
            }
        } elseif (is_array($data['group'] ?? null)) {
            $groupName = trim((string) ($data['group']['name'] ?? ''));
            // Group metadata is emitted only when group name exists.
            if ($groupName !== '') {
                $viewTitle = 'Group: ' . $groupName;
                $metaDescription = 'Members in group ' . $groupName . '.';
            }
        }

        // Provide stock 404 metadata when route did not supply context-specific values.
        if ($viewTitle === '' && $statusCode === 404) {
            $viewTitle = 'Not Found';
            // Ensure 404 metadata description is never empty.
            if ($metaDescription === '') {
                $metaDescription = 'The requested page could not be found.';
            }
        }

        $meta['title'] = $viewTitle;
        $meta['desc'] = $metaDescription;

        $data['site'] = $site;
        $data['meta'] = $meta;

        return $data;
    }

    /**
     * Normalizes one meta canonical URL by trimming trailing root slashes.
     *
     * @param string $url Candidate absolute URL.
     * @return string Normalized URL string.
     */
    private function normalizedMetaUrl(string $url): string
    {
        $url = trim($url);
        // Empty canonical URL input resolves to an empty output string.
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        // Non-parseable URLs fall back to trailing-slash-normalized raw value.
        if (!is_array($parts)) {
            return rtrim($url, '/');
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = trim((string) ($parts['host'] ?? ''));
        // Hostless parse results fall back to raw trailing-slash normalization.
        if ($host === '') {
            return rtrim($url, '/');
        }

        $authority = $host;
        // Preserve explicit port in canonical URL when present.
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $path = trim((string) ($parts['path'] ?? ''));
        $trailingSlash = ChannelPolicy::siteRoutingUsesTrailingSlash($this->config);
        // Root path canonicalizes to empty suffix while deeper paths follow site routing mode.
        if ($path === '' || $path === '/') {
            $path = '';
        } else {
            $path = rtrim($path, '/');
            if ($trailingSlash) {
                $path .= '/';
            }
        }

        $normalized = $scheme . $authority . $path;
        // Preserve query string in canonical URL when present.
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }
        // Preserve fragment in canonical URL when present.
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $normalized .= '#' . $parts['fragment'];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeDescTemplateField(array $payload): array
    {
        $payload['desc'] = trim((string) ($payload['desc'] ?? $payload['description'] ?? ''));
        unset($payload['description']);

        return $payload;
    }

    /**
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateDataFromPath(string $avatarPath): array
    {
        return $this->avatarConfig->templateData($avatarPath);
    }

    /**
     * Returns the template-facing username when username auth mode is enabled.
     *
     * @param string $username Raw username value.
     * @return string Username for templates, or empty when hidden by auth mode.
     */
    private function publicTemplateUsername(string $username): string
    {
        $normalized = trim($username);
        // Empty usernames should never render into public templates.
        if ($normalized === '') {
            return '';
        }

        return $this->publicUsernamesEnabled() ? $normalized : '';
    }

    /**
     * Returns whether public templates should expose username values.
     *
     * @return bool True when user auth mode supports usernames.
     */
    private function publicUsernamesEnabled(): bool
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.method', 'email')));
        return $mode !== 'email';
    }
}
