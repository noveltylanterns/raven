<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/UserListController.php
 * Panel user list controller for user list and invite list routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\UserRead;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;

/**
 * Handles user list and invite list routes for the panel.
 *
 * Owns GET /user and GET /user/invites only. User create/edit/save/delete live
 * in UserEditController, and invite token create/generate/delete live in
 * UserInviteController.
 */
final class UserListController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private GroupRead $groupRead;
    private UserRead $userRead;
    private Closure $inviteReadResolver;
    private ?InviteRead $inviteRead = null;
    private SessionFlash $flashList;
    private GroupRouteParser $groupParser;
    private LoginIdentifierResolver $loginIdentifierResolver;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupRead $groupRead Group repository read side for group filter options.
     * @param UserRead $userRead User repository read side for user list and invite creator map.
     * @param callable(): InviteRead $inviteReadResolver Lazy invite read resolver for token listings.
     * @param SessionFlash $flashList List-style flash store for pulling generated token batches.
     * @param GroupRouteParser $groupParser Shared group/profile routing-policy parser.
     * @param LoginIdentifierResolver $loginIdentifierResolver Shared login-identifier normalization helper.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        GroupRead $groupRead,
        UserRead $userRead,
        callable $inviteReadResolver,
        SessionFlash $flashList,
        GroupRouteParser $groupParser,
        LoginIdentifierResolver $loginIdentifierResolver
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->groupRead = $groupRead;
        $this->userRead = $userRead;
        $this->inviteReadResolver = Closure::fromCallable($inviteReadResolver);
        $this->flashList = $flashList;
        $this->groupParser = $groupParser;
        $this->loginIdentifierResolver = $loginIdentifierResolver;
    }

    /**
     * Lists users for the User management section.
     *
     * @return void
     */
    public function userList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'view')) {
            return;
        }

        $prefilterGroup = strtolower(trim((string) ($this->input->text($_GET['group'] ?? null, 120) ?? '')));
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->userRead->listPage(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterGroup !== '' ? $prefilterGroup : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $userRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->userRead->listPage(
                $perPage,
                $pagination['offset'],
                $prefilterGroup !== '' ? $prefilterGroup : null
            );
            $userRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $groupOptions = is_array($pageResult['group_options'] ?? null)
            ? $pageResult['group_options']
            : $this->groupRead->listOptions();

        $this->context->renderPanel('panel/user/list', [
            'users' => $userRows,
            'prefilterGroup' => $prefilterGroup,
            'groupOptions' => $groupOptions,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'registrationMode' => $this->registrationMode(),
            'pagination' => $this->context->panelPaginationViewData('/user', $pagination, ['group' => $prefilterGroup]),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'user',
        ]);
    }

    /**
     * Lists registration invite tokens for user onboarding.
     *
     * @return void
     */
    public function userInvites(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'view')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        $this->context->renderPanel('panel/user/invites', [
            'inviteRows' => $this->inviteRead()->listAll(),
            'inviteCreatorMap' => $this->inviteCreatorMap(),
            'inviteGeneratedTokens' => $this->pullFlashList('generated_invites'),
            'inviteRegistrationMode' => $this->registrationMode(),
            'inviteNowTs' => time(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'user',
        ]);
    }

    /**
     * Resolves the invite read side only when invite list routes are hit.
     *
     * @return InviteRead Invite-token read side for token listings.
     */
    private function inviteRead(): InviteRead
    {
        if ($this->inviteRead instanceof InviteRead) {
            return $this->inviteRead;
        }

        $repo = ($this->inviteReadResolver)();
        if (!$repo instanceof InviteRead) {
            throw new \RuntimeException('Panel invite read resolver returned an invalid value.');
        }

        $this->inviteRead = $repo;
        return $this->inviteRead;
    }

    /**
     * Builds one user-id keyed label/edit-url map for invite-token creator rendering.
     *
     * @return array<int, array{label: string, edit_url: string}>
     */
    private function inviteCreatorMap(): array
    {
        $rows = $this->userRead->listAll();
        $map = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $displayName = trim((string) ($row['name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $label = $displayName !== ''
                ? $displayName
                : ($username !== '' ? $username : ($email !== '' ? $email : ('User #' . $userId)));

            $map[$userId] = [
                'label' => $label,
                'edit_url' => $this->context->panelUrl('/user/edit/' . $userId),
            ];
        }

        return $map;
    }

    /**
     * Pulls and removes one flash-list payload from session.
     *
     * @param string $key Flash-list storage key.
     * @return array<int, string>|null Sanitized stored values, or null when none exist.
     */
    private function pullFlashList(string $key): ?array
    {
        $value = $this->flashList->pullList($key);
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            $stringItem = is_string($item) ? trim($item) : '';
            if ($stringItem === '') {
                continue;
            }

            $normalized[] = $this->input->text($stringItem, 400);
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Resolves configured public registration mode.
     *
     * @return string Normalized public registration mode.
     */
    private function registrationMode(): string
    {
        return $this->groupParser->registrationMode();
    }

    /**
     * Restricts invite-token management to invite-only registration mode.
     *
     * @return bool True when invite-token management is allowed.
     */
    private function ensureInviteRegistrationMode(): bool
    {
        if ($this->registrationMode() === 'invite') {
            return true;
        }

        $this->context->flash('error', 'User invite tokens are available only when public registration mode is set to Invite.');
        Redirect::redirect($this->context->panelUrl('/user'));
        return false;
    }

    /**
     * Resolves the configured panel login identifier mode.
     *
     * @return string `email` or `username`.
     */
    private function panelLoginIdentifierMode(): string
    {
        return $this->loginIdentifierResolver->modeFromConfig($this->config);
    }
}
