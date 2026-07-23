<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/UserEditController.php
 * Panel user edit controller for user CRUD routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Core\Repository\AuthWrite;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Repository\UserWrite;
use Raven\Core\Router\UserPolicy;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Media\AvatarConfig;
use Raven\Lib\Media\AvatarDelete;
use Raven\Lib\Media\AvatarUpload;
use Raven\Lib\Media\AvatarValidator;
use Raven\Lib\Media\CoverConfig;
use Raven\Lib\Media\CoverDelete;
use Raven\Lib\Media\CoverUpload;
use Raven\Lib\Media\MediaConfig;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\View\Form2fa;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\Theme as PanelTheme;

/**
 * Handles panel user create/edit/save/delete routes.
 *
 * Owns user create/edit, save, and delete. Invite-token list/write routes are
 * split into UserListController and UserInviteController.
 */
final class UserEditController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private GroupRead $groupRead;
    private UserRead $userRead;
    private UserWrite $userWrite;
    private AuthWrite $authWrite;
    private UserPolicy $groupParser;
    private LoginIdentifier $loginIdentifier;
    private EditorTabs $editorTabs;
    private PanelTheme $panelTheme;
    private EditorBlocks $editorBlocks;
    private AvatarConfig $avatarConfig;
    private MediaConfig $mediaConfig;
    private UserProfileParser $profileParser;
    private Form2fa $form2fa;
    private CoverConfig $coverConfig;
    private string $projectRoot;
    private AvatarUpload $avatarUpload;
    private CoverUpload $coverUpload;
    private AvatarDelete $avatarDelete;
    private CoverDelete $coverDelete;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupRead $groupRead Group repository read side for group option reads and slug lookups.
     * @param UserRead $userRead User repository read side for user find and author lookups.
     * @param UserWrite $userWrite User repository write side for panel user saves and deletes.
     * @param AuthWrite $authWrite Auth-user write repository for 2FA method updates.
     * @param UserPolicy $groupParser Shared group/profile routing-policy parser.
     * @param LoginIdentifier $loginIdentifier Shared login-identifier normalization helper.
     * @param EditorTabs $editorTabs Shared editor-tab normalization helper.
     * @param EditorBlocks $editorBlocks Shared repeater-block view helper for modular panel rows.
     * @param AvatarConfig $avatarConfig Shared avatar-limit and template-data helper.
     * @param MediaConfig $mediaConfig Shared non-avatar media-limit helper.
     * @param UserProfileParser $profileParser Shared profile-contact normalizer.
     * @param Form2fa $form2fa Shared 2FA list normalizer.
     * @param CoverConfig $coverConfig Shared user cover-image URL resolver.
     * @param string $projectRoot Absolute project root for user-media filesystem writes.
     * @param AvatarUpload $avatarUpload Avatar upload storage and extension normalization helper.
     * @param CoverUpload $coverUpload Cover image upload storage helper.
     * @param AvatarDelete $avatarDelete Avatar file and thumbnail deletion helper.
     * @param CoverDelete $coverDelete Cover image file deletion helper.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        GroupRead $groupRead,
        UserRead $userRead,
        UserWrite $userWrite,
        AuthWrite $authWrite,
        UserPolicy $groupParser,
        LoginIdentifier $loginIdentifier,
        EditorTabs $editorTabs,
        EditorBlocks $editorBlocks,
        AvatarConfig $avatarConfig,
        MediaConfig $mediaConfig,
        UserProfileParser $profileParser,
        Form2fa $form2fa,
        CoverConfig $coverConfig,
        string $projectRoot,
        AvatarUpload $avatarUpload,
        CoverUpload $coverUpload,
        AvatarDelete $avatarDelete,
        CoverDelete $coverDelete
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->groupRead = $groupRead;
        $this->userRead = $userRead;
        $this->userWrite = $userWrite;
        $this->authWrite = $authWrite;
        $this->groupParser = $groupParser;
        $this->loginIdentifier = $loginIdentifier;
        $this->editorTabs = $editorTabs;
        $this->panelTheme = new PanelTheme();
        $this->editorBlocks = $editorBlocks;
        $this->avatarConfig = $avatarConfig;
        $this->mediaConfig = $mediaConfig;
        $this->profileParser = $profileParser;
        $this->form2fa = $form2fa;
        $this->coverConfig = $coverConfig;
        $this->projectRoot = $projectRoot;
        $this->avatarUpload = $avatarUpload;
        $this->coverUpload = $coverUpload;
        $this->avatarDelete = $avatarDelete;
        $this->coverDelete = $coverDelete;
    }

    /**
     * Shows the panel user create/edit form.
     *
     * @param int|null $id User id in edit mode, or null in create mode.
     * @return void
     */
    public function userEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        // User editor permission is scoped by create vs edit mode.
        if (!$this->context->requireRoutePermissionOrForbidden('user', $requiredAction)) {
            return;
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['account', 'permissions', 'profile', 'security'], 'account');
        $user = $id !== null ? $this->userRead->findById($id) : null;
        // Normalize loaded user row for theme and 2FA display fields.
        if (is_array($user)) {
            $normalizedTheme = $this->panelTheme->normalizeChoice((string) ($user['theme'] ?? 'default'), true);
            $user['theme'] = $normalizedTheme ?? 'default';

            // Edit mode enriches form data with persisted 2FA preference entries.
            if ($id !== null) {
                $preferences = $this->context->auth()->userPreferences($id);
                $user['two_factor'] = is_array($preferences['two_factor'] ?? null)
                    ? array_values((array) $preferences['two_factor'])
                    : [];
            } else {
                $user['two_factor'] = [];
            }
        }

        // Edit mode must reference an existing user record.
        if ($id !== null && $user === null) {
            $this->context->flash('error', 'User not found.');
            Redirect::redirect($this->context->panelUrl('/user'));
        }

        $groupOptions = $this->groupRead->listOptions();
        $actorIsAdmin = $this->context->auth()->panelService()->isAdmin();
        $primaryGroupId = (int) ($user['primary_group_id'] ?? 0);
        $secondaryGroupIds = array_map('intval', (array) ($user['secondary_group_ids'] ?? []));
        $bioMaxLength = max(1, (int) $this->config->get('user.bio', 500));

        $this->context->renderPanel('panel/user/edit', [
            'userRow' => $user,
            'bioMaxLength' => $bioMaxLength,
            'loginIdentifierMode' => $this->identifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'profileRoutePrefix' => $this->profileRoutePrefix(),
            'profileRoutesEnabled' => $this->profileRoutesEnabled(),
            'profileRouteSegment' => is_array($user) ? ($this->profileRouteSegment($user) ?? '') : '',
            'avatarTemplateData' => is_array($user) ? $this->avatarTemplateData((string) ($user['avatar'] ?? '')) : ['filename' => '', 'url' => '', 'thumb_url' => ''],
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'coverImageUrl' => is_array($user) ? $this->coverPublicUrl((string) ($user['cover_image'] ?? '')) : '',
            'groupOptions' => $groupOptions,
            'primaryGroupId' => $primaryGroupId,
            'secondaryGroupIds' => $secondaryGroupIds,
            'canAssignAdmin' => $actorIsAdmin,
            'canAssignConfigurationGroups' => $actorIsAdmin,
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'editorBlocks' => $this->editorBlocks,
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'user',
        ]);
    }

    /**
     * Saves one user and its group memberships.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload.
     * @return void
     */
    public function userSave(array $post, array $files): void
    {
        $this->context->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        // User save permission is scoped by create vs edit mode.
        if (!$this->context->requireRoutePermissionOrForbidden('user', $requiredAction)) {
            return;
        }

        // CSRF validation protects user save actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/user'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['account', 'permissions', 'profile', 'security'], 'account');
        $editUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/user/edit',
            $id,
            $activeTab,
            'account'
        );
        $securityTabUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/user/edit',
            $id,
            $activeTab,
            'security'
        );
        $loginIdentifierMode = $this->identifierMode();
        $usernameSubmitted = array_key_exists('username', $post);
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeIdentifier($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $bioMaxLength = max(1, (int) $this->config->get('user.bio', 500));
        $bio = $this->input->text($post['bio'] ?? null, $bioMaxLength);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->panelTheme->normalizeChoice((string) $themeRaw, true);
        $password = $this->input->text($post['password'] ?? null, 255);
        $passwordConfirm = $this->input->text($post['password_confirm'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $submittedTwoFactorMethodsPresent = isset($post['two_factor_methods_present'])
            && (string) ($post['two_factor_methods_present'] ?? '') === '1';
        $submittedTwoFactorMethodIndices = $this->normalizeSubmittedTwoFactorExistingIndices($post['two_factor_methods'] ?? null);
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';
        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';

        $existingUser = null;
        $existingTwoFactorMethods = [];
        $canUpdateTwoFactorMethods = false;
        // Edit mode loads existing user and 2FA preference state.
        if ($id !== null) {
            $existingUser = $this->userRead->findById($id);
            // Abort when edit target no longer exists.
            if ($existingUser === null) {
                $this->context->flash('error', 'User not found.');
                Redirect::redirect($this->context->panelUrl('/user'));
            }

            $existingPreferences = $this->context->auth()->userPreferences($id);
            // Existing preferences gate whether submitted 2FA methods can be merged.
            if (is_array($existingPreferences)) {
                $existingTwoFactorMethods = is_array($existingPreferences['two_factor'] ?? null)
                    ? array_values($existingPreferences['two_factor'])
                    : [];
                $canUpdateTwoFactorMethods = true;
            }
        }

        $currentAvatarPath = is_array($existingUser) && isset($existingUser['avatar']) && is_string($existingUser['avatar'])
            ? (string) $existingUser['avatar']
            : null;
        $currentCoverImage = is_array($existingUser) && isset($existingUser['cover_image']) && is_string($existingUser['cover_image'])
            ? (string) $existingUser['cover_image']
            : null;
        $currentUserString = $this->currentUserString($existingUser);

        $primaryGroupSubmitted = array_key_exists('primary_group_id', $post);
        $primaryGroupId = $this->input->int($post['primary_group_id'] ?? null, 1) ?? 0;
        // Preserve the existing primary group when an older or restricted form omitted its disabled select value.
        if ($id !== null && !$primaryGroupSubmitted && is_array($existingUser)) {
            $primaryGroupId = (int) ($existingUser['primary_group_id'] ?? 0);
        }
        /** @var mixed $secondaryGroupIdsRaw */
        $secondaryGroupIdsRaw = $post['secondary_group_ids'] ?? [];
        $secondaryGroupIds = [];
        // Secondary group selections are optional checkbox arrays.
        if (is_array($secondaryGroupIdsRaw)) {
            // Normalize secondary group ids from posted checkbox values.
            foreach ($secondaryGroupIdsRaw as $raw) {
                $parsed = $this->input->int($raw, 1);
                // Keep only valid positive integer group ids.
                if ($parsed !== null) {
                    $secondaryGroupIds[] = $parsed;
                }
            }
        }

        $groupIds = $primaryGroupId > 0
            ? array_values(array_unique(array_merge([$primaryGroupId], $secondaryGroupIds)))
            : array_values(array_unique($secondaryGroupIds));

        $groupOptions = $this->groupRead->listOptions();
        $validGroupIds = array_map(static fn (array $group): int => (int) $group['id'], $groupOptions);
        $groupIds = array_values(array_intersect($groupIds, $validGroupIds));

        $groupPermissionMasks = [];
        // Build per-group permission mask lookup for assignment policy checks.
        foreach ($groupOptions as $groupOption) {
            $groupPermissionMasks[(int) ($groupOption['id'] ?? 0)] = (int) ($groupOption['permissions'] ?? 0);
        }

        $actorIsAdmin = $this->context->auth()->panelService()->isAdmin();
        // Non-admin actors cannot newly assign the Admin group.
        if (!$actorIsAdmin) {
            $targetAlreadyHasAdmin = false;
            // Existing group memberships are needed to detect admin-group retention.
            if (is_array($existingUser)) {
                $existingGroupIds = array_map('intval', (array) ($existingUser['group_ids'] ?? []));
                $targetAlreadyHasAdmin = in_array(1, $existingGroupIds, true);
            }

            $requestedAdmin = in_array(1, $groupIds, true);
            // Block non-admin attempts to newly add Admin group membership.
            if ($requestedAdmin && !$targetAlreadyHasAdmin) {
                $this->context->flash('error', 'Only Admin users can assign the Admin group.');
                Redirect::redirect($editUrl);
            }

            // Preserve existing Admin membership when non-admin edits user groups.
            if ($targetAlreadyHasAdmin && !in_array(1, $groupIds, true)) {
                $groupIds[] = 1;
            }
        }

        // Non-admin actors cannot assign configuration-capable groups.
        if (!$actorIsAdmin) {
            $configurationGroupIds = [];
            $systemPanelBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
            // Discover groups that carry system configuration capabilities.
            foreach ($groupPermissionMasks as $groupIdKey => $mask) {
                if (($mask & $systemPanelBitsMask) !== 0) {
                    $configurationGroupIds[] = $groupIdKey;
                }
            }

            // Compare existing vs requested privileged-group assignments.
            if ($configurationGroupIds !== []) {
                $existingGroupIds = is_array($existingUser)
                    ? array_map('intval', (array) ($existingUser['group_ids'] ?? []))
                    : [];
                $existingConfigurationGroupIds = array_values(array_intersect($existingGroupIds, $configurationGroupIds));
                $requestedConfigurationGroupIds = array_values(array_intersect($groupIds, $configurationGroupIds));
                $newConfigurationAssignments = array_values(array_diff($requestedConfigurationGroupIds, $existingConfigurationGroupIds));

                // Block non-admin attempts to add new configuration-capable groups.
                if ($newConfigurationAssignments !== []) {
                    $this->context->flash('error', 'Only Admin users can assign groups with Manage System Configuration.');
                    Redirect::redirect($editUrl);
                }
            }
        }

        $usernameRequired = $loginIdentifierMode === 'username';
        // Preserve existing username when optional username was not submitted.
        if (!$usernameRequired && !$usernameSubmitted && is_array($existingUser)) {
            $username = trim((string) ($existingUser['username'] ?? ''));
            $rawUsername = $username;
        }
        $usernameInvalid = $usernameRequired
            ? !is_string($username)
            : ($rawUsername !== '' && !is_string($username));
        // Validate username/email/theme trio before any persistence actions.
        if ($usernameInvalid || $email === null || !is_string($theme)) {
            $this->context->flash(
                'error',
                $usernameRequired
                    ? 'Valid username, email, and theme are required.'
                    : 'Valid optional username, email, and theme are required.'
            );
            Redirect::redirect($editUrl);
        }

        // New users must provide an initial password meeting minimum length.
        if ($id === null && strlen($password) < 8) {
            $this->context->flash('error', 'New users require a password of at least 8 characters.');
            Redirect::redirect($editUrl);
        }

        // New-user password confirmation must match exactly.
        if ($id === null && !hash_equals($password, $passwordConfirm)) {
            $this->context->flash('error', 'Password confirmation does not match.');
            Redirect::redirect($securityTabUrl);
        }

        // Existing-user password updates must meet minimum length when supplied.
        if ($id !== null && $password !== '' && strlen($password) < 8) {
            $this->context->flash('error', 'Password must be at least 8 characters.');
            Redirect::redirect($editUrl);
        }

        // Existing-user password updates require matching confirmation.
        if ($id !== null && $password !== '' && !hash_equals($password, $passwordConfirm)) {
            $this->context->flash('error', 'Password confirmation does not match.');
            Redirect::redirect($securityTabUrl);
        }

        // Derive fallback primary group when submitted primary group is missing.
        if ($primaryGroupId < 1) {
            $fallbackGroupId = $this->groupRead->idBySlug('guest') ?? 0;
            // Use guest fallback when available.
            if ($fallbackGroupId > 0) {
                $primaryGroupId = $fallbackGroupId;
                // Ensure fallback primary group is represented in full group set.
                if (!in_array($primaryGroupId, $groupIds, true)) {
                    $groupIds = array_merge([$primaryGroupId], $groupIds);
                }
            }
        }

        // Primary + at least one group assignment is required for saved users.
        if ($primaryGroupId < 1 || $groupIds === []) {
            $this->context->flash('error', 'At least one user group is required.');
            Redirect::redirect($editUrl);
        }

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;
        $pendingAvatarUpload = null;
        $pendingAvatarExtension = null;
        $coverImage = $currentCoverImage;
        $uploadedCoverFilename = null;
        $pendingCoverUpload = null;
        $pendingCoverExtension = null;
        // Explicit avatar removal clears persisted avatar path.
        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }
        // Explicit cover removal clears persisted cover image path.
        if ($removeCover) {
            $coverImage = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasAvatarUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        // Validate/process avatar upload only when a real file was submitted.
        if ($hasAvatarUpload) {
            $avatarMaxSizeBytes = $this->avatarConfig->resolveMaxFilesizeBytes(1048576);
            $avatarMaxWidth = (int) $this->config->get('user.avatar.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('user.avatar.max_height', 500);
            $avatarAllowedExtensions = $this->avatarConfig->allowedExtensionsCsv();

            $validator = new AvatarValidator($avatarMaxSizeBytes, $avatarMaxWidth, $avatarMaxHeight, $avatarAllowedExtensions);
            /** @var array<string, mixed> $avatarUpload */
            $result = $validator->validate($avatarUpload);

            // Validator failures return early with explicit error feedback.
            if (!(bool) $result['ok']) {
                $this->context->flash('error', (string) ($result['error'] ?? 'Avatar upload failed.'));
                Redirect::redirect($editUrl);
            }

            $normalizedExtension = $this->avatarUpload->normalizeExtension((string) ($result['extension'] ?? ''));
            // Extension normalization guards unsupported avatar file types.
            if ($normalizedExtension === null) {
                $this->context->flash('error', 'Avatar upload format is not supported.');
                Redirect::redirect($editUrl);
            }

            // Existing users can store avatar immediately; new users must defer until id exists.
            if ($id !== null) {
                $storeResult = $this->avatarUpload->storeForUser($id, $avatarUpload, $normalizedExtension, $this->projectRoot);
                // Storage-layer failures are surfaced before save transaction.
                if (!(bool) ($storeResult['ok'] ?? false)) {
                    $this->context->flash('error', (string) ($storeResult['error'] ?? 'Avatar upload failed.'));
                    Redirect::redirect($editUrl);
                }

                $avatarSet = true;
                $avatarFilename = (string) ($storeResult['path'] ?? '');
                $uploadedAvatarFilename = $avatarFilename;
            } else {
                $pendingAvatarUpload = $avatarUpload;
                $pendingAvatarExtension = $normalizedExtension;
            }
        }

        $coverUpload = $files['cover_image'] ?? null;
        $hasCoverUpload = is_array($coverUpload)
            && (($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        // Validate/process cover upload only when a real file was submitted.
        if ($hasCoverUpload) {
            /** @var array<string, mixed> $coverUpload */
            $coverResult = $this->validateUserCoverUpload($coverUpload);
            if (!(bool) $coverResult['ok']) {
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Cover image upload failed.'));
                Redirect::redirect($editUrl);
            }

            $normalizedExtension = $this->avatarUpload->normalizeExtension((string) ($coverResult['extension'] ?? ''));
            // Extension normalization guards unsupported cover file types.
            if ($normalizedExtension === null) {
                $this->context->flash('error', 'Cover image upload format is not supported.');
                Redirect::redirect($editUrl);
            }

            // Existing users can store cover immediately; new users defer until id exists.
            if ($id !== null) {
                $storeResult = $this->coverUpload->storeForUser($id, $coverUpload, $normalizedExtension, $this->projectRoot);
                // Storage-layer failures are surfaced before save transaction.
                if (!(bool) ($storeResult['ok'] ?? false)) {
                    $this->context->flash('error', (string) ($storeResult['error'] ?? 'Cover image upload failed.'));
                    Redirect::redirect($editUrl);
                }

                $coverImage = (string) ($storeResult['path'] ?? '');
                $uploadedCoverFilename = $coverImage;
            } else {
                $pendingCoverUpload = $coverUpload;
                $pendingCoverExtension = $normalizedExtension;
            }
        }

        $createdUserId = null;
        // Main save flow can throw from repository and deferred upload operations.
        try {
            $savedId = $this->userWrite->save([
                'id' => $id,
                'username' => is_string($username) ? $username : '',
                'display_name' => $displayName,
                'email' => (string) $email,
                'bio' => $bio,
                'theme' => $theme,
                'password' => $password !== '' ? $password : null,
                'primary_group_id' => $primaryGroupId,
                'group_ids' => $groupIds,
                'contact_profiles' => $contactProfiles,
                'set_avatar' => $avatarSet,
                'avatar_path' => $avatarFilename,
                'cover_image' => $coverImage,
                'string_length' => (int) $this->config->get('user.string', 28),
            ]);

            // Create mode runs second save after generated user string exists.
            if ($id === null) {
                $this->userWrite->save([
                    'id' => $savedId,
                    'username' => is_string($username) ? $username : '',
                    'display_name' => $displayName,
                    'email' => (string) $email,
                    'bio' => $bio,
                    'theme' => $theme,
                    'password' => null,
                    'primary_group_id' => $primaryGroupId,
                    'group_ids' => $groupIds,
                    'contact_profiles' => $contactProfiles,
                    'set_avatar' => false,
                    'avatar_path' => null,
                    'cover_image' => $coverImage,
                    'string_length' => (int) $this->config->get('user.string', 28),
                ]);

                $createdUserId = $savedId;
                $createdUserString = $this->userRead->userStringById($savedId);
                // Generated user string is required before completing create flow.
                if ($createdUserString === null) {
                    throw new \RuntimeException('Failed to resolve generated user string.');
                }

                // Persist deferred avatar upload now that user id exists.
                if (is_array($pendingAvatarUpload) && is_string($pendingAvatarExtension)) {
                    $storeResult = $this->avatarUpload->storeForUser($savedId, $pendingAvatarUpload, $pendingAvatarExtension, $this->projectRoot);
                    // Deferred avatar store failures abort create flow.
                    if (!(bool) ($storeResult['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($storeResult['error'] ?? 'Avatar upload failed.'));
                    }

                    $avatarSet = true;
                    $avatarFilename = (string) ($storeResult['path'] ?? '');
                    $uploadedAvatarFilename = $avatarFilename;
                }

                // Persist deferred cover upload now that user id exists.
                if (is_array($pendingCoverUpload) && is_string($pendingCoverExtension)) {
                    $storeResult = $this->coverUpload->storeForUser($savedId, $pendingCoverUpload, $pendingCoverExtension, $this->projectRoot);
                    // Deferred cover store failures abort create flow.
                    if (!(bool) ($storeResult['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($storeResult['error'] ?? 'Cover image upload failed.'));
                    }

                    $coverImage = (string) ($storeResult['path'] ?? '');
                    $uploadedCoverFilename = $coverImage;
                }

                // Apply avatar/cover paths in a final save only when uploads were stored.
                if ($avatarSet || $uploadedCoverFilename !== null) {
                    $this->userWrite->save([
                        'id' => $savedId,
                        'username' => is_string($username) ? $username : '',
                        'display_name' => $displayName,
                        'email' => (string) $email,
                        'bio' => $bio,
                        'theme' => $theme,
                        'password' => null,
                        'primary_group_id' => $primaryGroupId,
                        'group_ids' => $groupIds,
                        'contact_profiles' => $contactProfiles,
                        'set_avatar' => $avatarSet,
                        'avatar_path' => $avatarFilename,
                        'cover_image' => $coverImage,
                        'string_length' => (int) $this->config->get('user.string', 28),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            // Roll back newly written avatar file on failure.
            if ($uploadedAvatarFilename !== null) {
                $this->avatarDelete->deleteFile($uploadedAvatarFilename);
            }
            // Roll back newly written cover file on failure.
            if ($uploadedCoverFilename !== null) {
                $this->coverDelete->deleteFile($uploadedCoverFilename);
            }

            // Remove partially created user records in create mode.
            if ($id === null && $createdUserId !== null) {
                // Cleanup delete failure should not mask original save failure.
                try {
                    $this->userWrite->deleteById($createdUserId);
                } catch (\Throwable) {
                    // Preserve the original save failure when cleanup also fails.
                }
            }

            $this->context->flash('error', $exception->getMessage() ?: 'Failed to save user.');
            Redirect::redirect($editUrl);
        }

        $twoFactorUpdateError = null;
        // Optional 2FA method retention applies only in edit mode with submitted marker.
        if ($id !== null && $canUpdateTwoFactorMethods && $submittedTwoFactorMethodsPresent) {
            $retainedTwoFactorMethods = [];
            // Retain only submitted indices that map to valid existing methods.
            foreach ($submittedTwoFactorMethodIndices as $methodIndex) {
                $method = $existingTwoFactorMethods[$methodIndex] ?? null;
                // Ignore malformed retained-method entries.
                if (!is_array($method)) {
                    continue;
                }

                $retainedTwoFactorMethods[] = $method;
            }

            $twoFactorUpdate = $this->authWrite->updateTwoFactorMethods($savedId, $retainedTwoFactorMethods);
            // Surface update errors without failing the overall user-save operation.
            if (!(bool) ($twoFactorUpdate['ok'] ?? false)) {
                $rawErrors = is_array($twoFactorUpdate['errors'] ?? null) ? $twoFactorUpdate['errors'] : [];
                $messages = array_map(static fn (mixed $value): string => trim((string) $value), $rawErrors);
                $messages = array_values(array_filter($messages, static fn (string $value): bool => $value !== ''));
                $twoFactorUpdateError = $messages !== []
                    ? implode(' ', $messages)
                    : 'User saved, but 2FA methods could not be updated.';
            }
        }

        // Remove replaced avatar file after successful save.
        if ($avatarSet && is_string($currentAvatarPath) && $currentAvatarPath !== '' && $currentAvatarPath !== $avatarFilename) {
            $this->avatarDelete->deleteFile($currentAvatarPath);
        }
        // Remove replaced cover file after successful save.
        if ($currentCoverImage !== null && $currentCoverImage !== '' && $currentCoverImage !== $coverImage) {
            $this->coverDelete->deleteFile($currentCoverImage);
        }

        // Report partial-success state when 2FA update failed.
        if ($twoFactorUpdateError !== null) {
            $this->context->flash('error', $twoFactorUpdateError);
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/user/edit',
                $savedId,
                $activeTab,
                'security'
            ));
        }

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/user/edit',
            $savedId,
            $activeTab,
            'account'
        ));
    }

    /**
     * Deletes one user or a set of selected users.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function userDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        // User deletion is permission-gated due destructive behavior.
        if (!$this->context->requireRoutePermissionOrForbidden('user', 'delete')) {
            return;
        }

        // CSRF validation protects user delete actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/user'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $currentUserId = $this->context->auth()->userId();

        // Single-row delete path takes precedence when id is posted.
        if ($id !== null) {
            // Prevent deleting the currently authenticated account.
            if ($currentUserId === $id) {
                $this->context->flash('error', 'You cannot delete your currently logged-in account.');
                Redirect::redirect($this->context->panelUrl('/user'));
            }

            // Repository delete may fail due relational or storage constraints.
            try {
                $this->userWrite->deleteById($id);
            } catch (\Throwable $exception) {
                $this->context->flash('error', $exception->getMessage() ?: 'Failed to delete user.');
                Redirect::redirect($this->context->panelUrl('/user'));
            }

            $this->context->flash('success', 'User deleted.');
            Redirect::redirect($this->context->panelUrl('/user'));
        }

        $selectedIds = $this->selectedIdsFromPost($post);
        // Bulk delete requires at least one selected id.
        if ($selectedIds === []) {
            $this->context->flash('error', 'No users selected.');
            Redirect::redirect($this->context->panelUrl('/user'));
        }

        $deletedCount = 0;
        $failedCount = 0;
        $skippedCurrentCount = 0;

        // Process bulk-selected ids independently for partial-success handling.
        foreach ($selectedIds as $selectedId) {
            // Skip current account if included in selection.
            if ($currentUserId !== null && $selectedId === $currentUserId) {
                $skippedCurrentCount++;
                continue;
            }

            // Continue processing remaining ids even when one delete fails.
            try {
                $this->userWrite->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        // Report successful deletes and include skipped/failed counts when relevant.
        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' user' . ($deletedCount === 1 ? '' : 's') . '.';
            // Explicitly mention when current account was skipped.
            if ($skippedCurrentCount > 0) {
                $message .= ' Skipped your currently logged-in account.';
            }
            // Include failed-count suffix for partial bulk outcomes.
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected user' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->context->flash('success', $message);
        } else {
            // Distinguish "only skipped self" from generic bulk-delete failure.
            if ($skippedCurrentCount > 0 && $failedCount === 0) {
                $this->context->flash('error', 'No users deleted because your currently logged-in account cannot be deleted.');
            } else {
                $this->context->flash('error', 'Failed to delete selected users.');
            }
        }

        Redirect::redirect($this->context->panelUrl('/user'));
    }

    /**
     * Resolves the configured panel login identifier mode.
     *
     * @return string `email` or `username`.
     */
    private function identifierMode(): string
    {
        return $this->loginIdentifier->modeFromConfig($this->config);
    }

    /**
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * @param string $rawValue User-submitted identifier candidate.
     * @return string|null Canonical username/email value, or null when invalid.
     */
    private function normalizeIdentifier(string $rawValue): ?string
    {
        return $this->loginIdentifier->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * Returns configured public profile route prefix.
     *
     * @return string Public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->groupParser->profileRoutePrefix();
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     *
     * @return bool True when profile routes are enabled.
     */
    private function profileRoutesEnabled(): bool
    {
        return $this->groupParser->profileRouteEnabled();
    }

    /**
     * Returns one current persisted user string when available.
     *
     * @param array<string, mixed>|null $user User row payload.
     * @return string|null Canonical user string, or null when unavailable.
     */
    private function currentUserString(?array $user): ?string
    {
        $userString = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($user['string'] ?? ''))) ?? '';
        return $userString !== '' ? $userString : null;
    }

    /**
     * Returns one public profile route segment for a user row.
     *
     * @param array<string, mixed> $user User row payload.
     * @return string|null Public route segment, or null when unavailable.
     */
    private function profileRouteSegment(array $user): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        // Profile route segment requires a valid persisted user id.
        if ($userId <= 0) {
            return null;
        }

        return match ($this->groupParser->profileSelector()) {
            'string' => $this->currentUserString($user),
            'username' => $this->normalizeIdentifier((string) ($user['username'] ?? '')),
            default => (string) $userId,
        };
    }

    /**
     * Returns normalized profile-contact option map from runtime config.
     *
     * @return array<string, array{label: string, prefix: string}>
     */
    private function profileContactOptions(): array
    {
        return $this->profileParser->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->profileParser->defaultOptions())
        );
    }

    /**
     * Normalizes submitted profile-contact rows from panel forms.
     *
     * @param mixed $rawProfiles Submitted profile-contact payload.
     * @param array<string, array{label: string, prefix: string}> $allowedOptions Allowed option map.
     * @return array<int, array{type: string, value: string}> Normalized contact-profile rows.
     */
    private function normalizeSubmittedContactProfiles(mixed $rawProfiles, array $allowedOptions): array
    {
        return $this->profileParser->normalizeSubmittedProfiles($rawProfiles, $allowedOptions);
    }

    /**
     * Returns 2FA type options for the panel user editor.
     *
     * @return array<string, string>
     */
    private function twoFactorTypeOptions(): array
    {
        return $this->form2fa->typeOptions();
    }

    /**
     * Normalizes retained 2FA method indices from the user editor.
     *
     * @param mixed $rawMethods Submitted 2FA payload.
     * @return array<int, int> Existing method indices to retain.
     */
    private function normalizeSubmittedTwoFactorExistingIndices(mixed $rawMethods): array
    {
        return $this->form2fa->normalizeSubmittedExistingIndices($rawMethods);
    }

    /**
     * Returns one config-driven avatar upload note for panel forms.
     *
     * @return string Human-readable avatar upload limit note.
     */
    private function avatarUploadLimitsNote(): string
    {
        return $this->avatarConfig->uploadLimitsNote();
    }

    /**
     * Returns one cover-image public URL for the panel editor preview.
     *
     * @param string $coverValue Stored cover-image value.
     * @return string Public URL or empty string.
     */
    private function coverPublicUrl(string $coverValue): string
    {
        return $this->coverConfig->publicUrl($coverValue);
    }

    /**
     * Returns avatar display metadata for panel templates.
     *
     * @param string $avatarPath Stored avatar path value.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateData(string $avatarPath): array
    {
        return $this->avatarConfig->templateData($avatarPath);
    }

    /**
     * Validates one cover-image upload using the shared image policy.
     *
     * @param array<string, mixed> $upload Uploaded cover payload.
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    private function validateUserCoverUpload(array $upload): array
    {
        $maxBytes = $this->mediaConfig->resolveMaxFilesizeBytes('images', 10485760);
        $allowedExtensions = (string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png');
        $policy = new AvatarValidator($maxBytes, 10000, 10000, $allowedExtensions);
        return $policy->validate($upload);
    }

    /**
     * Normalizes selected checkbox ids from one bulk-action form payload.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param string $key Form key holding selected ids.
     * @return array<int, int> Normalized selected ids.
     */
    private function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        $raw = $post[$key] ?? [];
        // Selected-id payload must be array-shaped checkbox values.
        if (!is_array($raw)) {
            return [];
        }

        $selected = [];
        // Normalize and deduplicate selected ids through associative map keys.
        foreach ($raw as $candidate) {
            $id = $this->input->int($candidate, 1);
            // Keep only valid positive integer identifiers.
            if ($id !== null) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
