<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Pagination\Pagination;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared template-facing payload decoration helpers for public views.
 */
final class PublicTemplateDecorator
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
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
        $page['show_title'] = !array_key_exists('display_title', $page)
            || (int) ($page['display_title'] ?? 1) === 1;

        $rawBlocks = is_array($page['extended_blocks'] ?? null) ? $page['extended_blocks'] : [];
        $hasBodyContent = trim((string) ($page['content'] ?? '')) !== '';
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
                'raven-page-extended-block',
                'raven-page-extended-block-' . $displayIndex,
            ];

            if ($hasBodyContent || $displayIndex > 1) {
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

        $page['extended_blocks'] = $renderedBlocks;
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
        $username = trim((string) ($profile['username'] ?? ''));
        $profile['display_name_resolved'] = $displayName !== '' ? $displayName : $username;

        $avatar = $this->avatarTemplateDataFromPath((string) ($profile['avatar_path'] ?? ''));
        $profile['avatar_filename'] = $avatar['filename'];
        $profile['avatar_url'] = $avatar['url'];
        $profile['avatar_thumb_url'] = $avatar['thumb_url'];
        $profile['has_avatar'] = $avatar['filename'] !== '';

        $contacts = is_array($profile['contact_profiles'] ?? null) ? $profile['contact_profiles'] : [];
        foreach ($contacts as $index => $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $href = trim((string) ($contact['href'] ?? ''));
            $contacts[$index]['is_external'] = preg_match('#^https?://#i', $href) === 1;
        }
        $profile['contact_profiles'] = $contacts;

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
            $username = trim((string) ($member['username'] ?? ''));
            $members[$index]['display_name_resolved'] = $displayName !== '' ? $displayName : $username;

            $avatar = $this->avatarTemplateDataFromPath((string) ($member['avatar_path'] ?? ''));
            $members[$index]['avatar_filename'] = $avatar['filename'];
            $members[$index]['avatar_url'] = $avatar['url'];
            $members[$index]['avatar_thumb_url'] = $avatar['thumb_url'];
            $members[$index]['has_avatar'] = $avatar['filename'] !== '';
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
        $group['member_count'] = max(count($members), (int) ($group['member_count'] ?? 0));
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

        $themeCss = trim((string) ($site['theme_css'] ?? $themeData['css'] ?? $theme));
        if ($themeCss === '') {
            $themeCss = 'raven';
        }

        $siteUrl = trim((string) ($site['url'] ?? ''));
        $themeUrl = trim((string) ($site['theme_url'] ?? $themeData['url'] ?? ''));
        if ($themeUrl === '' && $siteUrl !== '') {
            $themeUrl = rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeCss);
        }
        $data['theme'] = [
            'slug' => $theme,
            'css' => $themeCss,
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

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $meta['apple_touch_icon'] = trim((string) ($site['apple_touch_icon'] ?? ''));
        $meta['robots'] = trim((string) ($site['robots'] ?? ''));
        $meta['image'] = trim((string) ($site['og_image'] ?? ''));
        if ($meta['image'] === '') {
            $meta['image'] = trim((string) ($site['twitter_image'] ?? ''));
        }
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
            $site['robots'],
            $site['og_image'],
            $site['og_type'],
            $site['og_locale'],
            $site['twitter_card'],
            $site['twitter_creator'],
            $site['twitter_image'],
            $site['twitter_site'],
            $site['theme'],
            $site['theme_css'],
            $site['theme_url'],
            $site['panel_path']
        );

        $viewTitle = '';
        $metaDescription = '';
        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        $pageNumber = max(1, (int) ($pagination['current'] ?? 1));

        if (is_array($data['page'] ?? null)) {
            $viewTitle = trim((string) ($data['page']['title'] ?? ''));
            $metaDescription = trim((string) ($data['page']['description'] ?? ''));
        } elseif (is_array($data['category'] ?? null)) {
            $categoryName = trim((string) ($data['category']['name'] ?? ''));
            if ($categoryName !== '') {
                $viewTitle = 'Category: ' . $categoryName;
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['category']['description'] ?? ''));
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

                $metaDescription = 'Browse pages tagged ' . $tagName . '.';
            }
        } elseif (is_array($data['profile'] ?? null)) {
            $profileName = trim((string) ($data['profile']['display_name_resolved'] ?? $data['profile']['display_name'] ?? ''));
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

        $documentTitle = $viewTitle === '' ? $siteName : ($viewTitle . ' [' . $siteName . ']');

        $meta['title'] = $viewTitle;
        $meta['desc'] = $metaDescription;
        $meta['document_title'] = $documentTitle;

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
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateDataFromPath(string $avatarPath): array
    {
        $avatarFilename = basename(trim($avatarPath));
        if ($avatarFilename === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
        $avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;

        return [
            'filename' => $avatarFilename,
            'url' => '/uploads/avatars/' . rawurlencode($avatarFilename),
            'thumb_url' => '/uploads/avatars/' . rawurlencode($avatarThumbFilename),
        ];
    }
}
