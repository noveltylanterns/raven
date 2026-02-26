<?php

/**
 * RAVEN CMS
 * ~/private/src/Controller/PublicController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Enforce access and input validation before delegating to lower layers.

declare(strict_types=1);

namespace Raven\Controller;

use Raven\Core\Auth\AuthService;
use Raven\Core\Config;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
use Raven\Core\Support\CountryOptions;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Core\View;
use Raven\Repository\ContactFormRepository;
use Raven\Repository\ContactSubmissionRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\SignupFormRepository;
use Raven\Repository\TaxonomyRepository;
use Raven\Repository\UserRepository;
use Raven\Repository\WaitlistSignupRepository;

use function Raven\Core\Support\redirect;

/**
 * Handles public website routes.
 */
final class PublicController
{
    private View $view;
    private Config $config;
    private AuthService $auth;
    private GroupRepository $groups;
    private PageImageRepository $pageImages;
    private PageRepository $pages;
    private RedirectRepository $redirects;
    private TaxonomyRepository $taxonomy;
    private UserRepository $users;
    private ?ContactFormRepository $contactForms;
    private ?ContactSubmissionRepository $contactSubmissions;
    private ?SignupFormRepository $signupForms;
    private InputSanitizer $input;
    private Csrf $csrf;
    private ?WaitlistSignupRepository $waitlistSignups;
    private bool $captchaScriptIncluded = false;
    /** @var array<string, bool>|null */
    private ?array $enabledExtensionMap = null;
    /**
     * Request-local cache of enabled embedded forms keyed by type then slug.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $embeddedFormLookupCache = [];

    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        GroupRepository $groups,
        PageImageRepository $pageImages,
        PageRepository $pages,
        RedirectRepository $redirects,
        TaxonomyRepository $taxonomy,
        UserRepository $users,
        ?ContactFormRepository $contactForms,
        ?ContactSubmissionRepository $contactSubmissions,
        ?SignupFormRepository $signupForms,
        InputSanitizer $input,
        Csrf $csrf,
        ?WaitlistSignupRepository $waitlistSignups
    )
    {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->groups = $groups;
        $this->pageImages = $pageImages;
        $this->pages = $pages;
        $this->redirects = $redirects;
        $this->taxonomy = $taxonomy;
        $this->users = $users;
        $this->contactForms = $contactForms;
        $this->contactSubmissions = $contactSubmissions;
        $this->signupForms = $signupForms;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->waitlistSignups = $waitlistSignups;
    }

    /**
     * Renders homepage using `home` slug or `index` fallback, outside channels.
     */
    public function home(): void
    {
        $page = $this->pages->findHomepage();

        if ($page === null) {
            $this->notFound();
            return;
        }

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1;
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);

        $this->renderPublic('home', [
            'site' => $this->siteDataWithPageMetaImage($page),
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
        ], 'wrapper');
    }

    /**
     * Resolves one channel landing route by channel slug.
     *
     * Landing selection mirrors homepage priority inside the channel:
     * `home` first, then `index`.
     *
     * If no channel landing page is available, fallback preserves existing
     * single-segment behavior (root page + redirect lookup).
     */
    public function channel(string $channelSlug): void
    {
        $page = $this->pages->findChannelHomepage($channelSlug);

        if ($page === null) {
            $this->page($channelSlug, null);
            return;
        }

        $channel = $this->taxonomy->findChannelBySlug($channelSlug);

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1;
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);

        $channelTemplate = $this->resolveChannelTemplateName($channelSlug);
        $site = $this->siteDataWithPageMetaImage($page);
        if (is_array($channel)) {
            // Channel-level cover/preview uploads override default/page fallback for channel landing routes.
            $site = $this->siteDataWithTaxonomyMetaImage($channel, $site);
        }

        $this->renderPublic($channelTemplate, [
            'site' => $site,
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
        ], 'wrapper');
    }

    /**
     * Renders one public page, optionally nested by channel slug.
     */
    public function page(string $pageSlug, ?string $channelSlug = null): void
    {
        $page = $this->pages->findPublicPage($pageSlug, $channelSlug);

        if ($page === null) {
            // If no page exists at this path, attempt redirect fallback before 404.
            if ($this->tryRedirect($pageSlug, $channelSlug)) {
                return;
            }

            $this->notFound();
            return;
        }

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1;
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);

        $pageTemplate = $this->resolvePageTemplateName($channelSlug);

        $this->renderPublic($pageTemplate, [
            'site' => $this->siteDataWithPageMetaImage($page),
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
        ], 'wrapper');
    }

    /**
     * Attempts active redirect lookup for a URL path and emits HTTP redirect when found.
     */
    private function tryRedirect(string $pageSlug, ?string $channelSlug = null): bool
    {
        $redirect = $this->redirects->findActiveByPath($pageSlug, $channelSlug);
        if ($redirect === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirect['target_url'] ?? ''));
        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            return false;
        }

        // Default behavior is temporary redirect; status configuration can be added later.
        \Raven\Core\Support\redirect($targetUrl, 302);
    }

    /**
     * Safety check for redirect targets loaded from persistence.
     */
    private function isAllowedRedirectTargetUrl(string $targetUrl): bool
    {
        if ($targetUrl === '' || str_contains($targetUrl, ' ')) {
            return false;
        }

        if (str_starts_with($targetUrl, '/')) {
            // Block protocol-relative URLs (`//host`) to avoid bypassing scheme validation.
            return !str_starts_with($targetUrl, '//');
        }

        if (filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Renders category listing route `/{category_prefix}/{category_slug}/{page?}`.
     */
    public function category(string $categorySlug, int $pageNumber = 1): void
    {
        $categoryPrefix = $this->categoryRoutePrefix();
        if ($categoryPrefix === '') {
            $this->notFound();
            return;
        }

        $category = $this->taxonomy->findCategoryBySlug($categorySlug);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('categories.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pages->listPageByCategorySlug($categorySlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $categoryTemplate = $this->resolveCategoryTemplateName($categorySlug);

        $this->renderPublic($categoryTemplate, [
            'site' => $this->siteDataWithTaxonomyMetaImage($category),
            'category' => $category,
            'pages' => $pages,
            'pagination' => [
                'current' => $pageNumber,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'base_path' => '/' . $categoryPrefix . '/' . rawurlencode($categorySlug),
            ],
        ], 'wrapper');
    }

    /**
     * Renders tag listing route `/{tag_prefix}/{tag_slug}/{page?}`.
     */
    public function tag(string $tagSlug, int $pageNumber = 1): void
    {
        $tagPrefix = $this->tagRoutePrefix();
        if ($tagPrefix === '') {
            $this->notFound();
            return;
        }

        $tag = $this->taxonomy->findTagBySlug($tagSlug);

        if ($tag === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('tags.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pages->listPageByTagSlug($tagSlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $tagTemplate = $this->resolveTagTemplateName($tagSlug);

        $this->renderPublic($tagTemplate, [
            'site' => $this->siteDataWithTaxonomyMetaImage($tag),
            'tag' => $tag,
            'pages' => $pages,
            'pagination' => [
                'current' => $pageNumber,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'base_path' => '/' . $tagPrefix . '/' . rawurlencode($tagSlug),
            ],
        ], 'wrapper');
    }

    /**
     * Renders one public profile route `/{profile_prefix}/{username}`.
     */
    public function profile(string $username): void
    {
        $profileMode = $this->profileMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->profileRoutePrefix() === '') {
            $this->notFound();
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

        $normalizedUsername = $this->input->username($username);
        if ($normalizedUsername === null) {
            $this->notFound();
            return;
        }

        $profile = $this->users->findPublicProfileByUsername($normalizedUsername);
        if ($profile === null) {
            $this->notFound();
            return;
        }

        $template = match ($profileMode) {
            'public_full' => 'profiles/full',
            'public_limited' => $isLoggedIn ? 'profiles/full' : 'profiles/limited',
            'private' => 'profiles/full',
            default => 'profiles/index',
        };

        $this->renderPublic($template, [
            'site' => $this->siteData(),
            'profile' => $profile,
        ], 'wrapper');
    }

    /**
     * Renders one public group route `/{group_prefix}/{group_slug}`.
     */
    public function group(string $groupSlug): void
    {
        $groupMode = $this->groupMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->groupRoutePrefix() === '') {
            $this->notFound();
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

        $normalizedSlug = $this->input->slug($groupSlug);
        if ($normalizedSlug === null) {
            $this->notFound();
            return;
        }

        $groupRouteData = $this->groups->findPublicRouteDataBySlug($normalizedSlug);
        if ($groupRouteData === null) {
            $this->notFound();
            return;
        }

        $group = is_array($groupRouteData['group'] ?? null) ? $groupRouteData['group'] : [];
        $members = is_array($groupRouteData['members'] ?? null) ? $groupRouteData['members'] : [];
        $this->renderPublic('groups/list', [
            'site' => $this->siteData(),
            'group' => $group,
            'members' => $members,
        ], 'wrapper');
    }

    /**
     * Renders profile-disabled/private-denied placeholder with explicit status.
     */
    private function renderProfileUnavailable(string $error, string $mode): void
    {
        if ($error === 'permission_denied') {
            http_response_code(403);
        } else {
            http_response_code(404);
        }

        $this->renderPublic('profiles/index', [
            'site' => $this->siteData(),
            'profileRouteError' => $error,
            'profileRouteMode' => $mode,
        ], 'wrapper');
    }

    /**
     * Renders group-route disabled/private-denied placeholder with explicit status.
     */
    private function renderGroupUnavailable(string $error, string $mode): void
    {
        if ($error === 'permission_denied') {
            http_response_code(403);
        } else {
            http_response_code(404);
        }

        $this->renderPublic('groups/index', [
            'site' => $this->siteData(),
            'groupRouteError' => $error,
            'groupRouteMode' => $mode,
        ], 'wrapper');
    }

    /**
     * Handles one public signup-sheet submission request.
     */
    public function submitWaitlist(string $formSlug): void
    {
        if (
            !$this->isExtensionEnabled('signups')
            || !$this->signupForms instanceof SignupFormRepository
            || !$this->waitlistSignups instanceof WaitlistSignupRepository
        ) {
            $this->notFound();
            return;
        }

        $slug = $this->input->slug($formSlug);
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $returnPath = $this->sanitizePublicReturnPath((string) ($_POST['return_path'] ?? '/'));
        $redirectTo = $returnPath . '#signups-' . $slug;

        if (!$this->csrf->validate($_POST['_csrf'] ?? null)) {
            $this->pushWaitlistFormFlash($slug, 'error', 'Your session token is invalid. Please retry and submit again.');
            redirect($redirectTo);
        }

        $definition = $this->findEmbeddedFormDefinition('signups', $slug);
        if ($definition === null) {
            $this->pushWaitlistFormFlash($slug, 'error', 'This signup sheet form is unavailable right now.');
            redirect($redirectTo);
        }

        $displayName = $this->input->text((string) ($_POST['signups_display_name'] ?? ''), 160);
        $emailRaw = strtolower($this->input->text((string) ($_POST['signups_email'] ?? ''), 254));
        $email = $this->input->email($emailRaw);
        $country = strtolower($this->input->text((string) ($_POST['signups_country'] ?? ''), 16));
        $oldValues = [
            'email' => $emailRaw,
            'display_name' => $displayName,
            'country' => $country,
            'additional' => [],
        ];

        $errors = [];
        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }

        $allowedCountries = array_keys($this->waitlistCountryOptions());
        if ($country === '' || !in_array($country, $allowedCountries, true)) {
            $errors[] = 'Please choose a valid country option.';
        }

        $additionalPayload = [];
        foreach ($this->signupsAdditionalFieldDefinitions($slug, $definition) as $fieldDefinition) {
            $inputName = (string) ($fieldDefinition['input_name'] ?? '');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $fieldLabel = (string) ($fieldDefinition['label'] ?? $fieldName);
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $fieldRequired = (bool) ($fieldDefinition['required'] ?? false);

            $rawValue = (string) ($_POST[$inputName] ?? '');
            $rawValue = $this->input->text($rawValue, $fieldType === 'textarea' ? 4000 : 255);
            $oldValues['additional'][$fieldName] = $rawValue;

            if ($fieldType === 'email') {
                $sanitizedEmail = $this->input->email($rawValue);
                if ($rawValue !== '' && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' must be a valid email address.';
                }
                if ($fieldRequired && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' is required.';
                }

                if ($sanitizedEmail !== null) {
                    $additionalPayload[] = [
                        'label' => $fieldLabel,
                        'name' => $fieldName,
                        'type' => $fieldType,
                        'value' => (string) $sanitizedEmail,
                    ];
                }
                continue;
            }

            if ($fieldRequired && $rawValue === '') {
                $errors[] = $fieldLabel . ' is required.';
            }

            if ($rawValue !== '') {
                $additionalPayload[] = [
                    'label' => $fieldLabel,
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'value' => $rawValue,
                ];
            }
        }

        if ($errors !== []) {
            $this->pushWaitlistFormFlash($slug, 'error', implode(' ', $errors), $oldValues);
            redirect($redirectTo);
        }

        $captchaError = $this->validatePublicCaptcha();
        if ($captchaError !== null) {
            $this->pushWaitlistFormFlash($slug, 'error', $captchaError, $oldValues);
            redirect($redirectTo);
        }

        $ipAddress = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $hostname = $this->resolveClientHostname($ipAddress);
        $userAgent = $this->input->text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
        $additionalFieldsJson = '[]';
        if ($additionalPayload !== []) {
            $encodedAdditional = json_encode($additionalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encodedAdditional) && $encodedAdditional !== '') {
                $additionalFieldsJson = $this->input->text($encodedAdditional, 20000);
            }
        }

        try {
            $this->waitlistSignups->create([
                'form_slug' => $slug,
                'email' => (string) $email,
                'display_name' => $displayName,
                'country' => $country,
                'additional_fields_json' => $additionalFieldsJson,
                'source_url' => $returnPath,
                'ip_address' => $ipAddress,
                'hostname' => $hostname,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ]);
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if ($message === '') {
                $message = 'Unable to save your signup right now.';
            }

            $this->pushWaitlistFormFlash($slug, 'error', $message, $oldValues);
            redirect($redirectTo);
        }

        $this->pushWaitlistFormFlash($slug, 'success', 'Thanks, your signup has been received.');
        redirect($redirectTo);
    }

    /**
     * Handles one public contact-form submission request.
     */
    public function submitContactForm(string $formSlug): void
    {
        if (
            !$this->isExtensionEnabled('contact')
            || !$this->contactForms instanceof ContactFormRepository
            || !$this->contactSubmissions instanceof ContactSubmissionRepository
        ) {
            $this->notFound();
            return;
        }

        $slug = $this->input->slug($formSlug);
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $returnPath = $this->sanitizePublicReturnPath((string) ($_POST['return_path'] ?? '/'));
        $redirectTo = $returnPath . '#contact-form-' . $slug;

        if (!$this->csrf->validate($_POST['_csrf'] ?? null)) {
            $this->pushContactFormFlash($slug, 'error', 'Your session token is invalid. Please retry and submit again.');
            redirect($redirectTo);
        }

        $definition = $this->findEmbeddedFormDefinition('contact', $slug);
        if ($definition === null) {
            $this->pushContactFormFlash($slug, 'error', 'This contact form is unavailable right now.');
            redirect($redirectTo);
        }

        $destinations = $this->parseContactDestinations((string) ($definition['destination'] ?? ''));
        if ($destinations === []) {
            $this->pushContactFormFlash($slug, 'error', 'This contact form has no valid destination address configured.');
            redirect($redirectTo);
        }
        $ccRecipients = $this->parseContactDestinations((string) ($definition['cc'] ?? ''));
        $bccRecipients = $this->parseContactDestinations((string) ($definition['bcc'] ?? ''));

        $senderName = $this->input->text((string) ($_POST['contact_name'] ?? ''), 160);
        $senderEmailRaw = strtolower($this->input->text((string) ($_POST['contact_email'] ?? ''), 254));
        $senderEmail = $this->input->email($senderEmailRaw);
        $messageRaw = $this->input->html((string) ($_POST['contact_message'] ?? ''), 5000);
        $message = trim((string) preg_replace('/\r\n?/', "\n", strip_tags($messageRaw)));

        $oldValues = [
            'name' => $senderName,
            'email' => $senderEmailRaw,
            'message' => $message,
            'additional' => [],
        ];

        $errors = [];
        if ($senderName === '') {
            $errors[] = 'Name is required.';
        }
        if ($senderEmail === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($message === '') {
            $errors[] = 'Message is required.';
        }

        $additionalPayload = [];
        foreach ($this->contactAdditionalFieldDefinitions($slug, $definition) as $fieldDefinition) {
            $inputName = (string) ($fieldDefinition['input_name'] ?? '');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $fieldLabel = (string) ($fieldDefinition['label'] ?? $fieldName);
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $fieldRequired = (bool) ($fieldDefinition['required'] ?? false);

            $rawValue = (string) ($_POST[$inputName] ?? '');
            $rawValue = $this->input->text($rawValue, $fieldType === 'textarea' ? 4000 : 255);
            $oldValues['additional'][$fieldName] = $rawValue;

            if ($fieldType === 'email') {
                $sanitizedEmail = $this->input->email($rawValue);
                if ($rawValue !== '' && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' must be a valid email address.';
                }
                if ($fieldRequired && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' is required.';
                }

                if ($sanitizedEmail !== null) {
                    $additionalPayload[] = [
                        'label' => $fieldLabel,
                        'value' => (string) $sanitizedEmail,
                    ];
                }
                continue;
            }

            if ($fieldRequired && $rawValue === '') {
                $errors[] = $fieldLabel . ' is required.';
            }

            if ($rawValue !== '') {
                $additionalPayload[] = [
                    'label' => $fieldLabel,
                    'value' => $rawValue,
                ];
            }
        }

        if ($errors !== []) {
            $this->pushContactFormFlash($slug, 'error', implode(' ', $errors), $oldValues);
            redirect($redirectTo);
        }

        $captchaError = $this->validatePublicCaptcha();
        if ($captchaError !== null) {
            $this->pushContactFormFlash($slug, 'error', $captchaError, $oldValues);
            redirect($redirectTo);
        }

        $formName = $this->input->text((string) ($definition['name'] ?? 'Contact Form'), 160);
        $subject = $this->buildContactSubject($formName);
        $body = $this->buildContactBody(
            $formName,
            $slug,
            $senderName,
            (string) $senderEmail,
            $message,
            $additionalPayload,
            $returnPath
        );

        $saveMailLocally = !array_key_exists('save_mail_locally', $definition)
            || (bool) ($definition['save_mail_locally'] ?? true);
        $savedLocally = false;
        $localSaveError = null;
        if ($saveMailLocally) {
            $additionalFieldsJson = '[]';
            if ($additionalPayload !== []) {
                $encodedAdditional = json_encode($additionalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encodedAdditional) && $encodedAdditional !== '') {
                    $additionalFieldsJson = $this->input->text($encodedAdditional, 20000);
                }
            }

            $ipAddress = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $hostname = $this->resolveClientHostname($ipAddress);
            $userAgent = $this->input->text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);

            try {
                $this->contactSubmissions->create([
                    'form_slug' => $slug,
                    'sender_name' => $senderName,
                    'sender_email' => (string) $senderEmail,
                    'message_text' => $message,
                    'additional_fields_json' => $additionalFieldsJson,
                    'source_url' => $returnPath,
                    'ip_address' => $ipAddress,
                    'hostname' => $hostname,
                    'user_agent' => $userAgent !== '' ? $userAgent : null,
                ]);
                $savedLocally = true;
            } catch (\Throwable $exception) {
                $localSaveError = $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Failed to save your message locally.';
            }
        }

        try {
            $this->sendContactMail($destinations, $ccRecipients, $bccRecipients, $subject, $body, (string) $senderEmail, $slug);
        } catch (\RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
            if ($savedLocally) {
                $errorMessage = 'Your message was saved locally, but email delivery failed. ' . $errorMessage;
            } elseif ($localSaveError !== null) {
                $errorMessage = $errorMessage . ' Local save also failed.';
            }

            $this->pushContactFormFlash($slug, 'error', trim($errorMessage), $oldValues);
            redirect($redirectTo);
        }

        if ($saveMailLocally && !$savedLocally) {
            $notice = 'Thanks, your message has been sent. Local save failed.';
            if (is_string($localSaveError) && $localSaveError !== '') {
                $notice .= ' ' . $localSaveError;
            }
            $this->pushContactFormFlash($slug, 'success', $notice);
            redirect($redirectTo);
        }

        $this->pushContactFormFlash($slug, 'success', 'Thanks, your message has been sent.');
        redirect($redirectTo);
    }

    /**
     * Enforces global frontend availability mode before route handling.
     */
    public function enforceSiteAvailability(): bool
    {
        $mode = $this->siteEnabledMode();

        if ($mode === 'disabled') {
            http_response_code(503);
            $this->renderPublic('messages/disabled', [
                'site' => $this->siteData(),
            ], 'wrapper');
            return false;
        }

        if ($mode === 'private') {
            if (!$this->auth->isLoggedIn() || !$this->auth->canViewPrivateSite()) {
                http_response_code(403);
                $this->renderPublic('messages/denied', [
                    'site' => $this->siteData(),
                ], 'wrapper');
                return false;
            }

            return true;
        }

        if (!$this->auth->canViewPublicSite()) {
            http_response_code(403);
            $this->renderPublic('messages/denied', [
                'site' => $this->siteData(),
            ], 'wrapper');
            return false;
        }

        return true;
    }

    /**
     * Renders public not-found page.
     */
    public function notFound(): void
    {
        http_response_code(404);

        $this->renderPublic('messages/404', [
            'site' => $this->siteData(),
        ], 'wrapper');
    }

    /**
     * Returns configured global frontend availability mode.
     */
    private function siteEnabledMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('site.enabled', 'public')));
        if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
            return 'public';
        }

        return $mode;
    }

    /**
     * Collects site config values required by public templates.
     *
     * @return array<string, string>
     */
    private function siteData(): array
    {
        $publicTheme = $this->currentPublicThemeSlug();
        $configuredDomain = (string) $this->config->get('site.domain', 'localhost');

        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'domain' => $configuredDomain,
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'current_url' => $this->currentRequestUrl($configuredDomain),
            'apple_touch_icon' => trim((string) $this->config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $this->config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $this->config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $this->config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $this->config->get('meta.twitter.creator', '')),
            'twitter_image' => $this->absoluteMetaImageUrl(
                trim((string) $this->config->get('meta.twitter.image', '')),
                $configuredDomain
            ),
            'og_image' => $this->absoluteMetaImageUrl(
                trim((string) $this->config->get('meta.opengraph.image', '')),
                $configuredDomain
            ),
            'og_type' => trim((string) $this->config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $this->config->get('meta.opengraph.locale', 'en_US')),
            'public_theme' => $publicTheme,
            // CSS may live only in a parent theme for child-theme setups.
            'public_theme_css' => $this->currentPublicThemeCssSlug($publicTheme),
        ];
    }

    /**
     * Returns site data with page-level OG/Twitter image override when available.
     *
     * @param array<string, mixed> $page
     * @return array<string, string>
     */
    private function siteDataWithPageMetaImage(array $page): array
    {
        $site = $this->siteData();
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId < 1) {
            return $site;
        }

        $previewImageUrl = $this->absoluteMetaImageUrl(
            trim((string) ($this->pageImages->previewImageUrlForPage($pageId) ?? '')),
            (string) ($site['domain'] ?? 'localhost')
        );
        if ($previewImageUrl === '') {
            return $site;
        }

        $site['og_image'] = $previewImageUrl;
        $site['twitter_image'] = $previewImageUrl;

        return $site;
    }

    /**
     * Returns site data with taxonomy-level OG/Twitter image override when available.
     *
     * @param array<string, mixed> $taxonomy
     * @param array<string, string>|null $baseSiteData
     * @return array<string, string>
     */
    private function siteDataWithTaxonomyMetaImage(array $taxonomy, ?array $baseSiteData = null): array
    {
        $site = $baseSiteData ?? $this->siteData();
        $configuredDomain = (string) ($site['domain'] ?? 'localhost');

        $candidates = [
            trim((string) ($taxonomy['preview_image_lg_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_md_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_sm_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_lg_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_md_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_sm_path'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $resolved = $this->absoluteMetaImageUrl($candidate, $configuredDomain);
            if ($resolved === '') {
                continue;
            }

            $site['og_image'] = $resolved;
            $site['twitter_image'] = $resolved;
            return $site;
        }

        return $site;
    }

    /**
     * Resolves one safe absolute URL for OpenGraph/Twitter image tags.
     *
     * Accepts absolute HTTP(S) URLs or local URL paths.
     */
    private function absoluteMetaImageUrl(string $value, string $configuredDomain): string
    {
        $value = trim(str_replace(["\r", "\n", "\0"], '', $value));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $value : '';
        }

        $path = str_starts_with($value, '/') ? $value : ('/' . ltrim($value, '/'));
        $scheme = $this->resolveRequestScheme();
        $host = $this->resolveRequestHost($configuredDomain);

        return $scheme . '://' . $host . $path;
    }

    /**
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function currentPublicThemeSlug(): string
    {
        $configured = strtolower($this->input->text((string) $this->config->get('site.default_theme', 'raven'), 80));
        $options = $this->publicThemeOptions();

        if (isset($options[$configured])) {
            return $configured;
        }

        if (isset($options['raven'])) {
            return 'raven';
        }

        $slugs = array_keys($options);
        return (string) ($slugs[0] ?? 'raven');
    }

    /**
     * Returns one canonical absolute URL for the current public request.
     */
    private function currentRequestUrl(string $configuredDomain): string
    {
        $scheme = $this->resolveRequestScheme();
        $host = $this->resolveRequestHost($configuredDomain);

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/';
        }

        $query = (string) parse_url($requestUri, PHP_URL_QUERY);
        $query = str_replace(["\r", "\n", "\0"], '', $query);

        $url = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    /**
     * Resolves request scheme from forwarded/proxy/server context.
     */
    private function resolveRequestScheme(): string
    {
        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if (in_array($forwarded, ['http', 'https'], true)) {
            return $forwarded;
        }

        $requestScheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
        if (in_array($requestScheme, ['http', 'https'], true)) {
            return $requestScheme;
        }

        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off' && $https !== '0') {
            return 'https';
        }

        return 'http';
    }

    /**
     * Resolves one safe host[:port] for absolute URL generation.
     */
    private function resolveRequestHost(string $configuredDomain): string
    {
        $configured = trim($configuredDomain);

        if ($configured !== '') {
            if (str_contains($configured, '://')) {
                $parsedHost = trim((string) parse_url($configured, PHP_URL_HOST));
                $parsedPort = parse_url($configured, PHP_URL_PORT);
                if ($parsedHost !== '') {
                    $candidate = $parsedHost;
                    if (is_int($parsedPort) && $parsedPort > 0) {
                        $candidate .= ':' . $parsedPort;
                    }

                    if ($this->isValidHostWithOptionalPort($candidate)) {
                        return $candidate;
                    }
                }
            }

            // Strip any accidental path/query suffix from domain config.
            $configured = preg_replace('/[\/?#].*$/', '', $configured) ?? $configured;
            if ($this->isValidHostWithOptionalPort($configured)) {
                return $configured;
            }
        }

        $serverHost = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($this->isValidHostWithOptionalPort($serverHost)) {
            return $serverHost;
        }

        return 'localhost';
    }

    /**
     * Returns true when a host[:port] value is safe for URL composition.
     */
    private function isValidHostWithOptionalPort(string $value): bool
    {
        if ($value === '' || str_contains($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            return false;
        }

        if (preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $value) === 1) {
            return true;
        }

        // Accept bracketed IPv6 hosts with optional port.
        return preg_match('/^\[[a-f0-9:]+\](?::\d{1,5})?$/i', $value) === 1;
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string>
     */
    private function publicThemeOptions(): array
    {
        $themesRoot = $this->publicThemesRoot();
        $options = PublicThemeRegistry::options($themesRoot);
        if ($options === []) {
            return ['raven' => 'Raven Basic'];
        }

        return $options;
    }

    /**
     * Returns filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return dirname(__DIR__, 3) . '/public/theme';
    }

    /**
     * Resolves active theme inheritance chain, child first.
     *
     * @return array<int, string>
     */
    private function currentPublicThemeInheritanceChain(string $themeSlug): array
    {
        $chain = PublicThemeRegistry::inheritanceChain($this->publicThemesRoot(), $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    /**
     * Resolves one theme slug that provides the active public stylesheet.
     */
    private function currentPublicThemeCssSlug(string $themeSlug): string
    {
        foreach ($this->currentPublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/css/style.css';
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
     * Normalizes and shortcode-renders repeatable Extended page blocks.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function renderPageExtendedBlocks(array $page): array
    {
        $rawBlocks = $page['extended_blocks'] ?? null;
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }

        $renderedBlocks = [];
        foreach ($rawBlocks as $block) {
            if (!is_scalar($block) && $block !== null) {
                continue;
            }

            $html = $this->renderEmbeddedForms((string) ($block ?? ''));
            if (trim($html) === '') {
                continue;
            }

            $renderedBlocks[] = $html;
        }

        $page['extended_blocks'] = $renderedBlocks;
        return $page;
    }

    /**
     * Returns configured category-list route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return $this->normalizeTaxonomyRoutePrefix((string) $this->config->get('categories.prefix', 'cat'), 'cat', true);
    }

    /**
     * Returns configured tag-list route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return $this->normalizeTaxonomyRoutePrefix((string) $this->config->get('tags.prefix', 'tag'), 'tag', true);
    }

    /**
     * Returns configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->normalizeTaxonomyRoutePrefix((string) $this->config->get('session.profile_prefix', 'user'), 'user', true);
    }

    /**
     * Returns configured public profile mode.
     */
    private function profileMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('session.profile_mode', 'disabled')));
        if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
            return 'disabled';
        }

        return $mode;
    }

    /**
     * Returns configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->normalizeTaxonomyRoutePrefix((string) $this->config->get('session.group_prefix', 'group'), 'group', true);
    }

    /**
     * Returns configured public group mode.
     */
    private function groupMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('session.show_groups', 'disabled')));
        if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
            return 'disabled';
        }

        return $mode;
    }

    /**
     * Normalizes one taxonomy route-prefix value and falls back safely.
     */
    private function normalizeTaxonomyRoutePrefix(string $configured, string $fallback, bool $allowBlank = false): string
    {
        $configured = trim($configured);
        if ($allowBlank && $configured === '') {
            return '';
        }

        $slug = $this->input->slug($configured);
        if ($slug === null || $slug === '') {
            return $fallback;
        }

        return $slug;
    }

    /**
     * Renders one public template with theme-aware lookup and private fallback.
     *
     * Theme lookup order:
     * 1) `public/theme/{active_theme}/views/{template}.php`
     * 2) `private/views/{template}.php`
     *
     * @param array<string, mixed> $data
     */
    private function renderPublic(string $template, array $data = [], ?string $layout = null): void
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/views';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        $templateFile = $this->resolvePublicTemplateFile($template, ...$lookupRoots);
        if ($templateFile === null) {
            throw new \RuntimeException('Public template not found: ' . $template);
        }

        $content = $this->renderPublicTemplateFile($templateFile, $data);
        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = $this->resolvePublicTemplateFile($layout, ...$lookupRoots);
        if ($layoutFile === null) {
            throw new \RuntimeException('Public layout not found: ' . $layout);
        }

        $layoutData = $data;
        $layoutData['content'] = $content;
        echo $this->renderPublicTemplateFile($layoutFile, $layoutData);
    }

    /**
     * Returns active public theme views roots, child first.
     *
     * @return array<int, string>
     */
    private function currentPublicThemeViewsRoots(): array
    {
        $roots = [];
        $themeSlug = $this->currentPublicThemeSlug();
        foreach ($this->currentPublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $themeViewsRoot = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/views';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        return $roots;
    }

    /**
     * Resolves one public template path from ordered roots.
     */
    private function resolvePublicTemplateFile(string $template, string ...$roots): ?string
    {
        $relative = trim($template, '/') . '.php';

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolves channel landing template name with slug-specific override support.
     *
     * Priority:
     * 1) `views/channels/{channel_slug}.php`
     * 2) `views/channels/index.php`
     */
    private function resolveChannelTemplateName(string $channelSlug): string
    {
        $normalizedSlug = $this->input->slug($channelSlug);
        if ($normalizedSlug === null) {
            return 'channels/index';
        }

        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/views';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        $slugTemplate = 'channels/' . $normalizedSlug;
        if ($this->resolvePublicTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'channels/index';
    }

    /**
     * Resolves public page template with optional channel-specific override.
     *
     * Priority:
     * 1) `views/pages/{channel_slug}.php` when route has a channel
     * 2) `views/pages/index.php`
     */
    private function resolvePageTemplateName(?string $channelSlug): string
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/views';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        if ($channelSlug !== null) {
            $normalizedSlug = $this->input->slug($channelSlug);
            if ($normalizedSlug !== null) {
                $channelTemplate = 'pages/' . $normalizedSlug;
                if ($this->resolvePublicTemplateFile($channelTemplate, ...$lookupRoots) !== null) {
                    return $channelTemplate;
                }
            }
        }

        return 'pages/index';
    }

    /**
     * Resolves category-list template name with category-slug override support.
     *
     * Priority:
     * 1) `views/categories/{category_slug}.php`
     * 2) `views/categories/index.php`
     */
    private function resolveCategoryTemplateName(string $categorySlug): string
    {
        $normalizedSlug = $this->input->slug($categorySlug);
        if ($normalizedSlug === null) {
            return 'categories/index';
        }

        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/views';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        $slugTemplate = 'categories/' . $normalizedSlug;
        if ($this->resolvePublicTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'categories/index';
    }

    /**
     * Resolves tag-list template name with tag-slug override support.
     *
     * Priority:
     * 1) `views/tags/{tag_slug}.php`
     * 2) `views/tags/index.php`
     */
    private function resolveTagTemplateName(string $tagSlug): string
    {
        $normalizedSlug = $this->input->slug($tagSlug);
        if ($normalizedSlug === null) {
            return 'tags/index';
        }

        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/views';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        $slugTemplate = 'tags/' . $normalizedSlug;
        if ($this->resolvePublicTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'tags/index';
    }

    /**
     * Executes one resolved public template file in isolated scope.
     *
     * @param array<string, mixed> $data
     */
    private function renderPublicTemplateFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);

        // Templates must only execute through Raven renderers, never as direct PHP endpoints.
        if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
            define('RAVEN_VIEW_RENDER_CONTEXT', true);
        }

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /**
     * Normalizes a post-submit return path to one safe local absolute path.
     */
    private function sanitizePublicReturnPath(string $rawPath): string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '' || str_contains($rawPath, "\0") || str_contains($rawPath, '\\')) {
            return '/';
        }

        $path = (string) parse_url($rawPath, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path;
    }

    /**
     * Returns normalized client IP when present and valid.
     */
    private function normalizeClientIp(string $rawIp): ?string
    {
        $rawIp = trim($rawIp);
        if ($rawIp === '') {
            return null;
        }

        // Keep only the first address in chained forwarding values.
        if (str_contains($rawIp, ',')) {
            $parts = explode(',', $rawIp);
            $rawIp = trim((string) ($parts[0] ?? ''));
        }

        if ($rawIp === '' || filter_var($rawIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->input->text($rawIp, 45);
    }

    /**
     * Resolves reverse-DNS hostname for one normalized client IP.
     */
    private function resolveClientHostname(?string $ipAddress): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        $rawHostname = @gethostbyaddr($ipAddress);
        if (!is_string($rawHostname)) {
            return null;
        }

        $hostname = strtolower(trim($rawHostname));
        if ($hostname === '' || $hostname === $ipAddress || filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Remove optional trailing dot from fully-qualified DNS names.
        $hostname = rtrim($hostname, '.');
        if ($hostname === '' || str_contains($hostname, '..') || preg_match('/[^a-z0-9.-]/', $hostname) === 1) {
            return null;
        }

        return $this->input->text($hostname, 255);
    }

    /**
     * Returns configured captcha provider for public form handling.
     */
    private function captchaProvider(): string
    {
        $provider = strtolower($this->input->text((string) $this->config->get('captcha.provider', 'none'), 20));
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            return 'none';
        }

        return $provider;
    }

    /**
     * Returns normalized public captcha site key for one provider.
     */
    private function captchaSiteKey(string $provider): string
    {
        return match ($provider) {
            'hcaptcha' => $this->input->text((string) $this->config->get('captcha.hcaptcha.public_key', ''), 500),
            'recaptcha2' => $this->input->text((string) $this->config->get('captcha.recaptcha2.public_key', ''), 500),
            'recaptcha3' => $this->input->text((string) $this->config->get('captcha.recaptcha3.public_key', ''), 500),
            default => '',
        };
    }

    /**
     * Returns normalized captcha secret key for one provider.
     */
    private function captchaSecretKey(string $provider): string
    {
        return match ($provider) {
            'hcaptcha' => $this->input->text((string) $this->config->get('captcha.hcaptcha.secret_key', ''), 500),
            'recaptcha2' => $this->input->text((string) $this->config->get('captcha.recaptcha2.secret_key', ''), 500),
            'recaptcha3' => $this->input->text((string) $this->config->get('captcha.recaptcha3.secret_key', ''), 500),
            default => '',
        };
    }

    /**
     * Returns submit payload field name for one captcha provider.
     */
    private function captchaResponseField(string $provider): string
    {
        return $provider === 'hcaptcha' ? 'h-captcha-response' : 'g-recaptcha-response';
    }

    /**
     * Verifies configured public captcha response in current request.
     *
     * @return string|null One user-facing validation error, or null when captcha passes.
     */
    private function validatePublicCaptcha(): ?string
    {
        $provider = $this->captchaProvider();
        if ($provider === 'none') {
            return null;
        }

        $siteKey = $this->captchaSiteKey($provider);
        $secretKey = $this->captchaSecretKey($provider);
        if ($siteKey === '' || $secretKey === '') {
            return 'Captcha is not configured right now. Please try again later.';
        }

        $responseField = $this->captchaResponseField($provider);
        $captchaToken = $this->input->text((string) ($_POST[$responseField] ?? ''), 6000);
        if ($captchaToken === '') {
            return 'Please complete the captcha challenge.';
        }

        $remoteIp = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$this->verifyCaptchaToken($provider, $secretKey, $captchaToken, $remoteIp)) {
            return 'Captcha verification failed. Please try again.';
        }

        return null;
    }

    /**
     * Performs server-side token verification against configured captcha provider.
     */
    private function verifyCaptchaToken(string $provider, string $secretKey, string $captchaToken, ?string $remoteIp): bool
    {
        $endpoint = $provider === 'hcaptcha'
            ? 'https://api.hcaptcha.com/siteverify'
            : 'https://www.google.com/recaptcha/api/siteverify';

        $payload = [
            'secret' => $secretKey,
            'response' => $captchaToken,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $rawResponse = @file_get_contents($endpoint, false, $context);
        if (!is_string($rawResponse) || trim($rawResponse) === '') {
            return false;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return false;
        }

        return !empty($decoded['success']);
    }

    /**
     * Returns captcha widget + script markup for public embedded forms.
     */
    private function publicCaptchaMarkup(): string
    {
        $provider = $this->captchaProvider();
        if ($provider === 'none') {
            return '';
        }

        $siteKey = $this->captchaSiteKey($provider);
        if ($siteKey === '') {
            return '<div class="col-12"><div class="alert alert-warning mb-0" role="alert">Captcha is currently unavailable.</div></div>';
        }

        $widgetClass = $provider === 'hcaptcha' ? 'h-captcha' : 'g-recaptcha';
        $scriptSrc = match ($provider) {
            'hcaptcha' => 'https://js.hcaptcha.com/1/api.js',
            'recaptcha3' => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($siteKey),
            default => 'https://www.google.com/recaptcha/api.js',
        };
        $escapedSiteKey = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');
        $escapedScriptSrc = htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8');

        $scriptMarkup = '';
        if (!$this->captchaScriptIncluded) {
            $scriptMarkup = '<script src="' . $escapedScriptSrc . '" async defer></script>';
            if ($provider === 'recaptcha3') {
                $siteKeyJson = json_encode($siteKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                if (!is_string($siteKeyJson) || $siteKeyJson === '') {
                    $siteKeyJson = '""';
                }

                $scriptMarkup .= '<script>'
                    . '(function(){'
                    . 'if(window.__ravenRecaptcha3Bound){return;}'
                    . 'window.__ravenRecaptcha3Bound=true;'
                    . 'var siteKey=' . $siteKeyJson . ';'
                    . 'document.addEventListener("submit",function(event){'
                    . 'var form=event.target;'
                    . 'if(!(form instanceof HTMLFormElement)){return;}'
                    . 'if(!form.querySelector(\'[data-raven-captcha-provider="recaptcha3"]\')){return;}'
                    . 'if(String(form.getAttribute("data-raven-recaptcha3-submitting")||"")==="1"){return;}'
                    . 'event.preventDefault();'
                    . 'form.setAttribute("data-raven-recaptcha3-submitting","1");'
                    . 'var tokenField=form.querySelector(\'input[name="g-recaptcha-response"]\');'
                    . 'if(!(tokenField instanceof HTMLInputElement)){'
                    . 'tokenField=document.createElement("input");'
                    . 'tokenField.type="hidden";'
                    . 'tokenField.name="g-recaptcha-response";'
                    . 'form.appendChild(tokenField);'
                    . '}'
                    . 'var submitWithoutToken=function(){'
                    . 'form.removeAttribute("data-raven-recaptcha3-submitting");'
                    . 'form.submit();'
                    . '};'
                    . 'if(!window.grecaptcha||typeof window.grecaptcha.ready!=="function"||typeof window.grecaptcha.execute!=="function"){'
                    . 'submitWithoutToken();'
                    . 'return;'
                    . '}'
                    . 'window.grecaptcha.ready(function(){'
                    . 'window.grecaptcha.execute(siteKey,{action:"submit"}).then(function(token){'
                    . 'tokenField.value=String(token||"");'
                    . 'form.removeAttribute("data-raven-recaptcha3-submitting");'
                    . 'form.submit();'
                    . '}).catch(function(){submitWithoutToken();});'
                    . '});'
                    . '},true);'
                    . '})();'
                    . '</script>';
            }
            $this->captchaScriptIncluded = true;
        }

        if ($provider === 'recaptcha3') {
            return $scriptMarkup
                . '<div class="col-12">'
                . '<input type="hidden" name="g-recaptcha-response" value="">'
                . '<div class="small text-muted" data-raven-captcha-provider="recaptcha3">Protected by reCAPTCHA.</div>'
                . '</div>';
        }

        return $scriptMarkup
            . '<div class="col-12"><div class="' . $widgetClass . '" data-sitekey="' . $escapedSiteKey . '"></div></div>';
    }

    /**
     * Returns available signup-country choices.
     *
     * @return array<string, string>
     */
    private function waitlistCountryOptions(): array
    {
        return CountryOptions::list(true);
    }

    /**
     * Normalizes signup-sheet additional field definitions for rendering and submit validation.
     *
     * @param array<string, mixed> $definition
     * @return array<int, array{
     *   label: string,
     *   name: string,
     *   type: string,
     *   required: bool,
     *   input_name: string
     * }>
     */
    private function signupsAdditionalFieldDefinitions(string $slug, array $definition): array
    {
        /** @var mixed $rawAdditionalFields */
        $rawAdditionalFields = $definition['additional_fields'] ?? [];
        if (!is_array($rawAdditionalFields)) {
            return [];
        }

        $fields = [];
        foreach ($rawAdditionalFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }

            $fieldLabelRaw = $this->input->text((string) ($rawField['label'] ?? ''), 120);
            $fieldNameRaw = strtolower($this->input->text((string) ($rawField['name'] ?? ''), 80));
            $fieldNameRaw = preg_replace('/[^a-z0-9_]+/', '_', $fieldNameRaw) ?? '';
            $fieldNameRaw = trim($fieldNameRaw, '_');
            $fieldTypeRaw = strtolower($this->input->text((string) ($rawField['type'] ?? 'text'), 20));
            if (!in_array($fieldTypeRaw, ['text', 'email', 'textarea'], true)) {
                $fieldTypeRaw = 'text';
            }

            if ($fieldLabelRaw === '' || $fieldNameRaw === '') {
                continue;
            }

            $fields[] = [
                'label' => $fieldLabelRaw,
                'name' => $fieldNameRaw,
                'type' => $fieldTypeRaw,
                'required' => (bool) ($rawField['required'] ?? false),
                'input_name' => 'signups_' . $slug . '_' . $fieldNameRaw,
            ];
        }

        return $fields;
    }

    /**
     * Stores one flash payload for a signup-sheet form slug.
     *
     * @param array{email?: string, display_name?: string, country?: string, additional?: array<string, string>} $old
     */
    private function pushWaitlistFormFlash(string $slug, string $type, string $message, array $old = []): void
    {
        if (!isset($_SESSION['_raven_waitlist_form_flash']) || !is_array($_SESSION['_raven_waitlist_form_flash'])) {
            $_SESSION['_raven_waitlist_form_flash'] = [];
        }

        $_SESSION['_raven_waitlist_form_flash'][$slug] = [
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => $message,
            'old' => $old,
        ];
    }

    /**
     * Returns and clears one signup-sheet form flash payload by slug.
     *
     * @return array{
     *   type: string,
     *   message: string,
     *   old: array{email: string, display_name: string, country: string, additional: array<string, string>}
     * }|null
     */
    private function pullWaitlistFormFlash(string $slug): ?array
    {
        $all = $_SESSION['_raven_waitlist_form_flash'] ?? null;
        if (!is_array($all) || !isset($all[$slug]) || !is_array($all[$slug])) {
            return null;
        }

        $raw = $all[$slug];
        unset($_SESSION['_raven_waitlist_form_flash'][$slug]);
        if ((array) ($_SESSION['_raven_waitlist_form_flash'] ?? []) === []) {
            unset($_SESSION['_raven_waitlist_form_flash']);
        }

        $type = ((string) ($raw['type'] ?? 'error')) === 'success' ? 'success' : 'error';
        $message = trim((string) ($raw['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        /** @var mixed $rawOld */
        $rawOld = $raw['old'] ?? [];
        $old = is_array($rawOld) ? $rawOld : [];

        /** @var mixed $rawAdditional */
        $rawAdditional = $old['additional'] ?? [];
        $additional = [];
        if (is_array($rawAdditional)) {
            foreach ($rawAdditional as $fieldName => $fieldValue) {
                if (!is_string($fieldName)) {
                    continue;
                }

                $additional[$fieldName] = $this->input->text((string) $fieldValue, 4000);
            }
        }

        return [
            'type' => $type,
            'message' => $message,
            'old' => [
                'email' => $this->input->text((string) ($old['email'] ?? ''), 254),
                'display_name' => $this->input->text((string) ($old['display_name'] ?? ''), 160),
                'country' => strtolower($this->input->text((string) ($old['country'] ?? ''), 16)),
                'additional' => $additional,
            ],
        ];
    }

    /**
     * Stores one flash payload for a contact form slug.
     *
     * @param array{name?: string, email?: string, message?: string, additional?: array<string, string>} $old
     */
    private function pushContactFormFlash(string $slug, string $type, string $message, array $old = []): void
    {
        if (!isset($_SESSION['_raven_contact_form_flash']) || !is_array($_SESSION['_raven_contact_form_flash'])) {
            $_SESSION['_raven_contact_form_flash'] = [];
        }

        $_SESSION['_raven_contact_form_flash'][$slug] = [
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => $message,
            'old' => $old,
        ];
    }

    /**
     * Returns and clears one contact form flash payload by slug.
     *
     * @return array{
     *   type: string,
     *   message: string,
     *   old: array{name: string, email: string, message: string, additional: array<string, string>}
     * }|null
     */
    private function pullContactFormFlash(string $slug): ?array
    {
        $all = $_SESSION['_raven_contact_form_flash'] ?? null;
        if (!is_array($all) || !isset($all[$slug]) || !is_array($all[$slug])) {
            return null;
        }

        $raw = $all[$slug];
        unset($_SESSION['_raven_contact_form_flash'][$slug]);
        if ((array) ($_SESSION['_raven_contact_form_flash'] ?? []) === []) {
            unset($_SESSION['_raven_contact_form_flash']);
        }

        $type = ((string) ($raw['type'] ?? 'error')) === 'success' ? 'success' : 'error';
        $message = trim((string) ($raw['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        /** @var mixed $rawOld */
        $rawOld = $raw['old'] ?? [];
        $old = is_array($rawOld) ? $rawOld : [];

        /** @var mixed $rawAdditional */
        $rawAdditional = $old['additional'] ?? [];
        $additional = [];
        if (is_array($rawAdditional)) {
            foreach ($rawAdditional as $fieldName => $fieldValue) {
                if (!is_string($fieldName)) {
                    continue;
                }

                $additional[$fieldName] = $this->input->text((string) $fieldValue, 4000);
            }
        }

        return [
            'type' => $type,
            'message' => $message,
            'old' => [
                'name' => $this->input->text((string) ($old['name'] ?? ''), 160),
                'email' => strtolower($this->input->text((string) ($old['email'] ?? ''), 254)),
                'message' => $this->input->text((string) ($old['message'] ?? ''), 5000),
                'additional' => $additional,
            ],
        ];
    }

    /**
     * Normalizes contact additional field definitions for rendering and submit validation.
     *
     * @param array<string, mixed> $definition
     * @return array<int, array{
     *   label: string,
     *   name: string,
     *   type: string,
     *   required: bool,
     *   input_name: string
     * }>
     */
    private function contactAdditionalFieldDefinitions(string $slug, array $definition): array
    {
        /** @var mixed $rawAdditionalFields */
        $rawAdditionalFields = $definition['additional_fields'] ?? [];
        if (!is_array($rawAdditionalFields)) {
            return [];
        }

        $fields = [];
        foreach ($rawAdditionalFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }

            $fieldLabelRaw = $this->input->text((string) ($rawField['label'] ?? ''), 120);
            $fieldNameRaw = strtolower($this->input->text((string) ($rawField['name'] ?? ''), 80));
            $fieldNameRaw = preg_replace('/[^a-z0-9_]+/', '_', $fieldNameRaw) ?? '';
            $fieldNameRaw = trim($fieldNameRaw, '_');
            $fieldTypeRaw = strtolower($this->input->text((string) ($rawField['type'] ?? 'text'), 20));
            if (!in_array($fieldTypeRaw, ['text', 'email', 'textarea'], true)) {
                $fieldTypeRaw = 'text';
            }

            if ($fieldLabelRaw === '' || $fieldNameRaw === '') {
                continue;
            }

            $fields[] = [
                'label' => $fieldLabelRaw,
                'name' => $fieldNameRaw,
                'type' => $fieldTypeRaw,
                'required' => (bool) ($rawField['required'] ?? false),
                'input_name' => 'contact_' . $slug . '_' . $fieldNameRaw,
            ];
        }

        return $fields;
    }

    /**
     * Parses one contact-form recipient list into valid unique email addresses.
     *
     * @return array<int, string>
     */
    private function parseContactDestinations(string $rawDestinations): array
    {
        $normalized = $this->input->text($rawDestinations, 1000);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[;,]+/', $normalized) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            if (!is_string($part) || trim($part) === '') {
                continue;
            }

            $email = $this->input->email(trim($part));
            if ($email === null) {
                continue;
            }

            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    /**
     * Builds one sanitized email subject for contact submissions.
     */
    private function buildContactSubject(string $formName): string
    {
        $mailPrefix = $this->input->text((string) $this->config->get('mail.prefix', 'Mailer Daemon'), 120);
        if ($mailPrefix === '') {
            $mailPrefix = 'Mailer Daemon';
        }

        $formName = $this->input->text($formName, 120);
        $subject = '[' . $mailPrefix . '] ' . ($formName !== '' ? $formName : 'Submission');
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        return trim($subject);
    }

    /**
     * Builds one plain-text contact mail body.
     *
     * @param array<int, array{label: string, value: string}> $additionalFields
     */
    private function buildContactBody(
        string $formName,
        string $formSlug,
        string $senderName,
        string $senderEmail,
        string $message,
        array $additionalFields,
        string $sourceUrl
    ): string {
        $lines = [
            'Contact form submission',
            'Submitted (UTC): ' . gmdate('c'),
            'Form: ' . $formName,
            'Form slug: ' . $formSlug,
            'Source path: ' . $sourceUrl,
            '',
            'Name: ' . $senderName,
            'Email: ' . $senderEmail,
            '',
            'Message:',
            $message,
        ];

        if ($additionalFields !== []) {
            $lines[] = '';
            $lines[] = 'Additional fields:';
            foreach ($additionalFields as $field) {
                $label = $this->input->text((string) ($field['label'] ?? ''), 120);
                $value = $this->input->text((string) ($field['value'] ?? ''), 4000);
                if ($label === '' || $value === '') {
                    continue;
                }

                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Sends contact form mail using configured global mail agent.
     *
     * @param array<int, string> $destinations
     * @param array<int, string> $ccRecipients
     * @param array<int, string> $bccRecipients
     *
     * @throws \RuntimeException
     */
    private function sendContactMail(
        array $destinations,
        array $ccRecipients,
        array $bccRecipients,
        string $subject,
        string $body,
        string $replyToEmail,
        string $formSlug
    ): void
    {
        $agent = strtolower($this->input->text((string) $this->config->get('mail.agent', 'php_mail'), 40));
        if ($agent !== 'php_mail') {
            throw new \RuntimeException('The configured mail agent is not supported yet.');
        }

        // Avoid duplicate recipients across To/CC/BCC.
        $destinationMap = array_fill_keys($destinations, true);
        $ccRecipients = array_values(array_filter($ccRecipients, static fn (string $email): bool => !isset($destinationMap[$email])));
        $toAndCcMap = $destinationMap;
        foreach ($ccRecipients as $ccEmail) {
            $toAndCcMap[$ccEmail] = true;
        }
        $bccRecipients = array_values(array_filter($bccRecipients, static fn (string $email): bool => !isset($toAndCcMap[$email])));

        $fromAddress = $this->configuredMailSenderAddress();
        $fromName = $this->configuredMailSenderName();
        $fromHeader = $fromName !== ''
            ? ($fromName . ' <' . $fromAddress . '>')
            : $fromAddress;
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
            'Reply-To: ' . $replyToEmail,
            'X-Raven-Contact-Form: ' . $formSlug,
        ];
        if ($ccRecipients !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
        }
        if ($bccRecipients !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $bccRecipients);
        }
        $headerString = implode("\r\n", $headers);

        $toRecipients = implode(', ', $destinations);
        $ok = @\mail($toRecipients, $subject, $body, $headerString);
        if ($ok !== true) {
            throw new \RuntimeException('Failed to send contact email via php_mail.');
        }
    }

    /**
     * Builds a safe no-reply address from configured site domain.
     */
    private function defaultNoReplyEmail(): string
    {
        $rawDomain = strtolower(trim((string) $this->config->get('site.domain', 'localhost')));
        $host = '';

        if ($rawDomain !== '') {
            $host = (string) parse_url('//' . $rawDomain, PHP_URL_HOST);
            if ($host === '') {
                $host = $rawDomain;
            }
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '' || !str_contains($host, '.')) {
            $host = 'localhost';
        }

        return 'no-reply@' . $host;
    }

    /**
     * Returns configured sender address or fallback no-reply address.
     */
    private function configuredMailSenderAddress(): string
    {
        $configured = trim((string) $this->config->get('mail.sender_address', ''));
        if ($configured !== '') {
            $normalized = $this->input->email($configured);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $this->defaultNoReplyEmail();
    }

    /**
     * Returns configured sender display name for outgoing contact mail.
     */
    private function configuredMailSenderName(): string
    {
        $name = $this->input->text((string) $this->config->get('mail.sender_name', 'Postmaster'), 120);
        if ($name === '') {
            $name = 'Postmaster';
        }

        return trim(str_replace(["\r", "\n"], ' ', $name));
    }

    /**
     * Resolves supported form shortcodes inside editor HTML content.
     */
    private function renderEmbeddedForms(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\[(contact|signups)\b([^\]]*)\]/i',
            function (array $matches): string {
                $type = strtolower((string) ($matches[1] ?? ''));
                $rawArgumentChunk = (string) ($matches[2] ?? '');
                $slug = $this->extractEmbeddedFormSlug($rawArgumentChunk);
                if ($type === '' || $slug === '') {
                    return '';
                }

                $definition = $this->findEmbeddedFormDefinition($type, $slug);
                if ($definition === null) {
                    return '';
                }

                return $this->embeddedFormMarkup($type, $definition);
            },
            $html
        );
    }

    /**
     * Extracts one shortcode form slug from raw shortcode arguments.
     *
     * Supported formats:
     * - `slug="my-form"`
     * - `slug='my-form'`
     * - `slug=my-form`
     * - `my-form`
     */
    private function extractEmbeddedFormSlug(string $rawArgs): string
    {
        $args = html_entity_decode($rawArgs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $args = trim($args);
        if ($args === '') {
            return '';
        }

        // Handle explicit `slug=...` first (quoted or unquoted).
        if (preg_match('/(?:^|\s)slug\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([a-z0-9_-]+))/i', $args, $matches) === 1) {
            $candidate = '';
            for ($index = 1; $index <= 3; $index++) {
                $value = trim((string) ($matches[$index] ?? ''));
                if ($value !== '') {
                    $candidate = $value;
                    break;
                }
            }

            $slug = $this->input->slug($candidate);
            return $slug ?? '';
        }

        // Also allow compact shorthand: `[contact my-form]` / `[signups my-form]`.
        if (preg_match('/^([a-z0-9_-]+)\s*$/i', $args, $matches) === 1) {
            $slug = $this->input->slug((string) ($matches[1] ?? ''));
            return $slug ?? '';
        }

        return '';
    }

    /**
     * Finds one enabled embedded form definition by shortcode type + slug.
     *
     * @return array<string, mixed>|null
     */
    private function findEmbeddedFormDefinition(string $type, string $slug): ?array
    {
        $type = strtolower(trim($type));
        $slug = strtolower(trim($slug));
        if ($type === '' || $slug === '') {
            return null;
        }

        if (($type === 'contact' && !$this->isExtensionEnabled('contact'))
            || ($type === 'signups' && !$this->isExtensionEnabled('signups'))) {
            return null;
        }

        $lookup = $this->extensionFormLookupByType($type);
        $definition = $lookup[$slug] ?? null;
        return is_array($definition) ? $definition : null;
    }

    /**
     * Returns enabled extension form definitions keyed by slug for one type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function extensionFormLookupByType(string $type): array
    {
        if (isset($this->embeddedFormLookupCache[$type])) {
            return $this->embeddedFormLookupCache[$type];
        }

        $forms = match ($type) {
            'contact' => $this->contactForms?->listAll() ?? [],
            'signups' => $this->signupForms?->listAll() ?? [],
            default => [],
        };

        $lookup = [];
        foreach ($forms as $form) {
            if (!is_array($form) || empty($form['enabled'])) {
                continue;
            }

            $slug = strtolower(trim((string) ($form['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $lookup[$slug] = $form;
        }

        $this->embeddedFormLookupCache[$type] = $lookup;
        return $lookup;
    }

    /**
     * Returns true when one extension is enabled in `private/ext/.state.php`.
     */
    private function isExtensionEnabled(string $extensionName): bool
    {
        if ($this->enabledExtensionMap === null) {
            $root = dirname(__DIR__, 3);
            $this->enabledExtensionMap = ExtensionRegistry::enabledMap($root);
        }

        return !empty($this->enabledExtensionMap[$extensionName]);
    }

    /**
     * Builds public HTML markup for one embedded form definition.
     *
     * @param array<string, mixed> $definition
     */
    private function embeddedFormMarkup(string $type, array $definition): string
    {
        $name = htmlspecialchars(trim((string) ($definition['name'] ?? 'Form')), ENT_QUOTES, 'UTF-8');
        $rawSlug = trim((string) ($definition['slug'] ?? ''));
        $slug = htmlspecialchars($rawSlug, ENT_QUOTES, 'UTF-8');

        if ($type === 'contact') {
            $flash = $this->pullContactFormFlash($rawSlug);
            $flashMarkup = '';
            $oldValues = [
                'name' => '',
                'email' => '',
                'message' => '',
                'additional' => [],
            ];
            if ($flash !== null) {
                $flashType = (string) ($flash['type'] ?? 'error');
                $flashClass = $flashType === 'success' ? 'alert-success' : 'alert-danger';
                $flashMessage = htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($flashMessage !== '') {
                    $flashMarkup = '<div class="alert ' . $flashClass . '" role="alert">' . $flashMessage . '</div>';
                }

                /** @var mixed $rawOld */
                $rawOld = $flash['old'] ?? [];
                if (is_array($rawOld)) {
                    $oldValues['name'] = htmlspecialchars((string) ($rawOld['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $oldValues['email'] = htmlspecialchars((string) ($rawOld['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $oldValues['message'] = htmlspecialchars((string) ($rawOld['message'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $oldValues['additional'] = is_array($rawOld['additional'] ?? null) ? (array) $rawOld['additional'] : [];
                }
            }

            $additionalFieldMarkup = '';
            foreach ($this->contactAdditionalFieldDefinitions($rawSlug, $definition) as $fieldDefinition) {
                $fieldLabel = htmlspecialchars((string) ($fieldDefinition['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $fieldName = (string) ($fieldDefinition['name'] ?? '');
                $inputName = htmlspecialchars((string) ($fieldDefinition['input_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
                $requiredAttr = (bool) ($fieldDefinition['required'] ?? false) ? ' required' : '';
                $rawOldAdditional = (string) ($oldValues['additional'][$fieldName] ?? '');
                $fieldValue = htmlspecialchars($rawOldAdditional, ENT_QUOTES, 'UTF-8');

                if ($fieldType === 'textarea') {
                    $additionalFieldMarkup .= '<div class="col-12"><label class="form-label">' . $fieldLabel . '</label>'
                        . '<textarea class="form-control" name="' . $inputName . '" rows="3"' . $requiredAttr . '>' . $fieldValue . '</textarea></div>';
                    continue;
                }

                $inputType = $fieldType === 'email' ? 'email' : 'text';
                $additionalFieldMarkup .= '<div class="col-md-6"><label class="form-label">' . $fieldLabel . '</label>'
                    . '<input type="' . $inputType . '" class="form-control" name="' . $inputName . '" value="' . $fieldValue . '"' . $requiredAttr . '></div>';
            }

            $submitAction = '/contact-form/submit/' . rawurlencode($rawSlug);
            $submitAction = htmlspecialchars($submitAction, ENT_QUOTES, 'UTF-8');
            $returnPath = htmlspecialchars($this->sanitizePublicReturnPath((string) ($_SERVER['REQUEST_URI'] ?? '/')), ENT_QUOTES, 'UTF-8');
            $captchaMarkup = $this->publicCaptchaMarkup();

            return '<section class="card my-3 raven-embedded-form raven-embedded-form-contact" id="contact-form-' . $slug . '" data-raven-form-type="contact" data-raven-form-slug="' . $slug . '">'
                . '<div class="card-body">'
                . '<h3 class="h5 mb-3">' . $name . '</h3>'
                . $flashMarkup
                . '<form method="post" action="' . $submitAction . '" novalidate>'
                . $this->csrf->field()
                . '<input type="hidden" name="return_path" value="' . $returnPath . '">'
                . '<div class="row g-3">'
                . '<div class="col-md-6"><label class="form-label">Name</label><input type="text" class="form-control" name="contact_name" placeholder="Your name" value="' . $oldValues['name'] . '" required></div>'
                . '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="contact_email" placeholder="you@example.com" value="' . $oldValues['email'] . '" required></div>'
                . '<div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="contact_message" rows="4" placeholder="How can we help?" required>' . $oldValues['message'] . '</textarea></div>'
                . $additionalFieldMarkup
                . $captchaMarkup
                . '<div class="col-12"><button type="submit" class="btn btn-primary">Send Message</button></div>'
                . '</div>'
                . '</form>'
                . '</div>'
                . '</section>';
        }

        $flash = $this->pullWaitlistFormFlash($rawSlug);
        $flashMarkup = '';
        $oldValues = [
            'email' => '',
            'display_name' => '',
            'country' => '',
            'additional' => [],
        ];
        if ($flash !== null) {
            $flashType = (string) ($flash['type'] ?? 'error');
            $flashClass = $flashType === 'success' ? 'alert-success' : 'alert-danger';
            $flashMessage = htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($flashMessage !== '') {
                $flashMarkup = '<div class="alert ' . $flashClass . '" role="alert">' . $flashMessage . '</div>';
            }

            /** @var mixed $rawOld */
            $rawOld = $flash['old'] ?? [];
            if (is_array($rawOld)) {
                $oldValues['email'] = htmlspecialchars((string) ($rawOld['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['display_name'] = htmlspecialchars((string) ($rawOld['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['country'] = strtolower((string) ($rawOld['country'] ?? ''));
                $oldValues['additional'] = is_array($rawOld['additional'] ?? null) ? (array) $rawOld['additional'] : [];
            }
        }

        $countryOptionsMarkup = '<option value="">Select country</option>';
        foreach ($this->waitlistCountryOptions() as $countryCode => $countryLabel) {
            $selectedAttr = $oldValues['country'] === $countryCode ? ' selected' : '';
            $countryOptionsMarkup .= '<option value="' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '"' . $selectedAttr . '>'
                . htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8')
                . '</option>';
        }

        $additionalFieldMarkup = '';
        foreach ($this->signupsAdditionalFieldDefinitions($rawSlug, $definition) as $fieldDefinition) {
            $fieldLabel = htmlspecialchars((string) ($fieldDefinition['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $inputName = htmlspecialchars((string) ($fieldDefinition['input_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $requiredAttr = (bool) ($fieldDefinition['required'] ?? false) ? ' required' : '';
            $rawOldAdditional = (string) ($oldValues['additional'][$fieldName] ?? '');
            $fieldValue = htmlspecialchars($rawOldAdditional, ENT_QUOTES, 'UTF-8');

            if ($fieldType === 'textarea') {
                $additionalFieldMarkup .= '<div class="col-12"><label class="form-label">' . $fieldLabel . '</label>'
                    . '<textarea class="form-control" name="' . $inputName . '" rows="3"' . $requiredAttr . '>' . $fieldValue . '</textarea></div>';
                continue;
            }

            $inputType = $fieldType === 'email' ? 'email' : 'text';
            $additionalFieldMarkup .= '<div class="col-md-6"><label class="form-label">' . $fieldLabel . '</label>'
                . '<input type="' . $inputType . '" class="form-control" name="' . $inputName . '" value="' . $fieldValue . '"' . $requiredAttr . '></div>';
        }

        $submitAction = '/signups/submit/' . rawurlencode($rawSlug);
        $submitAction = htmlspecialchars($submitAction, ENT_QUOTES, 'UTF-8');
        $returnPath = htmlspecialchars($this->sanitizePublicReturnPath((string) ($_SERVER['REQUEST_URI'] ?? '/')), ENT_QUOTES, 'UTF-8');
        $captchaMarkup = $this->publicCaptchaMarkup();

        return '<section class="card my-3 raven-embedded-form raven-embedded-form-signups" id="signups-' . $slug . '" data-raven-form-type="signups" data-raven-form-slug="' . $slug . '">'
            . '<div class="card-body">'
            . '<h3 class="h5 mb-3">' . $name . '</h3>'
            . $flashMarkup
            . '<form method="post" action="' . $submitAction . '" novalidate>'
            . $this->csrf->field()
            . '<input type="hidden" name="return_path" value="' . $returnPath . '">'
            . '<div class="row g-3">'
            . '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="signups_email" placeholder="you@example.com" value="' . $oldValues['email'] . '" required></div>'
            . '<div class="col-md-6"><label class="form-label">Display Name</label><input type="text" class="form-control" name="signups_display_name" placeholder="Your name" value="' . $oldValues['display_name'] . '" required></div>'
            . '<div class="col-md-6"><label class="form-label">Country</label><select class="form-select" name="signups_country" required>'
            . $countryOptionsMarkup
            . '</select></div>'
            . $additionalFieldMarkup
            . $captchaMarkup
            . '<div class="col-12"><button type="submit" class="btn btn-primary">Join Signup Sheet</button></div>'
            . '</div>'
            . '</form>'
            . '</div>'
            . '</section>';
    }
}
