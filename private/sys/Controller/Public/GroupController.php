<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/GroupController.php
 * Split public group controller for group routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\GroupRead;
use Raven\Lib\View\Public\TemplateDecorator;

/**
 * Handles split public group routes.
 */
final class GroupController
{
    private SharedController $context;
    private GroupRead $groupRead;
    private TemplateDecorator $templateDecorator;

    /**
     * @param SharedController $context Shared public request context.
     * @param GroupRead $groupRead Group repository read side for public group-page lookups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        GroupRead $groupRead
    ) {
        $this->context = $context;
        $this->groupRead = $groupRead;
        $this->templateDecorator = new TemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
    }

    /**
     * Renders one public group route `/{group_prefix}/{group_slug}`.
     *
     * @param string $groupSlug Raw group route segment.
     * @return void
     */
    public function group(string $groupSlug): void
    {
        $groupMode = $this->context->groupParser()->groupMode();
        $isLoggedIn = $this->context->auth()->isLoggedIn();
        if ($this->context->groupParser()->groupRoutePrefix() === '') {
            $this->context->notFound();
            return;
        }

        if ($groupMode === 'disabled') {
            $this->renderGroupUnavailable('not_found', 'disabled');
            return;
        }

        if ($groupMode === 'private' && !$isLoggedIn) {
            $this->renderGroupUnavailable('permission_denied', 'private');
            return;
        }

        $normalizedSlug = $this->context->input()->slug($groupSlug);
        if ($normalizedSlug === null) {
            $this->context->notFound();
            return;
        }

        $groupRouteData = $this->groupRead->findRoutedBySlugWithMembers($normalizedSlug);
        if ($groupRouteData === null) {
            $this->context->notFound();
            return;
        }

        $group = is_array($groupRouteData['group'] ?? null) ? $groupRouteData['group'] : [];
        $members = is_array($groupRouteData['members'] ?? null) ? $groupRouteData['members'] : [];
        $members = $this->templateDecorator->decorateGroupMembersForTemplate($members);
        $group = $this->templateDecorator->decorateGroupForTemplate($group, $members);

        $template = match ($groupMode) {
            'public_full' => 'group/list',
            'public_limited' => $isLoggedIn ? 'group/list' : 'group/limited',
            'private' => 'group/list',
            default => 'group/index',
        };

        $this->context->renderPublic($template, [
            'site' => $this->context->siteData(),
            'group' => $group,
            'members' => $members,
        ], 'wrapper');
    }

    /**
     * Renders group-route disabled/private-denied placeholder with explicit status.
     *
     * @param string $error Public route error key.
     * @param string $mode Group visibility mode that triggered the response.
     * @return void
     */
    private function renderGroupUnavailable(string $error, string $mode): void
    {
        $status = $error === 'permission_denied' ? 403 : 404;
        $siteData = $this->context->siteData();

        http_response_code($status);
        $this->context->renderPublic(
            'group/index',
            [
                'site' => $siteData,
                'group_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
            'wrapper'
        );
    }
}
