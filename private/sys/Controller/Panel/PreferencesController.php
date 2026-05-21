<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PreferencesController.php
 * Split panel preferences controller for current-user account settings.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use lbuchs\WebAuthn\WebAuthn as VendorWebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Raven\Core\Config;
use Raven\Core\Repository\AuthWrite;
use Raven\Core\Repository\UserRead;
use Raven\Lib\Auth\LoginIdentifier;
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
use Raven\Lib\Security\PasswordValidator;
use Raven\Lib\Security\WebAuthn;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Response;
use Raven\Lib\View\Form2fa;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\Theme as PanelTheme;
use Raven\Lib\View\Preferences as PreferencesView;

use Raven\Lib\View\Qr;

/**
 * Handles the current user's panel preferences routes.
 */
final class PreferencesController
{
    private const SESSION_WEBAUTHN_PREFERENCES_CHALLENGE = '_raven_preferences_webauthn_challenge';

    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private LoginIdentifier $loginIdentifier;
    private EditorTabs $editorTabs;
    private PanelTheme $panelTheme;
    private EditorBlocks $editorBlocks;
    private AvatarConfig $avatarConfig;
    private MediaConfig $mediaConfig;
    private UserProfileParser $profileParser;
    private Form2fa $form2fa;
    private CoverConfig $coverConfig;
    private PasswordValidator $passwordValidator;
    private UserRead $userRead;
    private AuthWrite $authWrite;
    private string $projectRoot;
    private AvatarUpload $avatarUpload;
    private CoverUpload $coverUpload;
    private AvatarDelete $avatarDelete;
    private CoverDelete $coverDelete;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param LoginIdentifier $loginIdentifier Shared login-identifier normalization helper.
     * @param EditorTabs $editorTabs Shared editor-tab normalization helper.
     * @param EditorBlocks $editorBlocks Shared repeater-block view helper for modular panel rows.
     * @param AvatarConfig $avatarConfig Shared avatar-limit and template-data helper.
     * @param MediaConfig $mediaConfig Shared non-avatar media-limit helper.
     * @param UserProfileParser $profileParser Shared profile-contact normalizer.
     * @param Form2fa $form2fa Shared 2FA helper set.
     * @param CoverConfig $coverConfig Shared user cover-image URL resolver.
     * @param PasswordValidator $passwordValidator Shared password validation policy.
     * @param UserRead $userRead User repository read side for uniqueness checks.
     * @param AuthWrite $authWrite Auth-user write repository for preference persistence.
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
        LoginIdentifier $loginIdentifier,
        EditorTabs $editorTabs,
        EditorBlocks $editorBlocks,
        AvatarConfig $avatarConfig,
        MediaConfig $mediaConfig,
        UserProfileParser $profileParser,
        Form2fa $form2fa,
        CoverConfig $coverConfig,
        PasswordValidator $passwordValidator,
        UserRead $userRead,
        AuthWrite $authWrite,
        string $projectRoot,
        AvatarUpload $avatarUpload,
        CoverUpload $coverUpload,
        AvatarDelete $avatarDelete,
        CoverDelete $coverDelete
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->loginIdentifier = $loginIdentifier;
        $this->editorTabs = $editorTabs;
        $this->panelTheme = new PanelTheme();
        $this->editorBlocks = $editorBlocks;
        $this->avatarConfig = $avatarConfig;
        $this->mediaConfig = $mediaConfig;
        $this->profileParser = $profileParser;
        $this->form2fa = $form2fa;
        $this->coverConfig = $coverConfig;
        $this->passwordValidator = $passwordValidator;
        $this->userRead = $userRead;
        $this->authWrite = $authWrite;
        $this->projectRoot = $projectRoot;
        $this->avatarUpload = $avatarUpload;
        $this->coverUpload = $coverUpload;
        $this->avatarDelete = $avatarDelete;
        $this->coverDelete = $coverDelete;
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
        $normalizedTheme = $this->panelTheme->normalizeChoice((string) ($preferences['theme'] ?? 'default'), true);
        $preferences['theme'] = $normalizedTheme ?? 'default';
        $preferences['two_factor'] = $this->prepareTwoFactorMethodsForView(
            is_array($preferences['two_factor'] ?? null) ? $preferences['two_factor'] : [],
            (string) ($preferences['email'] ?? '')
        );
        $bioMaxLength = max(1, (int) $this->config->get('user.bio', 500));

        $this->context->renderPanel('panel/preferences', [
            'preferences' => $preferences,
            'bioMaxLength' => $bioMaxLength,
            'loginIdentifierMode' => $this->identifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'avatarTemplateData' => $this->avatarTemplateData((string) ($preferences['avatar'] ?? '')),
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'coverImageUrl' => $this->coverPublicUrl((string) ($preferences['cover_image'] ?? '')),
            'editorBlocks' => $this->editorBlocks,
            'activeTab' => $activeTab,
            'section' => 'preferences',
            'csrfField' => $this->context->csrf()->field(),
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
            $this->passwordValidator->validateNewPass($newPassword, $confirmNewPassword, 8)
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
            $avatarMaxSizeBytes = $this->avatarConfig->resolveMaxFilesizeBytes(1048576);
            $avatarMaxWidth = (int) $this->config->get('user.avatar.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('user.avatar.max_height', 500);
            $avatarAllowedExtensions = $this->avatarConfig->allowedExtensionsCsv();

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
                $normalizedExtension = $this->avatarUpload->normalizeExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Avatar upload format is not supported.';
                } else {
                    $storeResult = $this->avatarUpload->storeForUser($userId, $avatarUpload, $normalizedExtension, $this->projectRoot);
                    if (!(bool) ($storeResult['ok'] ?? false)) {
                        $errors[] = (string) ($storeResult['error'] ?? 'Avatar upload failed.');
                    } else {
                        $avatarSet = true;
                        $avatarFilename = (string) ($storeResult['path'] ?? '');
                        $uploadedAvatarFilename = $avatarFilename;
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
                $normalizedExtension = $this->avatarUpload->normalizeExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Cover image upload format is not supported.';
                } else {
                    $storeResult = $this->coverUpload->storeForUser($userId, $coverUpload, $normalizedExtension, $this->projectRoot);
                    if (!(bool) ($storeResult['ok'] ?? false)) {
                        $errors[] = (string) ($storeResult['error'] ?? 'Cover image upload failed.');
                    } else {
                        $coverImage = (string) ($storeResult['path'] ?? '');
                        $uploadedCoverFilename = $coverImage;
                    }
                }
            }
        }

        if ($errors !== []) {
            if ($uploadedAvatarFilename !== null) {
                $this->avatarDelete->deleteFile($uploadedAvatarFilename);
            }
            if ($uploadedCoverFilename !== null) {
                $this->coverDelete->deleteFile($uploadedCoverFilename);
            }

            $this->context->flash('error', implode(' ', $errors));
            Redirect::redirect($preferencesUrl);
        }

        $update = PreferencesView::updateUserPreferences($userId, [
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
        ], $this->userRead, $this->authWrite);

        if (!$update['ok']) {
            if ($uploadedAvatarFilename !== null) {
                $this->avatarDelete->deleteFile($uploadedAvatarFilename);
            }
            if ($uploadedCoverFilename !== null) {
                $this->coverDelete->deleteFile($uploadedCoverFilename);
            }

            $this->context->flash('error', implode(' ', $update['errors']));
            Redirect::redirect($preferencesUrl);
        }

        $oldAvatar = $current['avatar'] ?? null;
        if (is_string($oldAvatar) && $oldAvatar !== '' && $oldAvatar !== $avatarFilename && $avatarSet) {
            $this->avatarDelete->deleteFile($oldAvatar);
        }
        if ($currentCoverImage !== null && $currentCoverImage !== '' && $currentCoverImage !== $coverImage) {
            $this->coverDelete->deleteFile($currentCoverImage);
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

        $payload = $this->form2fa->buildTotpSetupPayload(
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
            'qr_data_uri' => Qr::dataUriSvgBase64((string) ($payload['provisioning_uri'] ?? ''), 220),
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

        $recoveryCode = $this->form2fa->generateRecoveryPhrase(12);
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

        $excludeCredentialIds = $this->form2fa->collectWebauthnExcludeCredentialIds(
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

        $userIdentity = $this->form2fa->resolveWebauthnUserIdentity($preferences, $userId);
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
     * Normalizes submitted profile-contact rows from the preferences form.
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
     * Normalizes submitted 2FA methods from the preferences form.
     *
     * @param mixed $rawMethods Submitted 2FA payload.
     * @param string $fallbackEmail Fallback account email for default 2FA entries.
     * @return array<int, array<string, mixed>> Normalized 2FA methods.
     */
    private function normalizeSubmittedTwoFactorMethods(mixed $rawMethods, string $fallbackEmail): array
    {
        return $this->form2fa->normalizeSubmittedMethods(
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
        return $this->form2fa->prepareMethodsForView(
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
        return $this->form2fa->typeOptions();
    }

    /**
     * Returns one config-driven avatar upload note for preferences forms.
     *
     * @return string Human-readable avatar upload limit note.
     */
    private function avatarUploadLimitsNote(): string
    {
        return $this->avatarConfig->uploadLimitsNote();
    }

    /**
     * Returns one cover-image public URL for the preferences preview.
     *
     * @param string $coverValue Stored cover-image value.
     * @return string Public URL or empty string.
     */
    private function coverPublicUrl(string $coverValue): string
    {
        return $this->coverConfig->publicUrl($coverValue);
    }

    /**
     * Returns avatar display metadata for preferences templates.
     *
     * @param string $avatarPath Stored avatar path value.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateData(string $avatarPath): array
    {
        return $this->avatarConfig->templateData($avatarPath);
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
        $maxBytes = $this->mediaConfig->resolveMaxFilesizeBytes('images', 10485760);
        $allowedExtensions = (string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png');
        $policy = new AvatarValidator($maxBytes, 10000, 10000, $allowedExtensions);
        return $policy->validate($upload);
    }

    /**
     * Resolves the configured TOTP issuer label.
     *
     * @return string TOTP issuer label.
     */
    private function totpIssuer(): string
    {
        return $this->form2fa->resolveTotpIssuer(
            (string) $this->config->get('site.name', 'Raven CMS')
        );
    }

    /**
     * Creates the WebAuthn server runtime for current site config.
     *
     * @return VendorWebAuthn|null WebAuthn server instance, or null when unavailable.
     */
    private function createWebAuthnServer(): ?VendorWebAuthn
    {
        return WebAuthn::createServer(
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
