<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Config;
use Raven\Lib\Media\UserMediaPathService;
use Raven\Lib\Pagination\Pagination;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared template-facing payload decoration helpers for public views.
 */
final class PublicTemplateDecorator
{
    private Config $config;
    private InputSanitizer $input;
    private string $projectRoot;
    private UserMediaPathService $userMediaPathService;

    public function __construct(Config $config, InputSanitizer $input, string $projectRoot)
    {
        $this->config = $config;
        $this->input = $input;
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->userMediaPathService = new UserMediaPathService();
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    public function decoratePageListForTemplate(array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $path = trim((string) ($page['url'] ?? ''));
            if ($path === '') {
                $slug = $this->input->slug((string) ($page['slug'] ?? ''));
                $channelSlug = $this->input->slug((string) ($page['channel_slug'] ?? ''));
                if ($slug === null || $slug === '') {
                    $path = '/';
                } elseif ($channelSlug === null || $channelSlug === '') {
                    $path = '/' . rawurlencode($slug);
                } else {
                    $path = '/' . rawurlencode($channelSlug) . '/' . rawurlencode($slug);
                }
            }

            $pages[$index]['url'] = $path;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    public function decoratePaginationForTemplate(array $pagination): array
    {
        return Pagination::decorateTemplateLinks($pagination);
    }

    /**
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

        if (array_key_exists('channel_id', $page)) {
            $channelId = (int) ($page['channel_id'] ?? 0);
            $page['channel_id'] = max(0, $channelId);
        }

        $rawBlocks = is_array($page['content_blocks'] ?? null) ? $page['content_blocks'] : [];
        $renderedBlocks = [];
        $displayIndex = 0;

        foreach ($rawBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $html = trim((string) ($block['html'] ?? ''));
            if ($html === '') {
                continue;
            }

            $displayIndex++;
            $classNames = [
                'raven-page-content-block',
                'raven-page-content-block-' . $displayIndex,
            ];

            if ($displayIndex > 1) {
                array_unshift($classNames, 'mt-3');
            }

            $customClass = trim((string) ($block['css_class'] ?? ''));
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
     * @param array<int, array<string, mixed>> $galleryImages
     * @return array<int, array<string, mixed>>
     */
    public function decorateGalleryImagesForTemplate(array $galleryImages): array
    {
        foreach ($galleryImages as $index => $image) {
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
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function decorateProfileForTemplate(array $profile): array
    {
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        $username = $this->publicTemplateUsername((string) ($profile['username'] ?? ''));
        $profile['name'] = $displayName !== '' ? $displayName : $username;
        $profile['username'] = $username;

        $avatar = $this->avatarTemplateDataFromPath((string) ($profile['avatar_path'] ?? ''));
        $profile['avatar_filename'] = $avatar['filename'];
        $profile['avatar_full'] = $avatar['url'];
        $profile['avatar_thumb'] = $avatar['thumb_url'];
        $profile['avatar'] = $avatar['filename'] !== '';

        $contacts = is_array($profile['contact_profiles'] ?? null) ? $profile['contact_profiles'] : [];
        $contactValues = [];
        foreach ($contacts as $index => $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $href = trim((string) ($contact['href'] ?? ''));
            $contacts[$index]['is_external'] = preg_match('#^https?://#i', $href) === 1;

            $type = trim((string) ($contact['type'] ?? ''));
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
            $profile['contact_profiles']
        );

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, mixed>>
     */
    public function decorateGroupMembersForTemplate(array $members): array
    {
        foreach ($members as $index => $member) {
            if (!is_array($member)) {
                continue;
            }

            $displayName = trim((string) ($member['display_name'] ?? ''));
            $username = $this->publicTemplateUsername((string) ($member['username'] ?? ''));
            $members[$index]['name'] = $displayName !== '' ? $displayName : $username;
            $members[$index]['username'] = $username;

            $avatar = $this->avatarTemplateDataFromPath((string) ($member['avatar_path'] ?? ''));
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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decorateTemplateData(array $data, int $statusCode): array
    {
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];

        $siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
        if ($siteName === '') {
            $siteName = 'Raven CMS';
        }
        $site['name'] = $siteName;

        $themeData = is_array($data['theme'] ?? null) ? $data['theme'] : [];

        $theme = trim((string) ($site['theme'] ?? $themeData['slug'] ?? 'raven'));
        if ($theme === '') {
            $theme = 'raven';
        }

        $themeActive = trim((string) ($site['theme_active'] ?? $themeData['active'] ?? $theme));
        if ($themeActive === '') {
            $themeActive = 'raven';
        }

        $siteUrl = trim((string) ($site['url'] ?? ''));
        $themeUrl = trim((string) ($site['theme_url'] ?? $themeData['url'] ?? ''));
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
        if ($meta['og_type'] === '') {
            $meta['og_type'] = 'website';
        }
        $meta['og_locale'] = trim((string) ($site['og_locale'] ?? ''));
        if ($meta['og_locale'] === '') {
            $meta['og_locale'] = 'en_US';
        }
        $meta['x_card'] = trim((string) ($site['twitter_card'] ?? ''));
        $meta['x_creator'] = trim((string) ($site['twitter_creator'] ?? ''));
        $meta['x_site'] = trim((string) ($site['twitter_site'] ?? ''));
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

        foreach (['page', 'category', 'tag', 'channel'] as $root) {
            if (!is_array($data[$root] ?? null)) {
                continue;
            }

            $data[$root] = $this->normalizeDescTemplateField($data[$root]);
        }

        $viewTitle = '';
        $metaDescription = '';
        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        $pageNumber = max(1, (int) ($pagination['current'] ?? 1));

        if (is_array($data['page'] ?? null)) {
            $viewTitle = trim((string) ($data['page']['title'] ?? ''));
            $metaDescription = trim((string) ($data['page']['desc'] ?? ''));
        } elseif (is_array($data['category'] ?? null)) {
            $categoryName = trim((string) ($data['category']['name'] ?? ''));
            if ($categoryName !== '') {
                $viewTitle = 'Category: ' . $categoryName;
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['category']['desc'] ?? ''));
                if ($metaDescription === '') {
                    $metaDescription = 'Browse pages in category ' . $categoryName . '.';
                }
            }
        } elseif (is_array($data['tag'] ?? null)) {
            $tagName = trim((string) ($data['tag']['name'] ?? ''));
            if ($tagName !== '') {
                $viewTitle = 'Tag: ' . $tagName;
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['tag']['desc'] ?? ''));
                if ($metaDescription === '') {
                    $metaDescription = 'Browse pages tagged ' . $tagName . '.';
                }
            }
        } elseif (is_array($data['profile'] ?? null)) {
            $profileName = trim((string) ($data['profile']['name'] ?? $data['profile']['display_name'] ?? ''));
            if ($profileName === '') {
                $profileName = trim((string) ($data['profile']['username'] ?? ''));
            }
            if ($profileName !== '') {
                $viewTitle = 'Profile: ' . $profileName;
                $metaDescription = 'Public profile for ' . $profileName . '.';
            }
        } elseif (is_array($data['group'] ?? null)) {
            $groupName = trim((string) ($data['group']['name'] ?? ''));
            if ($groupName !== '') {
                $viewTitle = 'Group: ' . $groupName;
                $metaDescription = 'Members in group ' . $groupName . '.';
            }
        }

        if ($viewTitle === '' && $statusCode === 404) {
            $viewTitle = 'Not Found';
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

    private function normalizedMetaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return rtrim($url, '/');
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return rtrim($url, '/');
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $path = trim((string) ($parts['path'] ?? ''));
        if ($path === '' || $path === '/') {
            $path = '';
        } else {
            $path = rtrim($path, '/');
        }

        $normalized = $scheme . $authority . $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }
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
        return $this->userMediaPathService->avatarTemplateData($this->projectRoot, $avatarPath);
    }

    private function publicTemplateUsername(string $username): string
    {
        $normalized = trim($username);
        if ($normalized === '') {
            return '';
        }

        return $this->publicUsernamesEnabled() ? $normalized : '';
    }

    private function publicUsernamesEnabled(): bool
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.login', 'email')));
        return $mode !== 'email';
    }
}
