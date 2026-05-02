<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/UserController.php
 * Split public user controller for public profile routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\UserRead;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\View\Public\TemplateDecorator;

/**
 * Handles split public user-profile routes.
 */
final class UserController
{
    private SharedController $context;
    private UserRead $userRead;
    private LoginIdentifier $loginIdentifierResolver;
    private UserProfileParser $profileContactService;
    private TemplateDecorator $templateDecorator;

    /**
     * @param SharedController $context Shared public request context.
     * @param UserRead $userRepo User repository read side for public profile routes.
     * @return void
     */
    public function __construct(
        SharedController $context,
        UserRead $userRepo
    ) {
        $this->context = $context;
        $this->userRead = $userRepo;
        $this->loginIdentifierResolver = new LoginIdentifier();
        $this->profileContactService = new UserProfileParser($context->input());
        $this->templateDecorator = new TemplateDecorator(
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
        $profileMode = $this->context->groupParser()->profileMode();
        $isLoggedIn = $this->context->auth()->isLoggedIn();
        if ($this->context->groupParser()->profileRoutePrefix() === '') {
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
        $profile = $this->templateDecorator->decorateProfileForTemplate($profile);

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
     * Renders profile-disabled/private-denied placeholder with explicit status.
     *
     * @param string $error Public route error key.
     * @param string $mode Profile visibility mode that triggered the response.
     * @return void
     */
    private function renderProfileUnavailable(string $error, string $mode): void
    {
        $status = $error === 'permission_denied' ? 403 : 404;
        $siteData = $this->context->siteData();

        http_response_code($status);
        $this->context->renderPublic(
            'profile/index',
            [
                'site' => $siteData,
                'profile_denied' => $error === 'permission_denied' && $mode === 'private',
            ],
            'wrapper'
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
        $selector = $this->context->groupParser()->profileSelector();
        if ($selector === 'id') {
            $userId = $this->context->input()->int($routeSegment, 1);
            if ($userId === null) {
                return null;
            }

            return $this->userRead->findProfileSummaryById($userId);
        }

        if ($selector === 'string') {
            $normalizedString = trim($routeSegment);
            if ($normalizedString === '' || preg_match('/^[a-zA-Z0-9]+$/', $normalizedString) !== 1) {
                return null;
            }

            return $this->userRead->findProfileSummaryByString($normalizedString);
        }

        $normalizedUsername = $this->loginIdentifierResolver->normalizeUsernameOrEmail($this->context->input(), $routeSegment);
        if ($normalizedUsername === null) {
            return null;
        }

        return $this->userRead->findProfileSummaryByUsername($normalizedUsername);
    }
}
