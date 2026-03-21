<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Shared route-to-template/status rendering decisions for public controllers.
 */
final class PublicRouteRenderService
{
    /**
     * @param array<string, string> $siteData
     * @return array{status: int, template: string, layout: string, data: array<string, mixed>}
     */
    public function profileUnavailablePayload(string $error, string $mode, array $siteData): array
    {
        return [
            'status' => $error === 'permission_denied' ? 403 : 404,
            'template' => 'profiles/index',
            'layout' => 'wrapper',
            'data' => [
                'site' => $siteData,
                'profile_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
        ];
    }

    /**
     * @param array<string, string> $siteData
     * @return array{status: int, template: string, layout: string, data: array<string, mixed>}
     */
    public function groupUnavailablePayload(string $error, string $mode, array $siteData): array
    {
        return [
            'status' => $error === 'permission_denied' ? 403 : 404,
            'template' => 'groups/index',
            'layout' => 'wrapper',
            'data' => [
                'site' => $siteData,
                'group_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
        ];
    }

    /**
     * @param array<string, string> $siteData
     * @return array{
     *   allowed: bool,
     *   status: int|null,
     *   template: string|null,
     *   layout: string|null,
     *   data: array<string, mixed>
     * }
     */
    public function availabilityGatePayload(
        string $mode,
        bool $isLoggedIn,
        bool $canViewDisabledSite,
        bool $canViewPrivateSite,
        bool $canViewPublicSite,
        array $siteData
    ): array {
        if ($mode === 'disabled') {
            if ($isLoggedIn && $canViewDisabledSite) {
                return $this->allowPayload();
            }

            return $this->blockedPayload(503, 'messages/disabled', $siteData);
        }

        if ($mode === 'private') {
            if (!$isLoggedIn || !$canViewPrivateSite) {
                return $this->blockedPayload(403, 'messages/denied', $siteData);
            }

            return $this->allowPayload();
        }

        if (!$canViewPublicSite) {
            return $this->blockedPayload(403, 'messages/denied', $siteData);
        }

        return $this->allowPayload();
    }

    /**
     * @param array<string, string> $siteData
     * @return array{status: int, template: string, layout: string, data: array<string, mixed>}
     */
    public function notFoundPayload(array $siteData): array
    {
        return [
            'status' => 404,
            'template' => 'messages/404',
            'layout' => 'wrapper',
            'data' => ['site' => $siteData],
        ];
    }

    /**
     * @return array{
     *   allowed: bool,
     *   status: int|null,
     *   template: string|null,
     *   layout: string|null,
     *   data: array<string, mixed>
     * }
     */
    private function allowPayload(): array
    {
        return [
            'allowed' => true,
            'status' => null,
            'template' => null,
            'layout' => null,
            'data' => [],
        ];
    }

    /**
     * @param array<string, string> $siteData
     * @return array{
     *   allowed: bool,
     *   status: int|null,
     *   template: string|null,
     *   layout: string|null,
     *   data: array<string, mixed>
     * }
     */
    private function blockedPayload(int $status, string $template, array $siteData): array
    {
        return [
            'allowed' => false,
            'status' => $status,
            'template' => $template,
            'layout' => 'wrapper',
            'data' => ['site' => $siteData],
        ];
    }
}
