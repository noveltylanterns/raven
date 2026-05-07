<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/UserInviteController.php
 * Panel invite-token write controller for user invite management routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\InviteWrite;
use Raven\Lib\Auth\SessionFlash;
use Raven\Core\Router\UserPolicy;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;

/**
 * Handles invite-token create/generate/delete routes for panel user onboarding.
 */
final class UserInviteController
{
    private SharedController $context;
    private InputSanitizer $input;
    private Closure $inviteWriteResolver;
    private ?InviteWrite $inviteWrite = null;
    private SessionFlash $flashList;
    private UserPolicy $groupParser;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable(): InviteWrite $inviteWriteResolver Lazy invite write resolver for token creation/deletion.
     * @param SessionFlash $flashList List-style flash store for generated token batches.
     * @param UserPolicy $groupParser Shared group/profile routing-policy parser.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $inviteWriteResolver,
        SessionFlash $flashList,
        UserPolicy $groupParser
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->inviteWriteResolver = Closure::fromCallable($inviteWriteResolver);
        $this->flashList = $flashList;
        $this->groupParser = $groupParser;
    }

    /**
     * Creates one invite token from panel form input.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function userInvitesCreate(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'create')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $isReusable = $this->isReusableInviteType($post['invite_type'] ?? 'single');
        $manualToken = null;
        if (!$isReusable) {
            $manualToken = trim((string) $this->input->text($post['token_slug'] ?? null, 255));
            if ($manualToken === '') {
                $manualToken = null;
            }
        }

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        try {
            $token = $this->inviteWrite()->createToken($isReusable, $expiresAt, $this->context->auth()->userId(), $manualToken);
        } catch (\Throwable $exception) {
            $this->context->flash('error', 'Failed to create invite token: ' . ($exception->getMessage() ?: 'Unknown error.'));
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $this->context->flash('success', $isReusable ? 'Reusable invite token created.' : 'Single-use invite token created.');
        $this->storeFlashList('generated_invites', [$token]);
        Redirect::redirect($this->context->panelUrl('/user/invites'));
    }

    /**
     * Generates a batch of single-use invite tokens from panel form input.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function userInvitesGenerate(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'create')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $count = $this->normalizeInviteBatchCount($post['count'] ?? null, 10, 1, 100);

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        try {
            $tokens = $this->inviteWrite()->createSingleUseBatch($count, $expiresAt, $this->context->auth()->userId());
        } catch (\Throwable $exception) {
            $this->context->flash('error', 'Failed to generate invite tokens: ' . ($exception->getMessage() ?: 'Unknown error.'));
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $this->context->flash('success', 'Generated ' . count($tokens) . ' single-use invite token' . (count($tokens) === 1 ? '' : 's') . '.');
        $this->storeFlashList('generated_invites', $tokens);
        Redirect::redirect($this->context->panelUrl('/user/invites'));
    }

    /**
     * Deletes one invite token.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function userInvitesDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'delete')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id === null) {
            $this->context->flash('error', 'Invite token id is required.');
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        if (!$this->inviteWrite()->deleteById($id)) {
            $this->context->flash('error', 'Invite token was not found.');
            Redirect::redirect($this->context->panelUrl('/user/invites'));
        }

        $this->context->flash('success', 'Invite token deleted.');
        Redirect::redirect($this->context->panelUrl('/user/invites'));
    }

    /**
     * Resolves the invite write side only when invite create/delete routes are hit.
     *
     * @return InviteWrite Invite-token write side for token creation and deletion.
     */
    private function inviteWrite(): InviteWrite
    {
        if ($this->inviteWrite instanceof InviteWrite) {
            return $this->inviteWrite;
        }

        $repo = ($this->inviteWriteResolver)();
        if (!$repo instanceof InviteWrite) {
            throw new \RuntimeException('Panel invite write resolver returned an invalid value.');
        }

        $this->inviteWrite = $repo;
        return $this->inviteWrite;
    }

    /**
     * Stores one flash-list payload in session after sanitizing the items.
     *
     * @param string $key Flash-list storage key.
     * @param array<int, string> $values Flash-list values to store.
     * @return void
     */
    private function storeFlashList(string $key, array $values): void
    {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim($value);
            if ($item === '') {
                continue;
            }

            $normalized[] = $this->input->text($item, 400);
        }

        if ($normalized === []) {
            return;
        }

        $this->flashList->putList($key, $normalized);
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
     * Parses one optional invite-expiration datetime into a unix timestamp.
     *
     * @param mixed $rawValue User-submitted expiration value.
     * @return int|null Parsed timestamp, or null when blank.
     * @throws \RuntimeException When the submitted value is invalid or not in the future.
     */
    private function parseInviteExpirationTimestamp(mixed $rawValue): ?int
    {
        $value = trim((string) $this->input->text(is_string($rawValue) ? $rawValue : null, 40));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException('Invite expiration must be a valid date/time or left blank.');
        }

        if ($timestamp <= time()) {
            throw new \RuntimeException('Invite expiration must be in the future.');
        }

        return $timestamp;
    }

    /**
     * Resolves whether one invite-create request should generate a reusable token.
     *
     * @param mixed $rawType Submitted invite type value.
     * @return bool True when reusable invite mode is selected.
     */
    private function isReusableInviteType(mixed $rawType): bool
    {
        $inviteType = strtolower(trim((string) $this->input->text($rawType, 20)));
        return $inviteType === 'reusable';
    }

    /**
     * Normalizes invite batch-count input for bulk single-use token creation.
     *
     * @param mixed $rawCount Submitted count value.
     * @param int $default Default count when submitted value is empty/invalid.
     * @param int $min Minimum allowed count.
     * @param int $max Maximum allowed count.
     * @return int Normalized invite batch count.
     */
    private function normalizeInviteBatchCount(mixed $rawCount, int $default = 10, int $min = 1, int $max = 100): int
    {
        $default = max($min, min($max, $default));
        return $this->input->int($rawCount, $min, $max) ?? $default;
    }
}
