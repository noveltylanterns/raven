<?php

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Shared route-to-template/status rendering decisions for public profile and group controllers.
 */
final class PublicRouteRenderService
{
    /**
     * Returns the render payload for an unavailable user profile page.
     *
     * @param string               $error    Error key: 'permission_denied' for 403, any other value for 404.
     * @param string               $mode     Visibility mode ('public', 'private', or 'disabled') of the profile.
     * @param array<string, string> $siteData Assembled site context to pass to the template.
     * @return array{status: int, template: string, layout: string, data: array<string, mixed>}
     */
    public function profileUnavailablePayload(string $error, string $mode, array $siteData): array
    {
        return [
            'status' => $error === 'permission_denied' ? 403 : 404,
            'template' => 'profile/index',
            'layout' => 'wrapper',
            'data' => [
                'site' => $siteData,
                'profile_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
        ];
    }

    /**
     * Returns the render payload for an unavailable group page.
     *
     * @param string               $error    Error key: 'permission_denied' for 403, any other value for 404.
     * @param string               $mode     Visibility mode ('public', 'private', or 'disabled') of the group.
     * @param array<string, string> $siteData Assembled site context to pass to the template.
     * @return array{status: int, template: string, layout: string, data: array<string, mixed>}
     */
    public function groupUnavailablePayload(string $error, string $mode, array $siteData): array
    {
        return [
            'status' => $error === 'permission_denied' ? 403 : 404,
            'template' => 'group/index',
            'layout' => 'wrapper',
            'data' => [
                'site' => $siteData,
                'group_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
        ];
    }
}
