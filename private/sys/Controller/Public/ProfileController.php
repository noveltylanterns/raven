<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/ProfileController.php
 * Split public profile controller for user and group profile routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\View\PublicRouteRenderService;
use Raven\Lib\View\PublicTemplateDecorator;
use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\UserRepository;

/**
 * Handles split public profile and group routes.
 */
final class ProfileController
{
    private RequestContext $context;
    private GroupRepository $groupRepo;
    private UserRepository $userRepo;
    private LoginIdentifierResolver $loginIdentifierResolver;
    private ProfileContactService $profileContactService;
    private PublicRouteRenderService $publicRouteRenderService;
    private PublicTemplateDecorator $publicTemplateDecorator;

    /**
     * @param RequestContext $context Shared public request context.
     * @param GroupRepository $groupRepo Group repository for public group routes.
     * @param UserRepository $userRepo User repository for public profile routes.
     * @return void
     */
    public function __construct(
        RequestContext $context,
        GroupRepository $groupRepo,
        UserRepository $userRepo
    ) {
        $this->context = $context;
        $this->groupRepo = $groupRepo;
        $this->userRepo = $userRepo;
        $this->loginIdentifierResolver = new LoginIdentifierResolver();
        $this->profileContactService = new ProfileContactService($context->input());
        $this->publicRouteRenderService = new PublicRouteRenderService();
        $this->publicTemplateDecorator = new PublicTemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
    }

    /**
     * Renders one public profile route `/{profile_prefix}/{selector}`.
     *
     * @param string $username Raw route segment representing the configured profile selector.
     * @return void
     */
    public function profile(string $username): void
    {
        $profileMode = $this->context->routeConfigService()->profileMode();
        $isLoggedIn = $this->context->auth()->isLoggedIn();
        if ($this->context->routeConfigService()->profileRoutePrefix() === '') {
            $this->context->notFound();
            return;
        }

        if ($profileMode === 'disabled') {
            $this->renderProfileUnavailable('not_found', 'disabled');
            return;
        }

        if ($profileMode === 'private' && !$isLoggedIn) {
            $this->renderProfileUnavailable('permission_denied', 'private');
            return;
        }

        $profile = $this->findPublicProfileByRouteSegment(rawurldecode($username));
        if ($profile === null) {
            $this->context->notFound();
            return;
        }

        $profile = $this->decoratePublicProfileContacts($profile);
        $profile = $this->publicTemplateDecorator->decorateProfileForTemplate($profile);

        $template = match ($profileMode) {
            'public_full' => 'profile/full',
            'public_limited' => $isLoggedIn ? 'profile/full' : 'profile/limited',
            'private' => 'profile/full',
            default => 'profile/index',
        };

        $this->context->renderPublic($template, [
            'site' => $this->context->siteData(),
            'profile' => $profile,
        ], 'wrapper');
    }

    /**
     * Renders one public group route `/{group_prefix}/{group_slug}`.
     *
     * @param string $groupSlug Raw group route segment.
     * @return void
     */
    public function group(string $groupSlug): void
    {
        $groupMode = $this->context->routeConfigService()->groupMode();
        $isLoggedIn = $this->context->auth()->isLoggedIn();
        if ($this->context->routeConfigService()->groupRoutePrefix() === '') {
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

        $groupRouteData = $this->groupRepo->findPublicRouteDataBySlug($normalizedSlug);
        if ($groupRouteData === null) {
            $this->context->notFound();
            return;
        }

        $group = is_array($groupRouteData['group'] ?? null) ? $groupRouteData['group'] : [];
        $members = is_array($groupRouteData['members'] ?? null) ? $groupRouteData['members'] : [];
        $members = $this->publicTemplateDecorator->decorateGroupMembersForTemplate($members);
        $group = $this->publicTemplateDecorator->decorateGroupForTemplate($group, $members);

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
     * Renders profile-disabled/private-denied placeholder with explicit status.
     *
     * @param string $error Public route error key.
     * @param string $mode Profile visibility mode that triggered the response.
     * @return void
     */
    private function renderProfileUnavailable(string $error, string $mode): void
    {
        $payload = $this->publicRouteRenderService->profileUnavailablePayload($error, $mode, $this->context->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->context->renderPublic(
            (string) ($payload['template'] ?? 'profile/index'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
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
        $payload = $this->publicRouteRenderService->groupUnavailablePayload($error, $mode, $this->context->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->context->renderPublic(
            (string) ($payload['template'] ?? 'group/index'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
    }

    /**
     * Attaches label/href metadata to public profile contact rows.
     *
     * @param array<string, mixed> $profile Public profile payload.
     * @return array<string, mixed> Decorated public profile payload.
     */
    private function decoratePublicProfileContacts(array $profile): array
    {
        $profileContactOptions = $this->profileContactService->normalizeOptionsConfig(
            $this->context->config()->get('user.contact', $this->profileContactService->defaultOptions())
        );

        return $this->profileContactService->decorateProfileContacts($profile, $profileContactOptions);
    }

    /**
     * Resolves one public profile using the configured public route selector strategy.
     *
     * @param string $routeSegment Raw public profile route segment.
     * @return array<string, mixed>|null Profile payload when found.
     */
    private function findPublicProfileByRouteSegment(string $routeSegment): ?array
    {
        $selector = $this->context->routeConfigService()->profileSelector();
        if ($selector === 'id') {
            $userId = $this->context->input()->int($routeSegment, 1);
            if ($userId === null) {
                return null;
            }

            return $this->userRepo->findPublicProfileById($userId);
        }

        if ($selector === 'string') {
            $normalizedString = trim($routeSegment);
            if ($normalizedString === '' || preg_match('/^[a-zA-Z0-9]+$/', $normalizedString) !== 1) {
                return null;
            }

            return $this->userRepo->findPublicProfileByString($normalizedString);
        }

        $normalizedUsername = $this->loginIdentifierResolver->normalizeUsernameOrEmail($this->context->input(), $routeSegment);
        if ($normalizedUsername === null) {
            return null;
        }

        return $this->userRepo->findPublicProfileByUsername($normalizedUsername);
    }
}
