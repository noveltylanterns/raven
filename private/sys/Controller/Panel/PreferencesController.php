<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PreferencesController.php
 * Split panel preferences controller for current-user account settings.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Raven\Core\Config;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\Panel\PanelTwoFactorPreferencesService;
use Raven\Lib\Auth\PasswordChangePolicy;
use Raven\Lib\Media\Panel\AvatarUploadService;
use Raven\Lib\Media\Panel\AvatarValidationPolicy;
use Raven\Lib\Media\Panel\AvatarValidator;
use Raven\Lib\Media\Panel\UserMediaPathService;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\PanelMediaConfigService;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\QrCodeService;
use Raven\Lib\Security\WebAuthnService;
use Raven\Lib\Transport\Response;

use Raven\Lib\Transport\Redirect;

/**
 * Handles the current user's panel preferences routes.
 */
final class PreferencesController
{
    private const SESSION_WEBAUTHN_PREFERENCES_CHALLENGE = '_raven_preferences_webauthn_challenge';

    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    private LoginIdentifierResolver $loginIdentifierResolver;
    private EditorTabs $editorTabs;
    private Editor $editor;
    private PanelMediaConfigService $panelMediaConfigService;
    private ProfileContactService $profileContactService;
    private PanelTwoFactorPreferencesService $panelTwoFactorPreferencesService;
    private AvatarUploadService $avatarUploadService;
    private UserMediaPathService $userMediaPathService;
    private PasswordChangePolicy $passwordChangePolicy;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for user-media storage helpers.
     * @param LoginIdentifierResolver $loginIdentifierResolver Shared login-identifier normalization helper.
     * @param EditorTabs $editorTabs Shared editor-tab normalization helper.
     * @param Editor $editor Shared panel editor utility methods (theme normalization).
     * @param PanelMediaConfigService $panelMediaConfigService Shared media-limit helper.
     * @param ProfileContactService $profileContactService Shared profile-contact normalizer.
     * @param PanelTwoFactorPreferencesService $panelTwoFactorPreferencesService Shared 2FA helper set.
     * @param AvatarUploadService $avatarUploadService Shared sanitized avatar/cover upload helper.
     * @param UserMediaPathService $userMediaPathService Shared user-media path resolver.
     * @param PasswordChangePolicy $passwordChangePolicy Shared password validation policy.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        LoginIdentifierResolver $loginIdentifierResolver,
        EditorTabs $editorTabs,
        Editor $editor,
        PanelMediaConfigService $panelMediaConfigService,
        ProfileContactService $profileContactService,
        PanelTwoFactorPreferencesService $panelTwoFactorPreferencesService,
        AvatarUploadService $avatarUploadService,
        UserMediaPathService $userMediaPathService,
        PasswordChangePolicy $passwordChangePolicy
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->loginIdentifierResolver = $loginIdentifierResolver;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->panelMediaConfigService = $panelMediaConfigService;
        $this->profileContactService = $profileContactService;
        $this->panelTwoFactorPreferencesService = $panelTwoFactorPreferencesService;
        $this->avatarUploadService = $avatarUploadService;
        $this->userMediaPathService = $userMediaPathService;
        $this->passwordChangePolicy = $passwordChangePolicy;
    }

    /**
     * Shows the user preferences form for the currently logged-in user.
     *
     * @return void
     */
    public function preferences(): void
    {
        $this->context->requirePanelLogin();

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            Redirect::redirect($this->context->panelUrl('/login'));
        }

        $preferences = $this->context->auth()->userPreferences($userId);
        if ($preferences === null) {
            $this->context->flash('error', 'Unable to load your preferences.');
            Redirect::redirect($this->context->panelUrl('/'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['account', 'profile', 'security'], 'account');
        $normalizedTheme = $this->editor->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true);
        $preferences['theme'] = $normalizedTheme ?? 'default';
        $preferences['two_factor'] = $this->prepareTwoFactorMethodsForView(
            is_array($preferences['two_factor'] ?? null) ? $preferences['two_factor'] : [],
            (string) ($preferences['email'] ?? '')
        );
        $bioMaxLength = max(1, (int) $this->config->get('user.bio', 500));

        $this->context->renderPanel('panel/preferences', [
            'preferences' => $preferences,
            'bioMaxLength' => $bioMaxLength,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'avatarTemplateData' => $this->avatarTemplateData((string) ($preferences['avatar'] ?? '')),
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'coverImageUrl' => $this->coverPublicUrl((string) ($preferences['cover_image'] ?? '')),
            'activeTab' => $activeTab,
            'section' => 'preferences',
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
        ]);
    }

    /**
     * Saves user preferences for the currently logged-in user.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload.
     * @return void
     */
    public function preferencesSave(array $post, array $files): void
    {
        $this->context->requirePanelLogin();
        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['account', 'profile', 'security'], 'account');
        $preferencesUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/preferences',
            null,
            $activeTab,
            'account'
        );

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            Redirect::redirect($this->context->panelUrl('/login'));
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($preferencesUrl);
        }

        $current = $this->context->auth()->userPreferences($userId);
        if ($current === null) {
            $this->context->flash('error', 'Unable to load your current profile data.');
            Redirect::redirect($preferencesUrl);
        }

        $loginIdentifierMode = $this->panelLoginIdentifierMode();
        $usernameSubmitted = array_key_exists('username', $post);
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeUserIdentifierValue($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $bioMaxLength = max(1, (int) $this->config->get('user.bio', 500));
        $bio = $this->input->text($post['bio'] ?? null, $bioMaxLength);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->editor->normalizePanelThemeChoice((string) $themeRaw, true);
        $timezoneRaw = trim((string) $this->input->text($post['timezone'] ?? null, 64));
        $newPassword = $this->input->text($post['new_password'] ?? null, 255);
        $confirmNewPassword = $this->input->text($post['confirm_new_password'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $twoFactorMethods = $this->normalizeSubmittedTwoFactorMethods(
            $post['two_factor_methods'] ?? null,
            (string) ($current['email'] ?? '')
        );
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';
        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $currentUserString = $this->currentUserString($current);
        $currentCoverImage = isset($current['cover_image']) && is_string($current['cover_image'])
            ? (string) $current['cover_image']
            : null;

        $timezone = '';
        if ($timezoneRaw !== '') {
            if (!in_array($timezoneRaw, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                $timezone = '';
            } else {
                $timezone = $timezoneRaw;
            }
        }

        $errors = [];
        $usernameRequired = $loginIdentifierMode === 'username';
        if (!$usernameRequired && !$usernameSubmitted) {
            $username = trim((string) ($current['username'] ?? ''));
            $rawUsername = $username;
        }

        if ($usernameRequired && !is_string($username)) {
            $errors[] = 'Username must be 3-50 chars and contain only a-z, 0-9, _, -, .';
        }

        if (!$usernameRequired && $rawUsername !== '' && !is_string($username)) {
            $errors[] = 'Optional username must be 3-50 chars and contain only a-z, 0-9, _, -, .';
        }

        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }

        if (!is_string($theme)) {
            $errors[] = 'Theme selection is invalid.';
        }

        if ($timezoneRaw !== '' && $timezone === '') {
            $errors[] = 'Timezone selection is invalid.';
        }

        $errors = array_merge(
            $errors,
            $this->passwordChangePolicy->validateNewPasswordChange($newPassword, $confirmNewPassword, 8)
        );

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;
        $coverImage = $currentCoverImage;
        $uploadedCoverFilename = null;

        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }
        if ($removeCover) {
            $coverImage = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasUpload) {
            $avatarMaxSizeBytes = $this->panelMediaConfigService->resolveMediaMaxFilesizeBytes('avatars', 1048576);
            $avatarMaxWidth = (int) $this->config->get('user.avatar.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('user.avatar.max_height', 500);
            $avatarAllowedExtensions = $this->panelMediaConfigService->resolveAvatarAllowedExtensionsCsv();

            $validator = new AvatarValidator(
                $avatarMaxSizeBytes,
                $avatarMaxWidth,
                $avatarMaxHeight,
                $avatarAllowedExtensions
            );
            /** @var array<string, mixed> $avatarUpload */
            $result = $validator->validate($avatarUpload);

            if (!(bool) $result['ok']) {
                $errors[] = (string) ($result['error'] ?? 'Avatar upload failed.');
            } else {
                $normalizedExtension = $this->avatarUploadService->normalizeExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Avatar upload format is not supported.';
                } else {
                    if ($currentUserString === null) {
                        $errors[] = 'User string is missing for this account.';
                    } else {
                        $avatarsDir = $this->userMediaPathService->avatarStorageDirectory($this->root);
                        $avatarFilename = $this->userMediaPathService->avatarFilenameForString($currentUserString, $normalizedExtension);
                        $destination = $avatarsDir . '/' . $avatarFilename;

                        $storeError = $this->avatarUploadService->storeSanitizedUpload($avatarUpload, $destination);
                        if ($storeError !== null) {
                            $errors[] = $storeError;
                        } else {
                            $avatarSet = true;
                            $uploadedAvatarFilename = $avatarFilename;
                        }
                    }
                }
            }
        }

        $coverUpload = $files['cover_image'] ?? null;
        $hasCoverUpload = is_array($coverUpload)
            && (($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasCoverUpload) {
            /** @var array<string, mixed> $coverUpload */
            $result = $this->validateUserCoverUpload($coverUpload);

            if (!(bool) $result['ok']) {
                $errors[] = (string) ($result['error'] ?? 'Cover image upload failed.');
            } else {
                $normalizedExtension = $this->avatarUploadService->normalizeExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Cover image upload format is not supported.';
                } elseif ($currentUserString === null) {
                    $errors[] = 'User string is missing for this account.';
                } else {
                    $coversDir = $this->userMediaPathService->coverStorageDirectory($this->root);
                    $coverImage = $this->userMediaPathService->coverFilenameForString($currentUserString, $normalizedExtension);
                    $destination = $coversDir . '/' . $coverImage;

                    $storeError = $this->avatarUploadService->storeSanitizedImageUpload($coverUpload, $destination);
                    if ($storeError !== null) {
                        $errors[] = $storeError;
                    } else {
                        $uploadedCoverFilename = $coverImage;
                    }
                }
            }
        }

        if ($errors !== []) {
            if ($uploadedAvatarFilename !== null) {
                $this->userMediaPathService->deleteAvatarFile($this->root, $uploadedAvatarFilename);
            }
            if ($uploadedCoverFilename !== null) {
                $this->userMediaPathService->deleteCoverFile($this->root, $uploadedCoverFilename);
            }

            $this->context->flash('error', implode(' ', $errors));
            Redirect::redirect($preferencesUrl);
        }

        $update = $this->context->auth()->updateUserPreferences($userId, [
            'username' => is_string($username) ? $username : '',
            'display_name' => $displayName,
            'email' => (string) $email,
            'bio' => $bio,
            'theme' => $theme,
            'timezone' => $timezone,
            'password' => $newPassword !== '' ? $newPassword : null,
            'contact_profiles' => $contactProfiles,
            'two_factor_methods' => $twoFactorMethods,
            'set_avatar' => $avatarSet,
            'avatar_path' => $avatarFilename,
            'cover_image' => $coverImage,
        ]);

        if (!$update['ok']) {
            if ($uploadedAvatarFilename !== null) {
                $this->userMediaPathService->deleteAvatarFile($this->root, $uploadedAvatarFilename);
            }
            if ($uploadedCoverFilename !== null) {
                $this->userMediaPathService->deleteCoverFile($this->root, $uploadedCoverFilename);
            }

            $this->context->flash('error', implode(' ', $update['errors']));
            Redirect::redirect($preferencesUrl);
        }

        $oldAvatar = $current['avatar'] ?? null;
        if (is_string($oldAvatar) && $oldAvatar !== '' && $oldAvatar !== $avatarFilename && $avatarSet) {
            $this->userMediaPathService->deleteAvatarFile($this->root, $oldAvatar);
        }
        if ($currentCoverImage !== null && $currentCoverImage !== '' && $currentCoverImage !== $coverImage) {
            $this->userMediaPathService->deleteCoverFile($this->root, $currentCoverImage);
        }

        $this->context->auth()->markTwoFactorVerified($userId);

        $this->context->flash('success', 'User preferences updated.');
        Redirect::redirect($preferencesUrl);
    }

    /**
     * Returns TOTP setup details for the preferences 2FA flow.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function preferencesTotpSetup(array $post): void
    {
        $this->context->requirePanelLogin();

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->context->auth()->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $payload = $this->panelTwoFactorPreferencesService->buildTotpSetupPayload(
            $post['secret'] ?? '',
            (string) ($preferences['email'] ?? ''),
            $this->totpIssuer()
        );
        if (!(bool) ($payload['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($payload['message'] ?? 'Unable to generate a TOTP secret.')],
                500
            );
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'secret' => (string) ($payload['secret'] ?? ''),
            'issuer' => (string) ($payload['issuer'] ?? $this->totpIssuer()),
            'account' => (string) ($payload['account'] ?? 'account@local'),
            'provisioning_uri' => (string) ($payload['provisioning_uri'] ?? ''),
            'qr_data_uri' => QrCodeService::dataUriSvgBase64((string) ($payload['provisioning_uri'] ?? ''), 220),
        ], 200);
    }

    /**
     * Returns one generated 12-word recovery phrase for preferences 2FA flow.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function preferencesRecoveryCodeGenerate(array $post): void
    {
        $this->context->requirePanelLogin();

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $recoveryCode = $this->panelTwoFactorPreferencesService->generateRecoveryPhrase(12);
        if (!is_string($recoveryCode)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to generate a recovery phrase.'], 500);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'recovery_code' => $recoveryCode,
        ], 200);
    }

    /**
     * Returns WebAuthn registration options for current-user preferences flow.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function preferencesWebauthnCreateOptions(array $post): void
    {
        $this->context->requirePanelLogin();

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->context->auth()->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $excludeCredentialIds = $this->panelTwoFactorPreferencesService->collectWebauthnExcludeCredentialIds(
            (array) ($preferences['two_factor'] ?? []),
            $post['exclude_credential_ids'] ?? null,
            20
        );

        $requireUserVerification = isset($post['require_user_verification'])
            && (string) ($post['require_user_verification'] ?? '') === '1';

        $webAuthn = $this->createWebAuthnServer();
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        $userIdentity = $this->panelTwoFactorPreferencesService->resolveWebauthnUserIdentity($preferences, $userId);
        $username = (string) ($userIdentity['username'] ?? ('user-' . $userId));
        $displayName = (string) ($userIdentity['display_name'] ?? $username);

        try {
            $options = $webAuthn->getCreateArgs(
                (string) $userId,
                $username,
                $displayName,
                60,
                false,
                $requireUserVerification,
                null,
                $excludeCredentialIds
            );
            $_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE] = $webAuthn->getChallenge()->getBinaryString();
            $this->jsonResponse(['ok' => true, 'options' => $options], 200);
        } catch (WebAuthnException) {
            $this->jsonResponse(['ok' => false, 'message' => 'Failed to initialize security key registration.'], 500);
        } catch (\Throwable) {
            $this->jsonResponse(['ok' => false, 'message' => 'Failed to initialize security key registration.'], 500);
        }
    }

    /**
     * Verifies WebAuthn registration response in current-user preferences flow.
     *
     * @param array<string, mixed> $post Submitted registration payload.
     * @return void
     */
    public function preferencesWebauthnRegister(array $post): void
    {
        $this->context->requirePanelLogin();

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->context->auth()->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $challenge = $_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE] ?? null;
        if (!is_string($challenge) || $challenge === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Registration challenge is missing.'], 400);
            return;
        }

        $clientDataJSON = base64_decode((string) ($post['clientDataJSON'] ?? ''), true);
        $attestationObject = base64_decode((string) ($post['attestationObject'] ?? ''), true);
        if (
            !is_string($clientDataJSON) || $clientDataJSON === ''
            || !is_string($attestationObject) || $attestationObject === ''
        ) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid WebAuthn registration payload.'], 400);
            return;
        }

        $webAuthn = $this->createWebAuthnServer();
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        try {
            $result = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);

            $credentialIdBinary = null;
            if ($result->credentialId instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
                $credentialIdBinary = $result->credentialId->getBinaryString();
            } elseif (is_string($result->credentialId ?? null) && $result->credentialId !== '') {
                $credentialIdBinary = (string) $result->credentialId;
            }

            if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
                $this->jsonResponse(['ok' => false, 'message' => 'Registration did not return a credential id.'], 400);
                return;
            }

            $credentialPublicKey = trim((string) ($result->credentialPublicKey ?? ''));
            if ($credentialPublicKey === '') {
                $this->jsonResponse(['ok' => false, 'message' => 'Registration did not return a credential key.'], 400);
                return;
            }

            $signatureCounter = (int) ($result->signatureCounter ?? 0);
            if ($signatureCounter < 0) {
                $signatureCounter = 0;
            }

            $this->jsonResponse([
                'ok' => true,
                'credential_id' => base64_encode($credentialIdBinary),
                'credential_public_key' => $credentialPublicKey,
                'signature_counter' => $signatureCounter,
            ], 200);
        } catch (WebAuthnException) {
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);
            $this->jsonResponse(['ok' => false, 'message' => 'Security key registration failed.'], 400);
        } catch (\Throwable) {
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);
            $this->jsonResponse(['ok' => false, 'message' => 'Security key registration failed.'], 400);
        }
    }

    /**
     * Resolves configured panel login identifier mode.
     *
     * @return string `email` or `username`.
     */
    private function panelLoginIdentifierMode(): string
    {
        return $this->loginIdentifierResolver->modeFromConfig($this->config);
    }

    /**
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * @param string $rawValue User-submitted identifier candidate.
     * @return string|null Canonical username/email value, or null when invalid.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->loginIdentifierResolver->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * Returns normalized profile-contact option map from runtime config.
     *
     * @return array<string, array{label: string, prefix: string}>
     */
    private function profileContactOptions(): array
    {
        return $this->profileContactService->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->profileContactService->defaultOptions())
        );
    }

    /**
     * Normalizes submitted profile-contact rows from the preferences form.
     *
     * @param mixed $rawProfiles Submitted profile-contact payload.
     * @param array<string, array{label: string, prefix: string}> $allowedOptions Allowed option map.
     * @return array<int, array{type: string, value: string}> Normalized contact-profile rows.
     */
    private function normalizeSubmittedContactProfiles(mixed $rawProfiles, array $allowedOptions): array
    {
        return $this->profileContactService->normalizeSubmittedProfiles($rawProfiles, $allowedOptions);
    }

    /**
     * Normalizes submitted 2FA methods from the preferences form.
     *
     * @param mixed $rawMethods Submitted 2FA payload.
     * @param string $fallbackEmail Fallback account email for default 2FA entries.
     * @return array<int, array<string, mixed>> Normalized 2FA methods.
     */
    private function normalizeSubmittedTwoFactorMethods(mixed $rawMethods, string $fallbackEmail): array
    {
        return $this->panelTwoFactorPreferencesService->normalizeSubmittedMethods(
            $rawMethods,
            $fallbackEmail,
            $this->totpIssuer()
        );
    }

    /**
     * Prepares stored 2FA methods for panel view rendering.
     *
     * @param array<int, array<string, mixed>> $methods Stored 2FA methods.
     * @param string $fallbackEmail Fallback account email.
     * @return array<int, array<string, mixed>> View-ready 2FA methods.
     */
    private function prepareTwoFactorMethodsForView(array $methods, string $fallbackEmail): array
    {
        return $this->panelTwoFactorPreferencesService->prepareMethodsForView(
            $methods,
            $fallbackEmail,
            $this->totpIssuer()
        );
    }

    /**
     * Returns 2FA type options for the preferences editor.
     *
     * @return array<string, string>
     */
    private function twoFactorTypeOptions(): array
    {
        return $this->panelTwoFactorPreferencesService->typeOptions();
    }

    /**
     * Returns one config-driven avatar upload note for preferences forms.
     *
     * @return string Human-readable avatar upload limit note.
     */
    private function avatarUploadLimitsNote(): string
    {
        return $this->panelMediaConfigService->avatarUploadLimitsNote();
    }

    /**
     * Returns one cover-image public URL for the preferences preview.
     *
     * @param string $coverValue Stored cover-image value.
     * @return string Public URL or empty string.
     */
    private function coverPublicUrl(string $coverValue): string
    {
        return $this->userMediaPathService->coverPublicUrl($this->root, $coverValue);
    }

    /**
     * Returns avatar display metadata for preferences templates.
     *
     * @param string $avatarPath Stored avatar path value.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateData(string $avatarPath): array
    {
        return $this->userMediaPathService->avatarTemplateData($this->root, $avatarPath);
    }

    /**
     * Returns one current persisted user string when available.
     *
     * @param array<string, mixed>|null $user User preference row payload.
     * @return string|null Canonical user string, or null when unavailable.
     */
    private function currentUserString(?array $user): ?string
    {
        $userString = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($user['string'] ?? ''))) ?? '';
        return $userString !== '' ? $userString : null;
    }

    /**
     * Validates one cover-image upload using the shared image policy.
     *
     * @param array<string, mixed> $upload Uploaded cover payload.
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    private function validateUserCoverUpload(array $upload): array
    {
        $maxBytes = $this->panelMediaConfigService->resolveMediaMaxFilesizeBytes('images', 10485760);
        $allowedExtensions = (string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png');
        $policy = new AvatarValidationPolicy($maxBytes, 10000, 10000, $allowedExtensions);
        return $policy->validate($upload);
    }

    /**
     * Resolves the configured TOTP issuer label.
     *
     * @return string TOTP issuer label.
     */
    private function totpIssuer(): string
    {
        return $this->panelTwoFactorPreferencesService->resolveTotpIssuer(
            (string) $this->config->get('site.name', 'Raven CMS')
        );
    }

    /**
     * Creates the WebAuthn server runtime for current site config.
     *
     * @return WebAuthn|null WebAuthn server instance, or null when unavailable.
     */
    private function createWebAuthnServer(): ?WebAuthn
    {
        return WebAuthnService::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $_SERVER
        );
    }

    /**
     * Streams one JSON response payload for preferences AJAX endpoints.
     *
     * @param array<string, mixed> $payload Response payload.
     * @param int $status HTTP status code.
     * @return void
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        Response::json($payload, $status, true);
    }
}
