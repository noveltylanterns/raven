<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/PanelController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller;

use ZipArchive;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Raven\Core\Auth\AuthService;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Config;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Core\Media\PageImageManager;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Repository\CategoryRepository;
use Raven\Core\Security\AvatarValidator;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\QrCodeService;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\TotpService;
use Raven\Lib\Security\TwoFactorMethodNormalizer;
use Raven\Lib\Security\WebAuthnService;
use Raven\Core\View;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyRepository;
use Raven\Repository\UserRepository;

use function Raven\Core\Support\redirect;

/**
 * Handles panel pages after authentication.
 */
final class PanelController
{
    /** Fixed side length for generated avatar thumbnail JPEG files. */
    private const AVATAR_THUMB_SIZE = 120;
    private const SESSION_WEBAUTHN_PREFERENCES_CHALLENGE = '_raven_preferences_webauthn_challenge';
    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private PageImageRepository $pageImages;
    private PageImageManager $pageImageManager;
    private CategoryRepository $categories;
    private ChannelRepository $channels;
    private GroupRepository $groups;
    private PageRepository $pages;
    private RedirectRepository $redirects;
    private TagRepository $tags;
    private TaxonomyRepository $taxonomy;
    private UserRepository $users;
    private InviteTokenRepository $inviteTokens;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;

    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        InputSanitizer $input,
        Csrf $csrf,
        PageImageRepository $pageImages,
        PageImageManager $pageImageManager,
        CategoryRepository $categories,
        ChannelRepository $channels,
        GroupRepository $groups,
        PageRepository $pages,
        RedirectRepository $redirects,
        TagRepository $tags,
        TaxonomyRepository $taxonomy,
        UserRepository $users,
        InviteTokenRepository $inviteTokens
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->pageImages = $pageImages;
        $this->pageImageManager = $pageImageManager;
        $this->categories = $categories;
        $this->channels = $channels;
        $this->groups = $groups;
        $this->pages = $pages;
        $this->redirects = $redirects;
        $this->tags = $tags;
        $this->taxonomy = $taxonomy;
        $this->users = $users;
        $this->inviteTokens = $inviteTokens;
    }

    /**
     * Dashboard landing page.
     */
    public function dashboard(): void
    {
        $this->requirePanelLogin();
        $panelIdentity = $this->panelIdentityFromSession();

        $this->view->render('panel/dashboard', [
            'site' => $this->siteData(),
            'user' => [
                'email' => (string) ($panelIdentity['email'] ?? ''),
            ],
            'canManageUsers' => $this->auth->canManageUsers(),
            'canManageGroups' => $this->auth->canManageGroups(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'dashboard',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Pages list route.
     */
    public function pagesList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('pages', 'view')) {
            return;
        }

        $prefilterChannel = $this->input->slug($_GET['channel'] ?? null) ?? '';
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        if (!$this->categoryEnabled()) {
            $prefilterCategoryId = 0;
        }
        if (!$this->tagEnabled()) {
            $prefilterTagId = 0;
        }
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->pages->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterChannel !== '' ? $prefilterChannel : null,
            $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
            $prefilterTagId > 0 ? $prefilterTagId : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pages->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterChannel !== '' ? $prefilterChannel : null,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }
        $prefilterCategoryIds = $prefilterCategoryId > 0 ? [$prefilterCategoryId] : [];
        $prefilterTagIds = $prefilterTagId > 0 ? [$prefilterTagId] : [];
        foreach ($pages as &$pageRow) {
            // Server-side page prefilters already constrain result rows, so list rows only
            // need the active prefilter ids for client-side in-page filter persistence.
            $pageRow['category_ids'] = $prefilterCategoryIds;
            $pageRow['tag_ids'] = $prefilterTagIds;
        }
        unset($pageRow);

        $this->view->render('panel/pages/list', [
            'site' => $this->siteData(),
            'pages' => $pages,
            'prefilterChannel' => strtolower($prefilterChannel),
            'prefilterCategoryId' => $prefilterCategoryId,
            'prefilterTagId' => $prefilterTagId,
            'pagination' => $this->panelPaginationViewData(
                '/pages',
                $pagination,
                [
                    'channel' => $prefilterChannel,
                    'category' => $prefilterCategoryId > 0 ? (string) $prefilterCategoryId : '',
                    'tag' => $prefilterTagId > 0 ? (string) $prefilterTagId : '',
                ]
            ),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'pages',
            'pagesNav' => 'list',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Pages edit/create route.
     */
    public function pagesEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('pages', $requiredAction)) {
            return;
        }

        $pagesNavChannel = '';
        if ($id === null) {
            $requestedChannel = $this->input->slug($_GET['channel'] ?? null);
            if (is_string($requestedChannel) && $requestedChannel !== '') {
                $pagesNavChannel = $requestedChannel;
            }
        }

        // Null id means create mode; numeric id means edit mode.
        $page = null;
        $galleryImages = [];
        if ($id !== null) {
            $editData = $this->pages->editFormDataById($id);
            if (is_array($editData)) {
                $page = is_array($editData['page'] ?? null) ? $editData['page'] : null;
                $galleryImages = is_array($editData['gallery_images'] ?? null) ? $editData['gallery_images'] : [];
            }
        }
        // Load channel/category/tag options and page assignments in one query.
        $categoryEnabled = $this->categoryEnabled();
        $tagEnabled = $this->tagEnabled();
        $taxonomyData = $this->taxonomy->listPageEditorTaxonomyData($id ?? 0, $categoryEnabled, $tagEnabled);
        $channelOptions = is_array($taxonomyData['channels'] ?? null) ? $taxonomyData['channels'] : [];
        foreach ($channelOptions as &$channelOption) {
            if (!is_array($channelOption)) {
                continue;
            }

            $channelOption['text_editor_override'] = $this->normalizeChannelTextEditorOverride(
                (string) ($channelOption['text_editor_override'] ?? 'inherit')
            );
            $channelOption['page_route_mode'] = $this->normalizeChannelPageRouteMode(
                (string) ($channelOption['page_route_mode'] ?? 'slug')
            );
            $channelOption['page_url_separator'] = $this->normalizeChannelPageUrlSeparator(
                (string) ($channelOption['page_url_separator'] ?? 'inherit')
            );
        }
        unset($channelOption);
        if ($id === null && $pagesNavChannel !== '') {
            $channelExists = false;
            foreach ($channelOptions as $channelOption) {
                if (!is_array($channelOption)) {
                    continue;
                }

                if (strtolower(trim((string) ($channelOption['slug'] ?? ''))) === strtolower($pagesNavChannel)) {
                    $channelExists = true;
                    break;
                }
            }

            if ($channelExists) {
                if (!is_array($page)) {
                    $page = [];
                }
                $page['channel_slug'] = $pagesNavChannel;
            } else {
                $pagesNavChannel = '';
            }
        }
        $categoryOptions = is_array($taxonomyData['categories'] ?? null) ? $taxonomyData['categories'] : [];
        $tagOptions = is_array($taxonomyData['tags'] ?? null) ? $taxonomyData['tags'] : [];
        $assignedCategories = is_array($taxonomyData['assigned_categories'] ?? null) ? $taxonomyData['assigned_categories'] : [];
        $assignedTags = is_array($taxonomyData['assigned_tags'] ?? null) ? $taxonomyData['assigned_tags'] : [];
        $currentUserId = $this->auth->userId();

        $this->view->render('panel/pages/edit', [
            'site' => $this->siteData(),
            'page' => $page,
            'currentUserId' => $currentUserId !== null ? $currentUserId : 0,
            'authorOptions' => $this->pageAuthorOptions(),
            'channelOptions' => $channelOptions,
            'categoryOptions' => $categoryOptions,
            'tagOptions' => $tagOptions,
            'assignedCategories' => $assignedCategories,
            'assignedTags' => $assignedTags,
            'categoryEnabled' => $categoryEnabled,
            'tagEnabled' => $tagEnabled,
            'galleryImages' => $galleryImages,
            'imageUploadTarget' => (string) $this->config->get('media.images.upload_target', 'local'),
            'imageMaxFilesPerUpload' => max(0, (int) $this->config->get('media.images.max_files_per_upload', 10)),
            'defaultTextEditor' => $this->normalizeBodyTextEditorOption(
                (string) $this->config->get('content.default_editor', 'tinymce')
            ),
            'defaultPageUrlSeparator' => $this->normalizeGlobalPageUrlSeparator(
                (string) $this->config->get('content.separator', '-')
            ),
            'bodyBlockTypeDefinitions' => $this->pageEditorBodyBlockTypeDefinitions(),
            'shortcodeInsertItems' => $this->pageEditorInsertableShortcodes(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'pages',
            // Highlight "Create Page" only when opening the new-page form.
            'pagesNav' => $id === null ? 'create' : null,
            'pagesNavChannel' => $id === null ? $pagesNavChannel : null,
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves page form using CSRF + centralized input sanitizer.
     */
    public function pagesSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('pages', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['content', 'meta', 'media'], 'content');
        $title = $this->input->text($post['title'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $legacyContent = $this->input->html($post['content'] ?? null, 500000);
        $extendedBlocks = $this->normalizeExtendedBlocksInput($post['extended_blocks'] ?? []);
        if ($extendedBlocks === [] && trim($legacyContent) !== '') {
            $extendedBlocks[] = [
                'type' => 'tinymce',
                'content' => $legacyContent,
            ];
        }
        $description = $this->input->text($post['description'] ?? null, 1000);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $displayTitle = isset($post['display_title']) && (string) $post['display_title'] === '1';
        $galleryEnabled = $this->pageBodyBlocksIncludeGallery($extendedBlocks)
            || (isset($post['gallery_enabled']) && (string) $post['gallery_enabled'] === '1');
        $authorUserId = $this->input->int($post['author_user_id'] ?? null, 1);
        if ($authorUserId !== null && $this->users->findById($authorUserId) === null) {
            $this->flash('error', 'Selected author account was not found.');
            redirect($this->panelEditorUrlWithTab('/pages/edit', $id, $activeTab, 'meta'));
        }
        if ($authorUserId === null) {
            $authorUserId = $this->auth->userId();
        }
        // Body content is now authored via repeatable body blocks in `extended`.
        $content = '';
        $categoryIds = [];
        $tagIds = [];

        /** @var mixed $categoryIdsRaw */
        $categoryIdsRaw = $post['category_ids'] ?? [];
        /** @var mixed $tagIdsRaw */
        $tagIdsRaw = $post['tag_ids'] ?? [];
        /** @var mixed $galleryImagesRaw */
        $galleryImagesRaw = $post['gallery_images'] ?? [];

        $galleryImageUpdates = $this->normalizeGalleryImageUpdates($galleryImagesRaw);

        $categoryEnabled = $this->categoryEnabled();
        $tagEnabled = $this->tagEnabled();

        if ($categoryEnabled && is_array($categoryIdsRaw)) {
            foreach ($categoryIdsRaw as $rawCategoryId) {
                $parsed = $this->input->int($rawCategoryId, 1);
                if ($parsed !== null) {
                    $categoryIds[] = $parsed;
                }
            }
        }

        if ($tagEnabled && is_array($tagIdsRaw)) {
            foreach ($tagIdsRaw as $rawTagId) {
                $parsed = $this->input->int($rawTagId, 1);
                if ($parsed !== null) {
                    $tagIds[] = $parsed;
                }
            }
        }

        // Only keep ids that currently exist, preventing stale/manual post values.
        $categoryIds = $categoryEnabled ? $this->categories->existingIds($categoryIds) : [];
        $tagIds = $tagEnabled ? $this->tags->existingIds($tagIds) : [];

        if ($title === '' || $slug === null) {
            $this->flash('error', 'Title and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/pages/edit', $id, $activeTab, 'content'));
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            $this->flash('error', 'Status must be Published or Draft.');
            redirect($this->panelEditorUrlWithTab('/pages/edit', $id, $activeTab, 'content'));
        }

        // Normalize panel form input into repository payload shape.
        try {
            $savedId = $this->pages->save([
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'extended_blocks' => $extendedBlocks,
                'description' => $description,
                'display_title' => $displayTitle ? 1 : 0,
                'gallery_enabled' => $galleryEnabled ? 1 : 0,
                'author_user_id' => $authorUserId,
                'channel_slug' => $channelSlug,
                'category_ids' => $categoryIds,
                'tag_ids' => $tagIds,
                'is_published' => $status === 'published' ? 1 : 0,
                'published_at' => gmdate('Y-m-d H:i:s'),
            ]);

            // Keep Media tab metadata and page-level gallery toggle in sync with save.
            $this->pageImages->updateGalleryForPage(
                $savedId,
                $galleryEnabled,
                $galleryImageUpdates
            );
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() ?: 'Failed to save page.');
            redirect($this->panelEditorUrlWithTab('/pages/edit', $id, $activeTab, 'content'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/pages/edit', $savedId, $activeTab, 'content'));
    }

    /**
     * Returns page-author select options for page editor Meta tab.
     *
     * @return array<int, array{id: int, username: string, display_name: string}>
     */
    private function pageAuthorOptions(): array
    {
        $options = [];
        foreach ($this->users->listAll() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $userId = (int) ($entry['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $username = $this->normalizeUserIdentifierValue((string) ($entry['username'] ?? ''));
            if ($username === null || $username === '') {
                continue;
            }

            $options[$userId] = [
                'id' => $userId,
                'username' => $username,
                'display_name' => $this->input->text((string) ($entry['display_name'] ?? ''), 120),
            ];
        }

        $result = array_values($options);
        usort($result, static function (array $left, array $right): int {
            $leftLabel = strtolower(trim((string) (($left['display_name'] ?? '') !== '' ? $left['display_name'] : $left['username'])));
            $rightLabel = strtolower(trim((string) (($right['display_name'] ?? '') !== '' ? $right['display_name'] : $right['username'])));
            if ($leftLabel !== $rightLabel) {
                return $leftLabel <=> $rightLabel;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $result;
    }

    /**
     * Normalizes optional repeatable page-editor body blocks.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function normalizeExtendedBlocksInput(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
            // Keep payload bounded while still allowing substantial long-form pages.
            if (count($blocks) >= 50) {
                break;
            }

            $type = 'tinymce';
            $value = $entry;
            $cssId = '';
            $cssClass = '';
            if (is_array($entry)) {
                $type = $this->normalizeBodyBlockType((string) ($entry['type'] ?? 'tinymce'));
                $value = $entry['content'] ?? '';
                $cssId = $this->normalizeBodyBlockCssId($entry['css_id'] ?? null);
                $cssClass = $this->normalizeBodyBlockCssClassList($entry['css_class'] ?? null);
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $editorMode = $this->bodyBlockEditorMode($type);
            $normalized = $this->input->html($value !== null ? (string) $value : null, 500000);
            if ($editorMode === 'markdown_file') {
                $normalized = trim($normalized);
            }

            if ($editorMode === 'gallery') {
                $blocks[] = [
                    'type' => $type,
                    'content' => '',
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            if (trim($normalized) === '') {
                continue;
            }

            $blocks[] = [
                'type' => $type,
                'content' => $normalized,
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        return $blocks;
    }

    /**
     * Normalizes one optional body-block CSS id token.
     */
    private function normalizeBodyBlockCssId(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $id = str_replace("\0", '', trim((string) ($value ?? '')));
        $id = ltrim($id, '#');
        if ($id === '') {
            return '';
        }

        if (mb_strlen($id) > 120) {
            $id = mb_substr($id, 0, 120);
        }

        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $id) === 1
            ? $id
            : '';
    }

    /**
     * Normalizes optional body-block CSS class list into one space-delimited value.
     */
    private function normalizeBodyBlockCssClassList(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $raw = str_replace("\0", '', trim((string) ($value ?? '')));
        if ($raw === '') {
            return '';
        }

        $classMap = [];
        $classes = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $token) {
            $token = ltrim(trim((string) $token), '.');
            if ($token === '' || preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $token) !== 1) {
                continue;
            }

            $key = strtolower($token);
            if (isset($classMap[$key])) {
                continue;
            }

            $classMap[$key] = true;
            $classes[] = $token;
            if (count($classes) >= 12) {
                break;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Returns true when at least one body block requests gallery output.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
     */
    private function pageBodyBlocksIncludeGallery(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($this->bodyBlockEditorMode((string) ($block['type'] ?? '')) === 'gallery') {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes one text-editor option value from config/editor payloads.
     */
    private function normalizeBodyTextEditorOption(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Normalizes one channel text-editor override value.
     */
    private function normalizeChannelTextEditorOverride(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'inherit';
    }

    /**
     * Normalizes one channel page-route mode value.
     */
    private function normalizeChannelPageRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['slug', 'date_slug'], true)
            ? $mode
            : 'slug';
    }

    /**
     * Normalizes one channel page-url separator option.
     */
    private function normalizeChannelPageUrlSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['inherit', '-', '_'], true)
            ? $separator
            : 'inherit';
    }

    /**
     * Normalizes one global page-url separator option.
     */
    private function normalizeGlobalPageUrlSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['-', '_'], true)
            ? $separator
            : '-';
    }

    /**
     * Resolves channel page-url separator from channel option + global default.
     */
    private function resolveChannelPageUrlSeparator(string $channelValue): string
    {
        $normalizedChannel = $this->normalizeChannelPageUrlSeparator($channelValue);
        if ($normalizedChannel !== 'inherit') {
            return $normalizedChannel;
        }

        return $this->normalizeGlobalPageUrlSeparator(
            (string) $this->config->get('content.separator', '-')
        );
    }

    /**
     * Normalizes one body-block type value.
     */
    private function normalizeBodyBlockType(string $value): string
    {
        $type = strtolower(trim($value));
        if ($type === '') {
            return 'tinymce';
        }

        $definitions = $this->pageEditorBodyBlockTypeDefinitions();
        return array_key_exists($type, $definitions) ? $type : 'tinymce';
    }

    /**
     * Resolves editor mode for one body-block type key.
     */
    private function bodyBlockEditorMode(string $type): string
    {
        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return 'tinymce';
        }

        $definitions = $this->pageEditorBodyBlockTypeDefinitions();
        $editor = strtolower(trim((string) ($definitions[$normalized]['editor'] ?? 'tinymce')));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'gallery'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Returns page-editor body-block type definitions.
     *
     * @return array<string, array{label: string, editor: string}>
     */
    private function pageEditorBodyBlockTypeDefinitions(): array
    {
        if (is_array($this->pageBodyBlockTypeDefinitionsCache)) {
            return $this->pageBodyBlockTypeDefinitionsCache;
        }

        $definitions = [
            'tinymce' => ['label' => 'Rich Text', 'editor' => 'tinymce'],
            'plaintext' => ['label' => 'Plaintext', 'editor' => 'plaintext'],
            'autobr' => ['label' => 'Auto <br>', 'editor' => 'autobr'],
            'markdown' => ['label' => 'Markdown', 'editor' => 'markdown'],
            'markdown_file' => ['label' => 'Markdown File', 'editor' => 'markdown_file'],
            'image_gallery' => ['label' => 'Image Gallery', 'editor' => 'gallery'],
        ];

        foreach ($this->extensionProvidedBodyBlocksForEditor($this->loadExtensionStateMap()) as $type => $entry) {
            if (isset($definitions[$type])) {
                continue;
            }

            $definitions[$type] = $entry;
        }

        $this->pageBodyBlockTypeDefinitionsCache = $definitions;
        return $definitions;
    }

    /**
     * Loads extension-provided body-block definitions for page editor menus.
     *
     * Each enabled `content`/`plugin`/`module` extension may optionally define `lib/fields.php`
     * returning either:
     * - array<int, array{slug: string, label: string, editor: string}>
     * - callable(array{extension?: string}): array<int, array{slug: string, label: string, editor: string}>
     *
     * @param array<string, bool> $enabledMap
     * @return array<string, array{label: string, editor: string}>
     */
    private function extensionProvidedBodyBlocksForEditor(array $enabledMap): array
    {
        $definitions = [];
        foreach ($enabledMap as $extensionName => $enabled) {
            if (!$enabled) {
                continue;
            }

            $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $this->readExtensionManifest($extensionPath);
            if (
                !($manifest['valid'] ?? false)
                || !in_array((string) ($manifest['type'] ?? ''), ['content', 'plugin', 'module'], true)
            ) {
                continue;
            }

            $fields = ExtensionRegistry::fields(
                dirname(__DIR__, 3),
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                ]
            );
            if ($fields === null) {
                continue;
            }

            foreach ($fields as $entry) {
                $slug = $this->input->slug((string) ($entry['slug'] ?? ''));
                if ($slug === null || $slug === '') {
                    continue;
                }

                $normalizedSlug = str_replace('-', '_', strtolower($slug));
                $normalizedExtension = str_replace('-', '_', strtolower($extensionName));
                $type = 'content_' . $normalizedExtension . '_' . $normalizedSlug;
                if (!preg_match('/^[a-z0-9_]{1,120}$/', $type)) {
                    continue;
                }

                $label = $this->input->text((string) ($entry['label'] ?? ''), 120);
                $editor = strtolower(trim((string) ($entry['editor'] ?? 'tinymce')));
                if ($label === '' || !in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file'], true)) {
                    continue;
                }
                if (isset($definitions[$type])) {
                    continue;
                }

                $definitions[$type] = [
                    'label' => $label,
                    'editor' => $editor,
                ];
            }
        }

        uasort($definitions, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $definitions;
    }

    /**
     * Uploads one gallery image for an existing page.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function pagesGalleryUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('pages', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        if ($pageId === null || !$this->pageImages->pageExists($pageId)) {
            $this->flash('error', 'Save the page before uploading gallery images.');
            redirect($this->panelUrl('/pages'));
        }

        /** @var mixed $rawUploads */
        $rawUploads = $files['gallery_upload_image'] ?? null;
        $uploads = $this->normalizeUploadedFileSet($rawUploads);

        if ($uploads === []) {
            $this->flash('error', 'Please select one or more images to upload.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $maxFilesPerUpload = max(0, (int) $this->config->get('media.images.max_files_per_upload', 10));
        if ($maxFilesPerUpload > 0 && count($uploads) > $maxFilesPerUpload) {
            $this->flash(
                'error',
                'You selected ' . count($uploads) . ' image(s), but the max per upload is ' . $maxFilesPerUpload . '.'
            );
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $successCount = 0;
        $errors = [];

        foreach ($uploads as $upload) {
            $result = $this->pageImageManager->uploadForPage($pageId, $upload);
            if ((bool) ($result['ok'] ?? false)) {
                $successCount++;
                continue;
            }

            $errors[] = (string) ($result['error'] ?? 'Failed to upload one image.');
        }

        if ($successCount > 0) {
            $this->flash(
                'success',
                'Uploaded ' . $successCount . ' image' . ($successCount === 1 ? '' : 's') . '.'
            );
        }

        if ($errors !== []) {
            $this->flash('error', implode(' ', array_values(array_unique($errors))));
        }

        redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
    }

    /**
     * Deletes one gallery image from an existing page.
     *
     * @param array<string, mixed> $post
     */
    public function pagesGalleryDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('pages', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        $imageId = $this->input->int($post['gallery_delete_image_id'] ?? null, 1);
        $selectedImageIds = $this->selectedIdsFromPost($post, 'gallery_delete_image_ids');

        if ($pageId === null) {
            $this->flash('error', 'Invalid image delete request.');
            redirect($this->panelUrl('/pages'));
        }

        // Single-row delete action has priority when explicit image id is posted.
        if ($imageId !== null) {
            if (!$this->pageImageManager->deleteImageForPage($pageId, $imageId)) {
                $this->flash('error', 'Image not found or already deleted.');
                redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
            }

            $this->flash('success', 'Image deleted.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        // Bulk-delete path is used by Media-tab "Delete Selected" controls.
        if ($selectedImageIds === []) {
            $this->flash('error', 'No gallery images selected.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedImageIds as $selectedImageId) {
            if ($this->pageImageManager->deleteImageForPage($pageId, $selectedImageId)) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' image' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected image' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected images.');
        }

        redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
    }

    /**
     * Deletes one page and its relation rows.
     *
     * @param array<string, mixed> $post
     */
    public function pagesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('pages', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->pageImageManager->deleteAllForPage($id);
                $this->pages->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete page.');
                redirect($this->panelUrl('/pages'));
            }

            $this->flash('success', 'Page deleted.');
            redirect($this->panelUrl('/pages'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No pages selected.');
            redirect($this->panelUrl('/pages'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Keep processing all selected ids even when one delete fails.
                $this->pageImageManager->deleteAllForPage($selectedId);
                $this->pages->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' page' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected page' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected pages.');
        }

        redirect($this->panelUrl('/pages'));
    }

    /**
     * Lists channels for Channel management section.
     */
    public function channelsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('channels', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->channels->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $channels = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->channels->listPageForPanel($perPage, $pagination['offset']);
            $channels = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/channels/list', [
            'site' => $this->siteData(),
            'channels' => $channels,
            'pagination' => $this->panelPaginationViewData('/channels', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'channels',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows channel create/edit form.
     */
    public function channelsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('channels', $requiredAction)) {
            return;
        }

        $channel = null;
        if ($id !== null) {
            $channel = $this->channels->findById($id);

            if ($channel === null) {
                $this->flash('error', 'Channel not found.');
                redirect($this->panelUrl('/channels'));
            }
        }

        if (is_array($channel)) {
            $channel['text_editor_override'] = $this->normalizeChannelTextEditorOverride(
                (string) ($channel['text_editor_override'] ?? 'inherit')
            );
            $channel['page_route_mode'] = $this->normalizeChannelPageRouteMode(
                (string) ($channel['page_route_mode'] ?? 'slug')
            );
            $channel['page_url_separator'] = $this->normalizeChannelPageUrlSeparator(
                (string) ($channel['page_url_separator'] ?? 'inherit')
            );
        }

        $this->view->render('panel/channels/edit', [
            'site' => $this->siteData(),
            'channel' => $channel,
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'channels',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one channel from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function channelsSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('channels', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channels'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'content', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);
        $textEditorOverride = $this->normalizeChannelTextEditorOverride(
            (string) ($post['text_editor_override'] ?? 'inherit')
        );
        $pageRouteMode = $this->normalizeChannelPageRouteMode(
            (string) ($post['page_route_mode'] ?? 'slug')
        );
        $pageUrlSeparator = $this->normalizeChannelPageUrlSeparator(
            (string) ($post['page_url_separator'] ?? 'inherit')
        );

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Channel name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/channels/edit', $id, $activeTab, 'basic'));
        }

        // Persist one channel record; repository handles create vs update.
        try {
            $savedId = $this->channels->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'text_editor_override' => $textEditorOverride,
                'page_route_mode' => $pageRouteMode,
                'page_url_separator' => $pageUrlSeparator,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save channel. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/channels/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/channels/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->channels->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('channels', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('channels', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->channels->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save channel image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('channels', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one channel and detaches linked pages.
     *
     * @param array<string, mixed> $post
     */
    public function channelsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('channels', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channels'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->channels->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->channels->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete channel.');
                redirect($this->panelUrl('/channels'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'channels',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Channel deleted.');
            redirect($this->panelUrl('/channels'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No channels selected.');
            redirect($this->panelUrl('/channels'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->channels->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->channels->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'channels',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' channel' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected channel' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected channels.');
        }

        redirect($this->panelUrl('/channels'));
    }

    /**
     * Lists categories for Category management section.
     */
    public function categoriesList(): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('categories', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->categories->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $categories = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->categories->listPageForPanel($perPage, $pagination['offset']);
            $categories = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/categories/list', [
            'site' => $this->siteData(),
            'categories' => $categories,
            'pagination' => $this->panelPaginationViewData('/categories', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'categories',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows category create/edit form.
     */
    public function categoriesEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('categories', $requiredAction)) {
            return;
        }

        $category = null;
        if ($id !== null) {
            $category = $this->categories->findById($id);

            if ($category === null) {
                $this->flash('error', 'Category not found.');
                redirect($this->panelUrl('/categories'));
            }
        }

        $this->view->render('panel/categories/edit', [
            'site' => $this->siteData(),
            'category' => $category,
            'categoryRoutePrefix' => $this->categoryRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'categories',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one category from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function categoriesSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('categories', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/categories'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Category name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/categories/edit', $id, $activeTab, 'basic'));
        }

        // Persist one category; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->categories->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save category. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/categories/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/categories/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->categories->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('categories', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('categories', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->categories->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save category image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('categories', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one category and removes page-category links.
     *
     * @param array<string, mixed> $post
     */
    public function categoriesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('categories', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/categories'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->categories->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->categories->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete category.');
                redirect($this->panelUrl('/categories'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'categories',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Category deleted.');
            redirect($this->panelUrl('/categories'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No categories selected.');
            redirect($this->panelUrl('/categories'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->categories->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->categories->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'categories',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' ' . ($deletedCount === 1 ? 'category' : 'categories') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected ' . ($failedCount === 1 ? 'category' : 'categories') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected categories.');
        }

        redirect($this->panelUrl('/categories'));
    }

    /**
     * Lists tags for Tag management section.
     */
    public function tagsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tags', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->tags->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tags = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tags->listPageForPanel($perPage, $pagination['offset']);
            $tags = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/tags/list', [
            'site' => $this->siteData(),
            'tags' => $tags,
            'pagination' => $this->panelPaginationViewData('/tags', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'tags',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows tag create/edit form.
     */
    public function tagsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tags', $requiredAction)) {
            return;
        }

        $tag = null;
        if ($id !== null) {
            $tag = $this->tags->findById($id);

            if ($tag === null) {
                $this->flash('error', 'Tag not found.');
                redirect($this->panelUrl('/tags'));
            }
        }

        $this->view->render('panel/tags/edit', [
            'site' => $this->siteData(),
            'tag' => $tag,
            'tagRoutePrefix' => $this->tagRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'tags',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one tag from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function tagsSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tags', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tags'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Tag name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/tags/edit', $id, $activeTab, 'basic'));
        }

        // Persist one tag; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->tags->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save tag. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/tags/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/tags/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->tags->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('tags', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('tags', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->tags->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save tag image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('tags', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one tag and removes page-tag links.
     *
     * @param array<string, mixed> $post
     */
    public function tagsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tags', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tags'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->tags->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->tags->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete tag.');
                redirect($this->panelUrl('/tags'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'tags',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Tag deleted.');
            redirect($this->panelUrl('/tags'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No tags selected.');
            redirect($this->panelUrl('/tags'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->tags->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->tags->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'tags',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' tag' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected tag' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected tags.');
        }

        redirect($this->panelUrl('/tags'));
    }

    /**
     * Lists redirects for Redirect management section.
     */
    public function redirectsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('redirects', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->redirects->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $redirects = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->redirects->listPageForPanel($perPage, $pagination['offset']);
            $redirects = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/redirects/list', [
            'site' => $this->siteData(),
            'redirects' => $redirects,
            'pagination' => $this->panelPaginationViewData('/redirects', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'redirects',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows redirect create/edit form.
     */
    public function redirectsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('redirects', $requiredAction)) {
            return;
        }

        $editorData = $this->redirects->editFormData($id);
        $redirectRow = is_array($editorData['redirect'] ?? null) ? $editorData['redirect'] : null;
        $channelOptions = is_array($editorData['channels'] ?? null) ? $editorData['channels'] : [];

        if ($id !== null && $redirectRow === null) {
            $this->flash('error', 'Redirect not found.');
            redirect($this->panelUrl('/redirects'));
        }

        $this->view->render('panel/redirects/edit', [
            'site' => $this->siteData(),
            'redirectRow' => $redirectRow,
            'channelOptions' => $channelOptions,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'redirects',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one redirect from panel form.
     *
     * @param array<string, mixed> $post
     */
    public function redirectsSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('redirects', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirects'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $title = $this->input->text($post['title'] ?? null, 255);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $slug = $this->input->slug($post['slug'] ?? null);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $targetUrl = $this->input->text($post['target_url'] ?? null, 2048);

        if ($title === '' || $slug === null) {
            $this->flash('error', 'Redirect title and valid slug are required.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->flash('error', 'Status must be Active or Inactive.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Prevent root redirects from hijacking reserved public prefixes.
        if ($channelSlug === null && $this->isReservedPublicRootSlug($slug)) {
            $this->flash('error', 'This slug is reserved and cannot be used at root level.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Channel dropdown should only post known channel slugs.
        if ($channelSlug !== null && !$this->channels->slugExists($channelSlug)) {
            $this->flash('error', 'Selected channel does not exist.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            $this->flash('error', 'Target URL must be an absolute http(s) URL or a root-relative path.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        try {
            $savedId = $this->redirects->save([
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'channel_slug' => $channelSlug,
                'is_active' => $status === 'active' ? 1 : 0,
                'target_url' => $targetUrl,
            ]);
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to save redirect.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/redirects/edit/' . $savedId));
    }

    /**
     * Deletes one redirect or many selected redirects.
     *
     * @param array<string, mixed> $post
     */
    public function redirectsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('redirects', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirects'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->redirects->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete redirect.');
                redirect($this->panelUrl('/redirects'));
            }

            $this->flash('success', 'Redirect deleted.');
            redirect($this->panelUrl('/redirects'));
        }

        // Bulk-delete mode is used by list-level "Delete" actions.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No redirects selected.');
            redirect($this->panelUrl('/redirects'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                $this->redirects->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' redirect' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected redirect' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected redirects.');
        }

        redirect($this->panelUrl('/redirects'));
    }

    /**
     * Lists users for User management section.
     */
    public function usersList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'view')) {
            return;
        }

        $prefilterGroup = strtolower(trim((string) ($this->input->text($_GET['group'] ?? null, 120) ?? '')));
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->users->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterGroup !== '' ? $prefilterGroup : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $users = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->users->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterGroup !== '' ? $prefilterGroup : null
            );
            $users = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $groupOptions = is_array($pageResult['group_options'] ?? null)
            ? $pageResult['group_options']
            : $this->groups->listOptions();

        $this->view->render('panel/users/list', [
            'site' => $this->siteData(),
            'users' => $users,
            'prefilterGroup' => $prefilterGroup,
            'groupOptions' => $groupOptions,
            'pagination' => $this->panelPaginationViewData(
                '/users',
                $pagination,
                ['group' => $prefilterGroup]
            ),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'users',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows user create/edit form.
     */
    public function usersEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('users', $requiredAction)) {
            return;
        }

        $editData = $this->users->editFormData($id);
        $user = is_array($editData['user'] ?? null) ? $editData['user'] : null;
        if (is_array($user)) {
            $normalizedTheme = $this->normalizePanelThemeChoice((string) ($user['theme'] ?? 'default'), true);
            $user['theme'] = $normalizedTheme ?? 'default';

            if ($id !== null) {
                $preferences = $this->auth->userPreferences($id);
                $user['two_factor_methods'] = is_array($preferences['two_factor_methods'] ?? null)
                    ? array_values((array) $preferences['two_factor_methods'])
                    : [];
            } else {
                $user['two_factor_methods'] = [];
            }
        }
        if ($id !== null && $user === null) {
            $this->flash('error', 'User not found.');
            redirect($this->panelUrl('/users'));
        }
        $groupOptions = is_array($editData['group_options'] ?? null) ? $editData['group_options'] : [];
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();

        $this->view->render('panel/users/edit', [
            'site' => $this->siteData(),
            'userRow' => $user,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'profileRoutePrefix' => $this->profileRoutePrefix(),
            'profileRoutesEnabled' => $this->profileRoutesEnabledForRoutingTable(),
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'groupOptions' => $groupOptions,
            // Only existing Super Admin users can assign users into Super Admin group.
            'canAssignSuperAdmin' => $actorIsSuperAdmin,
            // Groups that include Manage System Configuration are assignable by Super Admin only.
            'canAssignConfigurationGroups' => $actorIsSuperAdmin,
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'users',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one user and group memberships.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function usersSave(array $post, array $files): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('users', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['account', 'permissions', 'profile'], 'account');
        $editPath = '/users/edit' . ($id !== null ? '/' . $id : '');
        $editUrl = $this->panelEditorUrlWithTab('/users/edit', $id, $activeTab, 'account');
        $loginIdentifierMode = $this->panelLoginIdentifierMode();
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeUserIdentifierValue($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->normalizePanelThemeChoice((string) $themeRaw, true);
        $password = $this->input->text($post['password'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $submittedTwoFactorMethodsPresent = isset($post['two_factor_methods_present'])
            && (string) ($post['two_factor_methods_present'] ?? '') === '1';
        $submittedTwoFactorMethods = $post['two_factor_methods'] ?? null;
        $submittedTwoFactorMethodIndices = $this->normalizeSubmittedTwoFactorExistingIndices($submittedTwoFactorMethods);
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $existingUser = null;
        $existingTwoFactorMethods = [];
        $canUpdateTwoFactorMethods = false;
        if ($id !== null) {
            $existingUser = $this->users->findById($id);
            if ($existingUser === null) {
                $this->flash('error', 'User not found.');
                redirect($this->panelUrl('/users'));
            }

            $existingPreferences = $this->auth->userPreferences($id);
            if (is_array($existingPreferences)) {
                $existingTwoFactorMethods = is_array($existingPreferences['two_factor_methods'] ?? null)
                    ? array_values($existingPreferences['two_factor_methods'])
                    : [];
                $canUpdateTwoFactorMethods = true;
            }
        }

        $currentAvatarPath = is_array($existingUser) && isset($existingUser['avatar_path']) && is_string($existingUser['avatar_path'])
            ? (string) $existingUser['avatar_path']
            : null;

        /** @var mixed $groupIdsRaw */
        $groupIdsRaw = $post['group_ids'] ?? [];
        $groupIds = [];

        if (is_array($groupIdsRaw)) {
            foreach ($groupIdsRaw as $raw) {
                $parsed = $this->input->int($raw, 1);
                if ($parsed !== null) {
                    $groupIds[] = $parsed;
                }
            }
        }

        // Keep only existing group ids to avoid invalid assignments.
        $groupOptions = $this->groups->listOptions();
        $validGroupIds = array_map(
            static fn (array $g): int => (int) $g['id'],
            $groupOptions
        );
        $groupIds = array_values(array_intersect($groupIds, $validGroupIds));

        $groupPermissionMasks = [];
        foreach ($groupOptions as $groupOption) {
            $groupPermissionMasks[(int) ($groupOption['id'] ?? 0)] = (int) ($groupOption['permission_mask'] ?? 0);
        }

        // Only Super Admin actors may assign users into Super Admin group.
        $superAdminGroupId = $this->groups->idBySlug('super');
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();
        if (!$actorIsSuperAdmin && $superAdminGroupId !== null) {
            $targetAlreadyHasSuperAdmin = false;

            if (is_array($existingUser)) {
                $existingGroupIds = array_map('intval', (array) ($existingUser['group_ids'] ?? []));
                $targetAlreadyHasSuperAdmin = in_array($superAdminGroupId, $existingGroupIds, true);
            }

            $requestedSuperAdmin = in_array($superAdminGroupId, $groupIds, true);
            if ($requestedSuperAdmin && !$targetAlreadyHasSuperAdmin) {
                $this->flash('error', 'Only Super Admin users can assign the Super Admin group.');
                redirect($editUrl);
            }

            // Preserve existing Super Admin membership on edits by non-Super-Admin actors.
            if ($targetAlreadyHasSuperAdmin && !in_array($superAdminGroupId, $groupIds, true)) {
                $groupIds[] = $superAdminGroupId;
            }
        }

        // Only Super Admin actors may promote users into any group that grants
        // Manage System Configuration capability.
        if (!$actorIsSuperAdmin) {
            $configurationGroupIds = [];
            $systemPanelBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
            foreach ($groupPermissionMasks as $groupIdKey => $mask) {
                if (($mask & $systemPanelBitsMask) !== 0) {
                    $configurationGroupIds[] = $groupIdKey;
                }
            }

            if ($configurationGroupIds !== []) {
                $existingGroupIds = is_array($existingUser)
                    ? array_map('intval', (array) ($existingUser['group_ids'] ?? []))
                    : [];
                $existingConfigurationGroupIds = array_values(array_intersect($existingGroupIds, $configurationGroupIds));
                $requestedConfigurationGroupIds = array_values(array_intersect($groupIds, $configurationGroupIds));
                $newConfigurationAssignments = array_values(array_diff($requestedConfigurationGroupIds, $existingConfigurationGroupIds));

                if ($newConfigurationAssignments !== []) {
                    $this->flash('error', 'Only Super Admin users can assign groups with Manage System Configuration.');
                    redirect($editUrl);
                }
            }
        }

        $usernameRequired = $loginIdentifierMode === 'username';
        $usernameInvalid = $usernameRequired
            ? !is_string($username)
            : ($rawUsername !== '' && !is_string($username));
        if ($usernameInvalid || $email === null || !is_string($theme)) {
            $this->flash(
                'error',
                $usernameRequired
                    ? 'Valid username, email, and theme are required.'
                    : 'Valid optional username, email, and theme are required.'
            );
            redirect($editUrl);
        }

        // Enforce password on create; optional on update.
        if ($id === null && (strlen($password) < 8)) {
            $this->flash('error', 'New users require a password of at least 8 characters.');
            redirect($editUrl);
        }

        if ($id !== null && $password !== '' && strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            redirect($editUrl);
        }

        // Ensure users always keep at least one group assignment.
        if ($groupIds === []) {
            $fallbackGroupId = $this->groups->idBySlug('guest');
            if ($fallbackGroupId !== null) {
                $groupIds = [$fallbackGroupId];
            }
        }

        if ($groupIds === []) {
            $this->flash('error', 'At least one user group is required.');
            redirect($editUrl);
        }

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;
        $pendingAvatarUpload = null;
        $pendingAvatarExtension = null;
        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasUpload) {
            // Validate bytes, dimensions, mime, and size before moving to public path.
            $avatarMaxSizeBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
            $avatarMaxWidth = (int) $this->config->get('media.avatars.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('media.avatars.max_height', 500);
            $avatarAllowedExtensions = $this->resolveAvatarAllowedExtensionsCsv();

            $validator = new AvatarValidator(
                $avatarMaxSizeBytes,
                $avatarMaxWidth,
                $avatarMaxHeight,
                $avatarAllowedExtensions
            );
            /** @var array<string, mixed> $avatarUpload */
            $result = $validator->validate($avatarUpload);

            if (!(bool) $result['ok']) {
                $this->flash('error', (string) ($result['error'] ?? 'Avatar upload failed.'));
                redirect($editUrl);
            }

            $normalizedExtension = $this->normalizeAvatarExtension((string) ($result['extension'] ?? ''));
            if ($normalizedExtension === null) {
                $this->flash('error', 'Avatar upload format is not supported.');
                redirect($editUrl);
            }

            if ($id !== null) {
                $avatarsDir = $this->avatarStorageDirectory();
                $avatarFilename = $this->avatarFilenameForUserId($id, $normalizedExtension);
                $destination = $avatarsDir . '/' . $avatarFilename;

                $storeError = $this->storeSanitizedAvatarUpload($avatarUpload, $destination);
                if ($storeError !== null) {
                    $this->flash('error', $storeError);
                    redirect($editUrl);
                }

                $avatarSet = true;
                $uploadedAvatarFilename = $avatarFilename;
            } else {
                // Create flow waits for DB-assigned id before deriving deterministic avatar filename.
                $pendingAvatarUpload = $avatarUpload;
                $pendingAvatarExtension = $normalizedExtension;
            }
        }

        $createdUserId = null;
        try {
            // Repository enforces uniqueness and applies password hashing.
            $savedId = $this->users->save([
                'id' => $id,
                'username' => is_string($username) ? $username : '',
                'display_name' => $displayName,
                'email' => (string) $email,
                'theme' => $theme,
                'password' => $password !== '' ? $password : null,
                'group_ids' => $groupIds,
                'contact_profiles' => $contactProfiles,
                'set_avatar' => $avatarSet,
                'avatar_path' => $avatarFilename,
            ]);

            if ($id === null && is_array($pendingAvatarUpload) && is_string($pendingAvatarExtension)) {
                $createdUserId = $savedId;
                $avatarsDir = $this->avatarStorageDirectory();
                $avatarFilename = $this->avatarFilenameForUserId($savedId, $pendingAvatarExtension);
                $destination = $avatarsDir . '/' . $avatarFilename;

                $storeError = $this->storeSanitizedAvatarUpload($pendingAvatarUpload, $destination);
                if ($storeError !== null) {
                    throw new \RuntimeException($storeError);
                }

                $avatarSet = true;
                $uploadedAvatarFilename = $avatarFilename;

                $this->users->save([
                    'id' => $savedId,
                    'username' => is_string($username) ? $username : '',
                    'display_name' => $displayName,
                    'email' => (string) $email,
                    'theme' => $theme,
                    'password' => null,
                    'group_ids' => $groupIds,
                    'contact_profiles' => $contactProfiles,
                    'set_avatar' => true,
                    'avatar_path' => $avatarFilename,
                ]);
            }
        } catch (\Throwable $exception) {
            // Roll back newly uploaded avatar when profile update fails.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            // Keep create+upload flow atomic when avatar post-write fails.
            if ($id === null && $createdUserId !== null) {
                try {
                    $this->users->deleteById($createdUserId);
                } catch (\Throwable) {
                    // Suppress cleanup failures; original save error is shown to operator.
                }
            }

            $this->flash('error', $exception->getMessage() ?: 'Failed to save user.');
            redirect($editUrl);
        }

        $twoFactorUpdateError = null;
        if ($id !== null && $canUpdateTwoFactorMethods && $submittedTwoFactorMethodsPresent) {
            $retainedTwoFactorMethods = [];
            foreach ($submittedTwoFactorMethodIndices as $methodIndex) {
                $method = $existingTwoFactorMethods[$methodIndex] ?? null;
                if (!is_array($method)) {
                    continue;
                }

                $retainedTwoFactorMethods[] = $method;
            }

            $twoFactorUpdate = $this->auth->updateUserTwoFactorMethods($savedId, $retainedTwoFactorMethods);
            if (!(bool) ($twoFactorUpdate['ok'] ?? false)) {
                $rawErrors = is_array($twoFactorUpdate['errors'] ?? null) ? $twoFactorUpdate['errors'] : [];
                $messages = array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $rawErrors
                );
                $messages = array_values(array_filter($messages, static fn (string $value): bool => $value !== ''));
                $twoFactorUpdateError = $messages !== []
                    ? implode(' ', $messages)
                    : 'User saved, but 2FA methods could not be updated.';
            }
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        if ($avatarSet && is_string($currentAvatarPath) && $currentAvatarPath !== '' && $currentAvatarPath !== $avatarFilename) {
            $this->deleteAvatarFile($currentAvatarPath);
        }

        if ($twoFactorUpdateError !== null) {
            $this->flash('error', $twoFactorUpdateError);
            redirect($this->panelEditorUrlWithTab('/users/edit', $savedId, $activeTab, 'account'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/users/edit', $savedId, $activeTab, 'account'));
    }

    /**
     * Deletes one user.
     *
     * @param array<string, mixed> $post
     */
    public function usersDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $currentUserId = $this->auth->userId();

        if ($id !== null) {
            // Prevent deleting the currently authenticated account from this UI.
            if ($currentUserId === $id) {
                $this->flash('error', 'You cannot delete your currently logged-in account.');
                redirect($this->panelUrl('/users'));
            }

            try {
                $this->users->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete user.');
                redirect($this->panelUrl('/users'));
            }

            $this->flash('success', 'User deleted.');
            redirect($this->panelUrl('/users'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No users selected.');
            redirect($this->panelUrl('/users'));
        }

        $deletedCount = 0;
        $failedCount = 0;
        $skippedCurrentCount = 0;

        foreach ($selectedIds as $selectedId) {
            // Never allow self-delete in bulk mode either.
            if ($currentUserId !== null && $selectedId === $currentUserId) {
                $skippedCurrentCount++;
                continue;
            }

            try {
                // Continue processing remaining selections on individual failures.
                $this->users->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' user' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($skippedCurrentCount > 0) {
                $message .= ' Skipped your currently logged-in account.';
            }
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected user' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            if ($skippedCurrentCount > 0 && $failedCount === 0) {
                $this->flash('error', 'No users deleted because your currently logged-in account cannot be deleted.');
            } else {
                $this->flash('error', 'Failed to delete selected users.');
            }
        }

        redirect($this->panelUrl('/users'));
    }

    /**
     * Lists registration invite tokens for user onboarding.
     */
    public function userInvites(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'view')) {
            return;
        }

        $this->view->render('panel/users/invites', [
            'site' => $this->siteData(),
            'inviteRows' => $this->inviteTokens->listForPanel(),
            'inviteGeneratedTokens' => $this->pullFlashList('generated_invites'),
            'inviteRegistrationMode' => $this->registrationMode(),
            'inviteNowTs' => time(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'users',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Creates one invite token from panel form input.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesCreate(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users/invites'));
        }

        $inviteType = strtolower(trim((string) $this->input->text($post['invite_type'] ?? 'single', 20)));
        $isReusable = $inviteType === 'reusable';

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/users/invites'));
        }

        try {
            $token = $this->inviteTokens->createToken($isReusable, $expiresAt, $this->auth->userId());
        } catch (\Throwable $exception) {
            $this->flash('error', 'Failed to create invite token: ' . ($exception->getMessage() ?: 'Unknown error.'));
            redirect($this->panelUrl('/users/invites'));
        }

        $this->flash('success', $isReusable ? 'Reusable invite token created.' : 'Single-use invite token created.');
        $this->flashList('generated_invites', [$token]);
        redirect($this->panelUrl('/users/invites'));
    }

    /**
     * Generates a batch of single-use invite tokens from panel form input.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesGenerate(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users/invites'));
        }

        $count = $this->input->int($post['count'] ?? null, 1, 100) ?? 10;

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/users/invites'));
        }

        try {
            $tokens = $this->inviteTokens->createSingleUseBatch($count, $expiresAt, $this->auth->userId());
        } catch (\Throwable $exception) {
            $this->flash('error', 'Failed to generate invite tokens: ' . ($exception->getMessage() ?: 'Unknown error.'));
            redirect($this->panelUrl('/users/invites'));
        }

        $this->flash('success', 'Generated ' . count($tokens) . ' single-use invite token' . (count($tokens) === 1 ? '' : 's') . '.');
        $this->flashList('generated_invites', $tokens);
        redirect($this->panelUrl('/users/invites'));
    }

    /**
     * Deletes one invite token.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('users', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users/invites'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id === null) {
            $this->flash('error', 'Invite token id is required.');
            redirect($this->panelUrl('/users/invites'));
        }

        if (!$this->inviteTokens->deleteById($id)) {
            $this->flash('error', 'Invite token was not found.');
            redirect($this->panelUrl('/users/invites'));
        }

        $this->flash('success', 'Invite token deleted.');
        redirect($this->panelUrl('/users/invites'));
    }

    /**
     * Lists groups for Usergroup management section.
     */
    public function groupsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('groups', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->groups->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $groups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->groups->listPageForPanel($perPage, $pagination['offset']);
            $groups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/groups/list', [
            'site' => $this->siteData(),
            'groups' => $groups,
            'pagination' => $this->panelPaginationViewData('/groups', $pagination),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'groups',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows usergroup create/edit form.
     */
    public function groupsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('groups', $requiredAction)) {
            return;
        }

        $group = null;
        if ($id !== null) {
            $group = $this->groups->findById($id);

            if ($group === null) {
                $this->flash('error', 'Group not found.');
                redirect($this->panelUrl('/groups'));
            }
        }

        $this->view->render('panel/groups/edit', [
            'site' => $this->siteData(),
            'group' => $group,
            'groupRoutePrefix' => $this->groupRoutePrefix(),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'permissionDefinitions' => $this->permissionDefinitions(),
            'canEditConfigurationBit' => $this->auth->isSuperAdmin(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'groups',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one usergroup.
     *
     * @param array<string, mixed> $post
     */
    public function groupsSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('groups', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/groups'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'permissions'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 100);
        $editUrl = $this->panelEditorUrlWithTab('/groups/edit', $id, $activeTab, 'basic');
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();
        $existingGroup = $id !== null ? $this->groups->findById($id) : null;
        $isExistingStockGroup = is_array($existingGroup) && (int) ($existingGroup['is_stock'] ?? 0) === 1;
        $slugRaw = trim($this->input->text($post['slug'] ?? null, 160));
        $slug = '';
        if (!$isExistingStockGroup && $slugRaw !== '') {
            $slug = $this->input->slug($slugRaw) ?? '';
            if ($slug === '') {
                $this->flash('error', 'Group slug must be a valid slug.');
                redirect($editUrl);
            }
        }

        $groupRoutingEnabledSystemWide = $this->groupRoutesEnabledForRoutingTable();
        $routeEnabled = $groupRoutingEnabledSystemWide
            && isset($post['route_enabled'])
            && (string) $post['route_enabled'] === '1';
        $roleSlug = $isExistingStockGroup
            ? strtolower(trim((string) ($existingGroup['slug'] ?? '')))
            : '';
        $isGuestLikeGroup = $roleSlug === 'guest' || $roleSlug === 'validating';
        $isBannedGroup = $roleSlug === 'banned';
        $isUserGroup = $roleSlug === 'user';
        $isEditorGroup = $roleSlug === 'editor';
        $isAdminGroup = $roleSlug === 'admin';
        $isSuperAdminGroup = $roleSlug === 'super';
        if ($isGuestLikeGroup || $isBannedGroup) {
            $routeEnabled = false;
        }

        /** @var mixed $permissionBitsRaw */
        $permissionBitsRaw = $post['permission_bits'] ?? [];
        $permissionMask = 0;
        $validBits = array_column($this->permissionDefinitions(), 'bit');
        $allValidBitsMask = 0;
        foreach ($validBits as $validBit) {
            $allValidBitsMask |= (int) $validBit;
        }
        $editorStockMask = PanelAccess::PANEL_LOGIN
            | PanelAccess::VIEW_PUBLIC_SITE
            | PanelAccess::VIEW_PRIVATE_SITE
            | PanelAccess::maskFromBits(PanelAccess::contentPanelBits());
        $adminStockMask = PanelAccess::PANEL_LOGIN
            | PanelAccess::VIEW_PUBLIC_SITE
            | PanelAccess::VIEW_PRIVATE_SITE
            | PanelAccess::VIEW_DISABLED_SITE
            | PanelAccess::maskFromBits(array_merge(
                PanelAccess::contentPanelBits(),
                PanelAccess::taxonomyPanelBits(),
                PanelAccess::usersPanelBits()
            ));

        if (is_array($permissionBitsRaw)) {
            foreach ($permissionBitsRaw as $rawBit) {
                $bit = $this->input->int($rawBit, 1);
                if ($bit !== null && in_array($bit, $validBits, true)) {
                    $permissionMask |= $bit;
                }
            }
        }
        if ($isBannedGroup) {
            $permissionMask = 0;
        } elseif ($isGuestLikeGroup) {
            $permissionMask &= PanelAccess::VIEW_PUBLIC_SITE;
        } elseif ($isUserGroup) {
            $permissionMask &= (PanelAccess::VIEW_PUBLIC_SITE | PanelAccess::VIEW_PRIVATE_SITE);
        } elseif ($isEditorGroup) {
            $permissionMask = $editorStockMask;
        } elseif ($isAdminGroup) {
            $permissionMask = $adminStockMask;
        } elseif ($isSuperAdminGroup) {
            $permissionMask = $allValidBitsMask;
        }

        $systemBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
        $requestedSystemBits = $permissionMask & $systemBitsMask;
        $existingSystemBits = is_array($existingGroup)
            ? (((int) ($existingGroup['permission_mask'] ?? 0)) & $systemBitsMask)
            : 0;
        if (!$actorIsSuperAdmin && $requestedSystemBits !== $existingSystemBits) {
            $this->flash('error', 'Only Super Admin users can change system administration permissions.');
            redirect($editUrl);
        }
        if (!$actorIsSuperAdmin) {
            $permissionMask = ($permissionMask & (~$systemBitsMask)) | $existingSystemBits;
        }
        if (($permissionMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
            $permissionMask &= ~PanelAccess::allStockPanelBitsMask();
            $permissionMask &= ~$this->extensionPermissionBitsMask();
            $permissionMask &= ~PanelAccess::VIEW_DISABLED_SITE;
        }

        if ($id === null && $name === '') {
            $this->flash('error', 'Group name is required.');
            redirect($editUrl);
        }

        try {
            $savedId = $this->groups->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'route_enabled' => $routeEnabled ? 1 : 0,
                'permission_mask' => $permissionMask,
            ]);
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() ?: 'Failed to save group.');
            redirect($editUrl);
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/groups/edit', $savedId, $activeTab, 'basic'));
    }

    /**
     * Deletes one non-stock group.
     *
     * @param array<string, mixed> $post
     */
    public function groupsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('groups', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/groups'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->groups->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete group.');
                redirect($this->panelUrl('/groups'));
            }

            $this->flash('success', 'Group deleted.');
            redirect($this->panelUrl('/groups'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No groups selected.');
            redirect($this->panelUrl('/groups'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Repository enforces stock-group protections per selected id.
                $this->groups->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' group' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected group' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected groups.');
        }

        redirect($this->panelUrl('/groups'));
    }

    /**
     * Shows the User Preferences form for the currently logged-in user.
     */
    public function preferences(): void
    {
        $this->requirePanelLogin();

        $userId = $this->auth->userId();
        if ($userId === null) {
            redirect($this->panelUrl('/login'));
        }

        $preferences = $this->auth->userPreferences($userId);
        if ($preferences === null) {
            $this->flash('error', 'Unable to load your preferences.');
            redirect($this->panelUrl('/'));
        }
        $normalizedTheme = $this->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true);
        $preferences['theme'] = $normalizedTheme ?? 'default';
        $preferences['two_factor_methods'] = $this->prepareTwoFactorMethodsForView(
            is_array($preferences['two_factor_methods'] ?? null) ? $preferences['two_factor_methods'] : [],
            (string) ($preferences['email'] ?? '')
        );

        $this->view->render('panel/preferences', [
            'site' => $this->siteData(),
            'section' => 'preferences',
            'showSidebar' => true,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'preferences' => $preferences,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves User Preferences for the currently logged-in user.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function preferencesSave(array $post, array $files): void
    {
        $this->requirePanelLogin();
        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['account', 'profile', 'security'], 'account');
        $preferencesUrl = $this->panelEditorUrlWithTab('/preferences', null, $activeTab, 'account');

        $userId = $this->auth->userId();
        if ($userId === null) {
            redirect($this->panelUrl('/login'));
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($preferencesUrl);
        }

        $current = $this->auth->userPreferences($userId);
        if ($current === null) {
            $this->flash('error', 'Unable to load your current profile data.');
            redirect($preferencesUrl);
        }

        $loginIdentifierMode = $this->panelLoginIdentifierMode();
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeUserIdentifierValue($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->normalizePanelThemeChoice((string) $themeRaw, true);
        $newPassword = $this->input->text($post['new_password'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $twoFactorMethods = $this->normalizeSubmittedTwoFactorMethods(
            $post['two_factor_methods'] ?? null,
            (string) ($current['email'] ?? '')
        );
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $errors = [];
        $usernameRequired = $loginIdentifierMode === 'username';

        // Collect all validation errors first so users can fix in one pass.
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

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;

        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        if ($hasUpload) {
            // Validate bytes, dimensions, mime, and size before moving to public path.
            $avatarMaxSizeBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
            $avatarMaxWidth = (int) $this->config->get('media.avatars.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('media.avatars.max_height', 500);
            $avatarAllowedExtensions = $this->resolveAvatarAllowedExtensionsCsv();

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
                $normalizedExtension = $this->normalizeAvatarExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Avatar upload format is not supported.';
                } else {
                    $avatarsDir = $this->avatarStorageDirectory();
                    $avatarFilename = $this->avatarFilenameForUserId($userId, $normalizedExtension);
                    $destination = $avatarsDir . '/' . $avatarFilename;

                    $storeError = $this->storeSanitizedAvatarUpload($avatarUpload, $destination);
                    if ($storeError !== null) {
                        $errors[] = $storeError;
                    } else {
                        $avatarSet = true;
                        $uploadedAvatarFilename = $avatarFilename;
                    }
                }
            }
        }

        if ($errors !== []) {
            // Remove newly written avatar when validation/update fails later.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            $this->flash('error', implode(' ', $errors));
            redirect($preferencesUrl);
        }

        $update = $this->auth->updateUserPreferences($userId, [
            'username' => is_string($username) ? $username : '',
            'display_name' => $displayName,
            'email' => (string) $email,
            'theme' => $theme,
            'password' => $newPassword !== '' ? $newPassword : null,
            'contact_profiles' => $contactProfiles,
            'two_factor_methods' => $twoFactorMethods,
            'set_avatar' => $avatarSet,
            'avatar_path' => $avatarFilename,
        ]);

        if (!$update['ok']) {
            // Roll back newly uploaded avatar file when profile update fails.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            $this->flash('error', implode(' ', $update['errors']));
            redirect($preferencesUrl);
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        $oldAvatar = $current['avatar_path'] ?? null;
        if (is_string($oldAvatar) && $oldAvatar !== '' && $oldAvatar !== $avatarFilename && $avatarSet) {
            $this->deleteAvatarFile($oldAvatar);
        }

        $this->flash('success', 'User preferences updated.');
        redirect($preferencesUrl);
    }

    /**
     * Returns TOTP setup details (secret + URI + QR) for preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesTotpSetup(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $secret = TotpService::normalizeSecret((string) ($post['secret'] ?? ''));
        if (!TotpService::isValidSecret($secret)) {
            $generatedSecret = TotpService::generateSecret($this->totpIssuer());
            $secret = is_string($generatedSecret) ? $generatedSecret : '';
        }

        if (!TotpService::isValidSecret($secret)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to generate a TOTP secret.'], 500);
            return;
        }

        $accountEmail = (string) ($preferences['email'] ?? '');
        $provisioningUri = TotpService::provisioningUri($this->totpIssuer(), $accountEmail, $secret);
        if ($provisioningUri === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to build TOTP provisioning data.'], 500);
            return;
        }

        $accountAddress = $this->input->email($accountEmail);
        if ($accountAddress === null) {
            $accountAddress = 'account@local';
        }

        $this->jsonResponse([
            'ok' => true,
            'secret' => $secret,
            'issuer' => $this->totpIssuer(),
            'account' => $accountAddress,
            'provisioning_uri' => $provisioningUri,
            'qr_data_uri' => QrCodeService::dataUriSvgBase64($provisioningUri, 220),
        ], 200);
    }

    /**
     * Returns one generated 12-word recovery phrase for preferences 2FA flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesRecoveryCodeGenerate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $recoveryCode = RecoveryPhrase::generate(12);
        if (!is_string($recoveryCode) || !RecoveryPhrase::isValid($recoveryCode, 12)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to generate a recovery phrase.'], 500);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'recovery_code' => $recoveryCode,
        ], 200);
    }

    /**
     * Returns WebAuthn registration options for current user preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesWebauthnCreateOptions(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $excludeCredentialIds = [];
        $seenCredentialIds = [];

        $appendCredentialId = static function (string $credentialIdB64) use (&$excludeCredentialIds, &$seenCredentialIds): void {
            $credentialIdB64 = trim($credentialIdB64);
            if ($credentialIdB64 === '') {
                return;
            }

            if (isset($seenCredentialIds[$credentialIdB64])) {
                return;
            }

            $credentialBinary = base64_decode($credentialIdB64, true);
            if (!is_string($credentialBinary) || $credentialBinary === '') {
                return;
            }

            $seenCredentialIds[$credentialIdB64] = true;
            $excludeCredentialIds[] = $credentialBinary;
        };

        foreach ((array) ($preferences['two_factor_methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn') {
                continue;
            }

            $appendCredentialId((string) ($method['credential_id'] ?? ''));
        }

        $submittedExcludeIds = $post['exclude_credential_ids'] ?? null;
        if (is_array($submittedExcludeIds)) {
            foreach ($submittedExcludeIds as $credentialIdCandidate) {
                if (!is_scalar($credentialIdCandidate)) {
                    continue;
                }

                $appendCredentialId((string) $credentialIdCandidate);
                if (count($excludeCredentialIds) >= 20) {
                    break;
                }
            }
        }

        $requireUserVerification = isset($post['require_user_verification'])
            && (string) ($post['require_user_verification'] ?? '') === '1';

        $webAuthn = $this->createWebAuthnServer();
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        $username = trim((string) ($preferences['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($preferences['email'] ?? ''));
        }
        if ($username === '') {
            $username = 'user-' . $userId;
        }

        $displayName = trim((string) ($preferences['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $username;
        }

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
     * Verifies WebAuthn registration response in current user preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesWebauthnRegister(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
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
     * Displays placeholder for panel sections not yet fully implemented.
     */
    public function sectionStub(string $section): void
    {
        $this->requirePanelLogin();

        $this->view->render('panel/dashboard', [
            'site' => $this->siteData(),
            'user' => $this->auth->userSummary(),
            'canManageUsers' => $this->auth->canManageUsers(),
            'canManageGroups' => $this->auth->canManageGroups(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => null,
            'flashError' => null,
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Configuration editor route (Manage System Configuration permission).
     */
    public function configuration(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('configuration', 'view')) {
            return;
        }

        $configSnapshot = $this->config->all();
        $configSnapshot = $this->removeSqliteDatabaseFilesConfig($configSnapshot);
        $configSnapshot = $this->ensureContentEditorConfig($configSnapshot);
        $configSnapshot = $this->ensureTaxonomyRoutePrefixConfig($configSnapshot);
        $configSnapshot = $this->ensurePublicProfileConfig($configSnapshot);
        $configSnapshot = $this->ensureUserAuthConfig($configSnapshot);
        $configSnapshot = $this->ensureSiteEnabledConfig($configSnapshot);
        $configSnapshot = $this->ensurePanelBrandingConfig($configSnapshot);
        $configSnapshot = $this->ensureCaptchaConfig($configSnapshot);
        $configSnapshot = $this->ensureMailConfig($configSnapshot);
        $configSnapshot = $this->ensureDebugToolbarConfig($configSnapshot);
        $activeConfigTab = $this->normalizeConfigEditorTab($_GET['tab'] ?? 'basic');

        $this->view->render('panel/configuration', [
            'site' => $this->siteData(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'configuration',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'configSnapshot' => $configSnapshot,
            'configFields' => $this->flattenConfigFields($configSnapshot),
            'activeConfigTab' => $activeConfigTab,
        ], 'panel/wrapper');
    }

    /**
     * Saves configuration values from per-key text inputs.
     */
    public function configurationSave(array $post): void
    {
        $this->requirePanelLogin();
        $activeConfigTab = $this->normalizeConfigEditorTab($post['_config_tab'] ?? 'basic');

        if (!$this->requireRoutePermissionOrForbidden('configuration', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        /** @var mixed $rawConfigValues */
        $rawConfigValues = $post['config_values'] ?? [];
        if (!is_array($rawConfigValues)) {
            $this->flash('error', 'Invalid configuration payload.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $currentConfig = $this->config->all();
        $currentConfig = $this->removeSqliteDatabaseFilesConfig($currentConfig);
        $currentConfig = $this->ensureContentEditorConfig($currentConfig);
        $currentConfig = $this->ensureTaxonomyRoutePrefixConfig($currentConfig);
        $currentConfig = $this->ensurePublicProfileConfig($currentConfig);
        $currentConfig = $this->ensureUserAuthConfig($currentConfig);
        $currentConfig = $this->ensureSiteEnabledConfig($currentConfig);
        $currentConfig = $this->ensurePanelBrandingConfig($currentConfig);
        $currentConfig = $this->ensureCaptchaConfig($currentConfig);
        $currentConfig = $this->ensureMailConfig($currentConfig);
        $currentConfig = $this->ensureDebugToolbarConfig($currentConfig);
        $fields = $this->flattenConfigFields($currentConfig);
        $nextConfig = $currentConfig;

        try {
            foreach ($fields as $field) {
                /** @var array<int, string> $segments */
                $segments = $field['segments'];
                $path = (string) $field['path'];
                if (str_starts_with($path, 'user.contact.')) {
                    continue;
                }
                $type = (string) $field['type'];
                $rawValue = $this->readNestedConfigValue($rawConfigValues, $segments);
                $normalized = $this->normalizeConfigFieldValue($path, $type, $rawValue, $nextConfig);
                $this->setNestedConfigValue($nextConfig, $segments, $normalized);
            }
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        // Validate required keys explicitly before save.
        $domain = $this->input->text((string) ($nextConfig['site']['domain'] ?? ''), 200);
        $panelPath = $this->input->slug((string) ($nextConfig['panel']['path'] ?? ''));

        if ($domain === '' || $panelPath === null) {
            $this->flash('error', 'site.domain and panel.path are required.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $nextConfig['site']['domain'] = $domain;
        $nextConfig['panel']['path'] = $panelPath;
        $nextConfig['user'] = is_array($nextConfig['user'] ?? null) ? $nextConfig['user'] : [];
        $nextConfig['user']['contact'] = $this->normalizeSubmittedProfileContactOptionsConfig(
            $post['profile_contact_options'] ?? null
        );

        // Keep taxonomy listing prefixes explicit/configured for public category/tag routes.
        $nextConfig = $this->ensureContentEditorConfig($nextConfig);
        $nextConfig = $this->ensureTaxonomyRoutePrefixConfig($nextConfig);
        $nextConfig = $this->ensurePublicProfileConfig($nextConfig);
        $nextConfig = $this->ensureUserAuthConfig($nextConfig);
        $nextConfig = $this->ensureSiteEnabledConfig($nextConfig);
        $nextConfig = $this->ensurePanelBrandingConfig($nextConfig);
        $nextConfig = $this->ensureCaptchaConfig($nextConfig);
        $nextConfig = $this->ensureMailConfig($nextConfig);
        $nextConfig = $this->ensureDebugToolbarConfig($nextConfig);
        $nextConfig = $this->removeSqliteDatabaseFilesConfig($nextConfig);

        // Replace-and-save keeps on-disk config as the single source of truth.
        $this->config->replace($nextConfig);
        $this->config->save();

        $this->flash('success', 'Configuration saved.');
        redirect($this->configurationUrlForTab($activeConfigTab));
    }

    /**
     * Routing inventory page (Manage Taxonomy permission).
     */
    public function routing(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $routeRows = $this->routingRowsForPanel();
        $summary = [
            'total' => count($routeRows),
            'pages' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'page')),
            'channels' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'channel')),
            'redirects' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'redirect')),
            'conflicts' => count(array_filter($routeRows, static fn (array $row): bool => !empty($row['is_conflict']))),
        ];

        $this->view->render('panel/routing', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'routing',
            'pageTitle' => 'Routing Table',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'routeRows' => $routeRows,
            'routeSummary' => $summary,
        ], 'panel/wrapper');
    }

    /**
     * Exports routing inventory rows as CSV (Manage Taxonomy permission).
     */
    public function routingExport(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $rows = $this->routingRowsForPanel();
        $filename = 'routing-inventory-' . gmdate('Ymd-His') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['Type', 'Title', 'Public URL', 'Target URL', 'Status', 'Notes', 'Conflict']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['type_label'] ?? ''),
                (string) ($row['source_label'] ?? ''),
                (string) ($row['public_url'] ?? ''),
                (string) ($row['target_url'] ?? ''),
                (string) ($row['status_label'] ?? ''),
                (string) ($row['notes'] ?? ''),
                !empty($row['is_conflict']) ? 'Yes' : 'No',
            ]);
        }

        fclose($stream);
    }

    /**
     * Public Theme manager route (Manage System Configuration permission).
     */
    public function themes(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        $themes = $this->listPublicThemesForPanel();

        $this->view->render('panel/themes', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'themes',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'themes' => $themes,
            'activeTheme' => $this->activePublicThemeSlug(),
            'themeOptions' => PublicThemeRegistry::options($this->publicThemesRoot()),
        ], 'panel/wrapper');
    }

    /**
     * Persists active public theme selection.
     *
     * @param array<string, mixed> $post
     */
    public function themesEnable(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        $availableThemes = $this->publicThemeOptions();
        if (!isset($availableThemes[$themeSlug])) {
            $this->flash('error', 'Theme "' . $themeSlug . '" is not available.');
            redirect($this->panelUrl('/themes'));
        }

        try {
            $this->config->set('site.default_theme', $themeSlug);
            $this->config->save();
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Failed to update active theme: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        $this->flash('success', 'Active public theme set to "' . ($availableThemes[$themeSlug] ?? $themeSlug) . '".');
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Creates one theme scaffold under `public/theme/{slug}/`.
     *
     * @param array<string, mixed> $post
     */
    public function themesCreate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeName = trim($this->input->text($post['name'] ?? null, 120));
        if ($themeName === '') {
            $this->flash('error', 'Theme name is required.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? ($post['slug'] ?? null), 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/themes'));
        }

        $parentTheme = strtolower(trim($this->input->text($post['parent_theme'] ?? null, 80)));
        if ($parentTheme !== '' && !$this->isSafePublicThemeSlug($parentTheme)) {
            $this->flash('error', 'Parent theme slug is invalid.');
            redirect($this->panelUrl('/themes'));
        }

        $cloneTheme = strtolower(trim($this->input->text($post['clone_theme'] ?? null, 80)));
        if ($cloneTheme !== '' && !$this->isSafePublicThemeSlug($cloneTheme)) {
            $this->flash('error', 'Clone-source theme slug is invalid.');
            redirect($this->panelUrl('/themes'));
        }

        $themesRoot = $this->publicThemesRoot();
        $themeOptions = PublicThemeRegistry::options($themesRoot);
        $themeManifests = PublicThemeRegistry::manifests($themesRoot);
        if ($parentTheme !== '' && !isset($themeOptions[$parentTheme])) {
            $this->flash('error', 'Selected parent theme was not found.');
            redirect($this->panelUrl('/themes'));
        }
        if ($cloneTheme !== '' && !isset($themeOptions[$cloneTheme])) {
            $this->flash('error', 'Selected clone-source theme was not found.');
            redirect($this->panelUrl('/themes'));
        }

        if ($parentTheme === $themeSlug) {
            $this->flash('error', 'A child theme cannot use itself as parent.');
            redirect($this->panelUrl('/themes'));
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';
        $generatePackageFile = isset($post['generate_package']) && (string) $post['generate_package'] === '1';
        $setActive = isset($post['set_active']) && (string) $post['set_active'] === '1';
        $themePath = $themesRoot . '/' . $themeSlug;
        if (file_exists($themePath)) {
            $this->flash('error', 'A theme directory with this slug already exists.');
            redirect($this->panelUrl('/themes'));
        }

        $isChildTheme = $parentTheme !== '';
        $resolvedParentTheme = $parentTheme;
        if ($cloneTheme !== '' && !$isChildTheme) {
            $cloneManifest = $themeManifests[$cloneTheme] ?? null;
            if (is_array($cloneManifest) && !empty($cloneManifest['is_child_theme'])) {
                $cloneParent = strtolower(trim((string) ($cloneManifest['parent_theme'] ?? '')));
                if ($cloneParent !== '' && $cloneParent !== $themeSlug && isset($themeOptions[$cloneParent])) {
                    $isChildTheme = true;
                    $resolvedParentTheme = $cloneParent;
                }
            }
        }

        try {
            if ($cloneTheme !== '') {
                $clonePath = $themesRoot . '/' . $cloneTheme;
                $this->copyDirectoryRecursively($clonePath, $themePath);
                $this->writePublicThemeManifest(
                    $themePath . '/theme.json',
                    [
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ]
                );
                if ($generateAgentsFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/AGENTS.md',
                        $this->publicThemeAgentsFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                                'is_child_theme' => $isChildTheme,
                                'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                            ]
                        )
                    );
                }
                if ($generateComposerFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/composer.json',
                        $this->publicThemeComposerFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                            ]
                        )
                    );
                }
                if ($generatePackageFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/package.json',
                        $this->publicThemePackageFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                            ]
                        )
                    );
                }
            } else {
                $this->createPublicThemeSkeleton(
                    $themePath,
                    [
                        'slug' => $themeSlug,
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ],
                    $generateAgentsFile,
                    $generateComposerFile,
                    $generatePackageFile
                );
            }
        } catch (\RuntimeException $exception) {
            $this->removeDirectoryRecursively($themePath);
            $this->flash('error', 'Failed to create theme scaffold: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        if ($setActive) {
            try {
                $this->config->set('site.default_theme', $themeSlug);
                $this->config->save();
            } catch (\RuntimeException $exception) {
                $this->removeDirectoryRecursively($themePath);
                $this->flash('error', 'Theme scaffold created, but activation failed: ' . $exception->getMessage());
                redirect($this->panelUrl('/themes'));
            }
        }

        $message = 'Theme scaffold created at public/theme/' . $themeSlug . '/';
        if ($cloneTheme !== '') {
            $message .= ' (cloned from "' . $cloneTheme . '")';
        }
        if ($setActive) {
            $message .= ' and activated.';
        } else {
            $message .= '.';
        }
        if ($generateAgentsFile || $generateComposerFile || $generatePackageFile) {
            $generated = [];
            if ($generateAgentsFile) {
                $generated[] = 'AGENTS.md';
            }
            if ($generateComposerFile) {
                $generated[] = 'composer.json';
            }
            if ($generatePackageFile) {
                $generated[] = 'package.json';
            }
            $message .= ' Generated: ' . implode(', ', $generated) . '.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Uploads one zipped public-theme package into `public/theme/{slug}/`.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function themesUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Theme upload requires the PHP zip extension.');
            redirect($this->panelUrl('/themes'));
        }

        /** @var mixed $rawUpload */
        $rawUpload = $files['theme_archive'] ?? null;
        if (!is_array($rawUpload)) {
            $this->flash('error', 'No theme archive payload was received.');
            redirect($this->panelUrl('/themes'));
        }

        $uploadError = (int) ($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->flash('error', $this->themeUploadErrorMessage($uploadError));
            redirect($this->panelUrl('/themes'));
        }

        $tmpPath = (string) ($rawUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            $this->flash('error', 'Uploaded archive could not be validated as an HTTP upload.');
            redirect($this->panelUrl('/themes'));
        }

        $archiveName = $this->input->text((string) ($rawUpload['name'] ?? 'theme.zip'), 255);
        if (strtolower((string) pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash('error', 'Themes must be uploaded as .zip archives.');
            redirect($this->panelUrl('/themes'));
        }

        // Keep archive uploads bounded to avoid accidental oversized package uploads.
        $maxArchiveBytes = 50 * 1024 * 1024;
        $archiveSize = (int) ($rawUpload['size'] ?? 0);
        if ($archiveSize < 1 || $archiveSize > $maxArchiveBytes) {
            $this->flash('error', 'Theme archive exceeds the 50MB upload limit.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['upload_slug'] ?? ($post['theme'] ?? null), 80)));
        $manualSlugProvided = $themeSlug !== '';
        if ($themeSlug === '') {
            $derivedSlug = $this->themeSlugFromArchiveFilename($archiveName);
            if (!is_string($derivedSlug)) {
                $this->flash('error', 'Could not derive a valid theme directory slug from archive filename.');
                redirect($this->panelUrl('/themes'));
            }
            $themeSlug = $derivedSlug;
        }

        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/themes'));
        }

        if ($manualSlugProvided && $this->isStockPublicThemeSlug($themeSlug)) {
            $this->flash('error', 'That theme slug is reserved by a stock theme.');
            redirect($this->panelUrl('/themes'));
        }

        $themesRoot = $this->publicThemesRoot();
        if (!is_dir($themesRoot) && !mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            $this->flash('error', 'Failed to initialize public/theme directory.');
            redirect($this->panelUrl('/themes'));
        }

        $initialThemeSlug = $themeSlug;
        $targetDirectory = $themesRoot . '/' . $themeSlug;
        if (file_exists($targetDirectory)) {
            if ($manualSlugProvided) {
                $this->flash('error', 'A theme directory with this slug already exists.');
                redirect($this->panelUrl('/themes'));
            }

            $resolvedThemeSlug = $this->nextAvailablePublicThemeSlug($themeSlug);
            if ($resolvedThemeSlug === null) {
                $this->flash('error', 'Failed to resolve an available theme slug for this upload.');
                redirect($this->panelUrl('/themes'));
            }

            $themeSlug = $resolvedThemeSlug;
            $targetDirectory = $themesRoot . '/' . $themeSlug;
        }

        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->flash('error', 'Failed to create theme directory.');
            redirect($this->panelUrl('/themes'));
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmpPath);
        if ($opened !== true) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Failed to read uploaded ZIP archive.');
            redirect($this->panelUrl('/themes'));
        }

        try {
            if ($zip->numFiles < 1) {
                throw new \RuntimeException('Theme archive is empty.');
            }

            // Validate all entry paths before extraction to block zip-slip paths.
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (!is_string($entryName) || !$this->isSafeZipEntryPath($entryName)) {
                    throw new \RuntimeException('Archive contains unsafe file paths.');
                }
            }

            if (!$zip->extractTo($targetDirectory)) {
                throw new \RuntimeException('Failed to extract theme archive.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Theme upload failed.');
            redirect($this->panelUrl('/themes'));
        }

        $zip->close();

        if (!$this->directoryHasFiles($targetDirectory)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extracted theme directory is empty.');
            redirect($this->panelUrl('/themes'));
        }

        $manifestPath = $targetDirectory . '/theme.json';
        if (!is_file($manifestPath)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Theme upload failed: archive must include theme.json at archive root.');
            redirect($this->panelUrl('/themes'));
        }

        $manifests = PublicThemeRegistry::manifests($themesRoot);
        if (!isset($manifests[$themeSlug])) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Theme upload failed: theme.json is missing required/valid metadata.');
            redirect($this->panelUrl('/themes'));
        }

        $message = 'Theme uploaded to public/theme/' . $themeSlug . '/. Enable it from the Installed Themes list when ready.';
        if (!$manualSlugProvided && $themeSlug !== $initialThemeSlug) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Exports one installed public theme directory as a ZIP download.
     *
     * @param array<string, mixed> $query
     */
    public function themesExport(array $query): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Theme export requires the PHP zip extension.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($query['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->flash('error', 'Theme directory was not found on disk.');
            redirect($this->panelUrl('/themes'));
        }

        try {
            $archivePath = $this->buildZipArchiveFromDirectory($themePath, $themeSlug);
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Theme export failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        $downloadFilename = 'theme-' . $themeSlug . '-' . gmdate('Ymd-His') . '.zip';
        $this->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
    }

    /**
     * Deletes one non-active, non-stock public theme.
     *
     * @param array<string, mixed> $post
     */
    public function themesDelete(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        if ($this->isStockPublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Stock themes cannot be deleted.');
            redirect($this->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->flash('error', 'Theme directory was not found on disk.');
            redirect($this->panelUrl('/themes'));
        }

        if ($this->activePublicThemeSlug() === $themeSlug) {
            $this->flash('error', 'Active theme cannot be deleted. Enable another theme first.');
            redirect($this->panelUrl('/themes'));
        }

        $this->removeDirectoryRecursively($themePath);
        if (is_dir($themePath)) {
            $this->flash('error', 'Failed to delete theme directory from disk.');
            redirect($this->panelUrl('/themes'));
        }

        $this->flash('success', 'Theme "' . $themeSlug . '" deleted.');
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Extensions management route (Manage System Configuration permission).
     */
    public function extensions(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        try {
            $extensions = $this->listExtensionsForPanel();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            $extensions = [];
        }

        $this->view->render('panel/extensions', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'extensions',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'extensions' => $extensions,
        ], 'panel/wrapper');
    }

    /**
     * Toggles one extension enabled/disabled state.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsToggle(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Refuse activation for invalid extension packages (missing/invalid manifest).
        $manifest = $this->readExtensionManifest($extensionPath);
        if (!($manifest['valid'] ?? false)) {
            // Also strip any stale enabled state so invalid packages cannot stay active.
            $enabledMap = $this->loadExtensionStateMap();
            if (isset($enabledMap[$extensionName])) {
                unset($enabledMap[$extensionName]);
                $this->saveExtensionStateMap($enabledMap);
            }

            $reason = (string) ($manifest['invalid_reason'] ?? 'Invalid extension metadata.');
            $this->flash('error', 'Extension is invalid: ' . $reason);
            redirect($this->panelUrl('/extensions'));
        }

        $enabledRaw = strtolower($this->input->text($post['enabled'] ?? null, 10));
        if (!in_array($enabledRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            $this->flash('error', 'Invalid extension toggle value.');
            redirect($this->panelUrl('/extensions'));
        }

        $enable = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        try {
            $enabledMap = $this->loadExtensionStateMap();
            if ($enable) {
                $enabledMap[$extensionName] = true;
            } else {
                unset($enabledMap[$extensionName]);
            }

            $this->saveExtensionStateMap($enabledMap);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $this->flash('success', 'Extension "' . $extensionName . '" ' . ($enable ? 'enabled' : 'disabled') . '.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Persists the required panel-side permission bit for one non-system extension.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsPermission(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }
        $this->flash('error', 'Extension permission levels are managed in Groups > Permissions.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Deletes one extension directory from `private/ext/{name}/`.
     *
     * Rules:
     * - Stock extensions can never be deleted.
     * - Enabled extensions must be disabled before deletion.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsDelete(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        if ($this->isStockExtensionDirectory($extensionName)) {
            $this->flash('error', 'Stock extensions cannot be deleted.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Prevent deleting active extensions so runtime behavior changes are deliberate.
        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        if (!empty($enabledMap[$extensionName])) {
            $this->flash('error', 'Disable the extension before deleting it.');
            redirect($this->panelUrl('/extensions'));
        }

        $this->removeDirectoryRecursively($extensionPath);
        if (is_dir($extensionPath)) {
            $this->flash('error', 'Failed to delete extension directory from disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Also clear stale state keys if present (defensive cleanup).
        if (
            isset($enabledMap[$extensionName])
            || isset($permissionMap[$extensionName])
            || isset($permissionBitsMap[$extensionName])
        ) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
            try {
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            } catch (\RuntimeException $exception) {
                $this->flash('error', 'Extension deleted, but state cleanup failed: ' . $exception->getMessage());
                redirect($this->panelUrl('/extensions'));
            }
        }

        $this->flash('success', 'Extension "' . $extensionName . '" deleted.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Uploads one zipped extension package into `private/ext/{name}/`.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function extensionsUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Extension upload requires the PHP zip extension.');
            redirect($this->panelUrl('/extensions'));
        }

        /** @var mixed $rawUpload */
        $rawUpload = $files['extension_archive'] ?? null;
        if (!is_array($rawUpload)) {
            $this->flash('error', 'No extension archive payload was received.');
            redirect($this->panelUrl('/extensions'));
        }

        $uploadError = (int) ($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->flash('error', $this->extensionUploadErrorMessage($uploadError));
            redirect($this->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($rawUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            $this->flash('error', 'Uploaded archive could not be validated as an HTTP upload.');
            redirect($this->panelUrl('/extensions'));
        }

        $archiveName = $this->input->text((string) ($rawUpload['name'] ?? 'extension.zip'), 255);
        if (strtolower((string) pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash('error', 'Extensions must be uploaded as .zip archives.');
            redirect($this->panelUrl('/extensions'));
        }

        // Keep archive uploads bounded to avoid accidental oversized package uploads.
        $maxArchiveBytes = 50 * 1024 * 1024;
        $archiveSize = (int) ($rawUpload['size'] ?? 0);
        if ($archiveSize < 1 || $archiveSize > $maxArchiveBytes) {
            $this->flash('error', 'Extension archive exceeds the 50MB upload limit.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim($this->input->text($post['upload_slug'] ?? null, 120)));
        $manualSlugProvided = $extensionName !== '';
        if ($extensionName === '') {
            $derivedExtensionName = $this->extensionNameFromArchiveFilename($archiveName);
            if ($derivedExtensionName === null) {
                $this->flash('error', 'Could not derive a valid extension directory name from archive filename.');
                redirect($this->panelUrl('/extensions'));
            }
            $extensionName = $derivedExtensionName;
        }

        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/extensions'));
        }

        if ($manualSlugProvided && $this->isStockExtensionDirectory($extensionName)) {
            $this->flash('error', 'That extension directory name is reserved by a stock extension.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $initialExtensionName = $extensionName;
        $targetDirectory = $this->extensionsBasePath() . '/' . $extensionName;
        if (file_exists($targetDirectory)) {
            if ($manualSlugProvided) {
                $this->flash('error', 'An extension directory with this name already exists.');
                redirect($this->panelUrl('/extensions'));
            }

            $resolvedExtensionName = $this->nextAvailableExtensionDirectoryName($extensionName);
            if ($resolvedExtensionName === null) {
                $this->flash('error', 'Failed to resolve an available extension directory name for this upload.');
                redirect($this->panelUrl('/extensions'));
            }

            $extensionName = $resolvedExtensionName;
            $targetDirectory = $this->extensionsBasePath() . '/' . $extensionName;
        }

        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->flash('error', 'Failed to create extension directory.');
            redirect($this->panelUrl('/extensions'));
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmpPath);
        if ($opened !== true) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Failed to read uploaded ZIP archive.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            if ($zip->numFiles < 1) {
                throw new \RuntimeException('Extension archive is empty.');
            }

            // Validate all entry paths before extraction to block zip-slip paths.
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (!is_string($entryName) || !$this->isSafeZipEntryPath($entryName)) {
                    throw new \RuntimeException('Archive contains unsafe file paths.');
                }
            }

            if (!$zip->extractTo($targetDirectory)) {
                throw new \RuntimeException('Failed to extract extension archive.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Extension upload failed.');
            redirect($this->panelUrl('/extensions'));
        }

        $zip->close();

        if (!$this->directoryHasFiles($targetDirectory)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extracted extension directory is empty.');
            redirect($this->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($targetDirectory);
        if (!($manifest['valid'] ?? false)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->flash('error', 'Extension upload failed: ' . $reason);
            redirect($this->panelUrl('/extensions'));
        }

        // New uploads must always start disabled, even if stale state data exists.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            // Roll back extracted files when state finalization fails to avoid ambiguous activation state.
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $message = 'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.';
        if (!$manualSlugProvided && $extensionName !== $initialExtensionName) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Exports one installed extension directory as a ZIP download.
     *
     * @param array<string, mixed> $query
     */
    public function extensionsExport(array $query): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Extension export requires the PHP zip extension.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim($this->input->text($query['extension'] ?? null, 120)));
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $archivePath = $this->buildZipArchiveFromDirectory($extensionPath, $extensionName);
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Extension export failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $downloadFilename = 'extension-' . $extensionName . '-' . gmdate('Ymd-His') . '.zip';
        $this->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
    }

    /**
     * Creates one new extension scaffold in `private/ext/{name}/`.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsCreate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim($this->input->text($post['extension'] ?? null, 120)));
        if ($extensionName === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $extensionName) !== 1) {
            $this->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/extensions'));
        }

        if ($this->isStockExtensionDirectory($extensionName)) {
            $this->flash('error', 'That extension directory name is reserved by a stock extension.');
            redirect($this->panelUrl('/extensions'));
        }

        $displayName = $this->input->text($post['name'] ?? null, 120);
        if ($displayName === '') {
            $this->flash('error', 'Extension name is required.');
            redirect($this->panelUrl('/extensions'));
        }

        $type = strtolower(trim($this->input->text($post['type'] ?? null, 20)));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }

        $version = $this->input->text($post['version'] ?? null, 80);
        if ($version === '') {
            $version = '0.1.0';
        }

        $description = $this->input->text($post['description'] ?? null, 1000);
        $author = $this->input->text($post['author'] ?? null, 120);

        $docsUrlRaw = trim($this->input->text($post['docs_url'] ?? ($post['homepage'] ?? null), 400));
        $homepage = '';
        if ($docsUrlRaw !== '') {
            if (filter_var($docsUrlRaw, FILTER_VALIDATE_URL) === false) {
                $this->flash('error', 'Documentation URL must be a valid absolute URL.');
                redirect($this->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($docsUrlRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->flash('error', 'Documentation URL must use http or https.');
                redirect($this->panelUrl('/extensions'));
            }

            $homepage = $docsUrlRaw;
        }

        $authorUrlRaw = trim($this->input->text($post['author_url'] ?? null, 400));
        $authorUrl = '';
        if ($authorUrlRaw !== '') {
            if (filter_var($authorUrlRaw, FILTER_VALIDATE_URL) === false) {
                $this->flash('error', 'Author URL must be a valid absolute URL.');
                redirect($this->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($authorUrlRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->flash('error', 'Author URL must use http or https.');
                redirect($this->panelUrl('/extensions'));
            }

            $authorUrl = $authorUrlRaw;
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (file_exists($extensionPath)) {
            $this->flash('error', 'An extension directory with this name already exists.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $this->createExtensionSkeleton($extensionPath, [
                'directory' => $extensionName,
                'name' => $displayName,
                'version' => $version,
                'description' => $description,
                'type' => $type,
                'author' => $author,
                'homepage' => $homepage,
                'author_url' => $authorUrl,
            ], $generateAgentsFile, $generateComposerFile);
        } catch (\Throwable $exception) {
            // Roll back partial writes so failed scaffold attempts do not leave broken extensions.
            $this->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        // New scaffolds must start disabled, including stale keys from previous deletions.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $createdFiles = ['ext.json', 'ext.php', 'lib/schema.php'];
        if (in_array($type, ['helper', 'plugin', 'module'], true)) {
            $createdFiles[] = 'lib/shortcodes.php';
        }
        if (in_array($type, ['content', 'plugin', 'module'], true)) {
            $createdFiles[] = 'lib/fields.php';
        }
        $createdFiles = array_merge($createdFiles, ['lib/routes_panel.php', 'vis/panel_index.php']);
        if ($type === 'module') {
            $createdFiles[] = 'lib/routes_public.php';
            $createdFiles[] = 'vis/public_index.php';
        }
        if ($generateComposerFile) {
            $createdFiles[] = 'composer.json';
        }
        $createdList = $createdFiles[0] ?? 'ext.json';
        if (count($createdFiles) === 2) {
            $createdList = $createdFiles[0] . ' and ' . $createdFiles[1];
        } elseif (count($createdFiles) > 2) {
            $createdList = implode(', ', array_slice($createdFiles, 0, -1))
                . ', and '
                . $createdFiles[count($createdFiles) - 1];
        }

        $this->flash(
            'success',
            'Extension scaffold created at private/ext/' . $extensionName
            . '/ with ' . $createdList
            . ($generateAgentsFile ? ', plus AGENTS.md.' : '.')
        );
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Flattens config tree into scalar field descriptors for form rendering.
     *
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     * @return array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }>
     */
    private function flattenConfigFields(array $config, array $segments = []): array
    {
        $fields = [];

        foreach ($config as $key => $value) {
            $pathSegments = [...$segments, (string) $key];

            if (is_array($value)) {
                // Continue walking nested config sections until leaf scalar values.
                $fields = array_merge($fields, $this->flattenConfigFields($value, $pathSegments));
                continue;
            }

            $path = implode('.', $pathSegments);
            // SQLite DB filenames are core-managed and intentionally hidden
            // from the configuration editor to keep installs consistent.
            // Public default theme is managed by Theme Manager / rvn-theme only.
            if ($path === 'site.default_theme' || str_starts_with($path, 'database.sqlite.files.')) {
                continue;
            }
            $fields[] = [
                'path' => $path,
                'segments' => $pathSegments,
                'label' => $this->labelFromPath($path),
                'type' => $this->detectConfigScalarType($value),
                'value' => $this->stringifyConfigScalar($value),
            ];
        }

        return $fields;
    }

    /**
     * Builds a user-facing label from one dotted config path.
     */
    private function labelFromPath(string $path): string
    {
        if ($path === 'media.images.max_filesize_kb') {
            return 'Max Filesize (KB)';
        }

        if ($path === 'media.avatars.max_filesize_kb') {
            return 'Max Avatar Filesize (KB)';
        }

        if ($path === 'media.avatars.max_width') {
            return 'Max Avatar Width (px)';
        }

        if ($path === 'media.avatars.max_height') {
            return 'Max Avatar Height (px)';
        }

        if ($path === 'media.avatars.allowed_extensions') {
            return 'Allowed Avatar Extensions';
        }

        if ($path === 'media.images.small.width') {
            return 'Small Width (px)';
        }

        if ($path === 'media.images.small.height') {
            return 'Small Height (px)';
        }

        if ($path === 'media.images.med.width') {
            return 'Medium Width (px)';
        }

        if ($path === 'media.images.med.height') {
            return 'Medium Height (px)';
        }

        if ($path === 'media.images.large.width') {
            return 'Large Width (px)';
        }

        if ($path === 'media.images.large.height') {
            return 'Large Height (px)';
        }

        if ($path === 'captcha.hcaptcha.public_key') {
            return 'Site Key';
        }

        if ($path === 'captcha.recaptcha2.public_key') {
            return 'Site Key';
        }

        if ($path === 'captcha.recaptcha3.public_key') {
            return 'Site Key';
        }

        if ($path === 'panel.path') {
            return 'Panel Path';
        }

        if ($path === 'panel.default_theme') {
            return 'Default Panel Theme';
        }

        if ($path === 'panel.brand_name') {
            return 'Branded Panel Name';
        }

        if ($path === 'panel.brand_logo') {
            return 'Branded Panel Logo';
        }

        if ($path === 'site.default_theme') {
            return 'Default Site Theme';
        }

        if ($path === 'site.enabled') {
            return 'Site Visibility';
        }

        if ($path === 'mail.agent') {
            return 'Mail Agent';
        }

        if ($path === 'mail.sender_address') {
            return 'Mail Sender Address';
        }

        if ($path === 'mail.sender_name') {
            return 'Mail Sender Name';
        }

        if ($path === 'content.default_editor') {
            return 'Default Text Editor';
        }

        if ($path === 'content.separator') {
            return 'Default Page URL Separator';
        }

        if ($path === 'category.prefix') {
            return 'Category URL Prefix';
        }

        if ($path === 'category.pagination') {
            return 'Pagination';
        }

        if ($path === 'tag.prefix') {
            return 'Tag URL Prefix';
        }

        if ($path === 'tag.pagination') {
            return 'Pagination';
        }

        if ($path === 'meta.twitter.card') {
            return 'Twitter Card';
        }

        if ($path === 'meta.twitter.site') {
            return 'Twitter Site';
        }

        if ($path === 'meta.twitter.creator') {
            return 'Twitter Creator';
        }

        if ($path === 'meta.twitter.image') {
            return 'Twitter Image';
        }

        if ($path === 'meta.apple_touch_icon') {
            return 'Apple Touch Icon';
        }

        if ($path === 'meta.opengraph.type') {
            return 'OpenGraph Type';
        }

        if ($path === 'meta.opengraph.locale') {
            return 'OpenGraph Locale';
        }

        if ($path === 'meta.opengraph.image') {
            return 'OpenGraph Image';
        }

        if ($path === 'session.cookie.name') {
            return 'Cookie Name';
        }

        if ($path === 'session.cookie.domain') {
            return 'Cookie Domain';
        }

        if ($path === 'session.cookie.prefix') {
            return 'Cookie Prefix';
        }

        if ($path === 'user.privacy') {
            return 'Enable Profiles';
        }

        if ($path === 'user.auth.login') {
            return 'Login Method';
        }

        if ($path === 'user.auth.registration') {
            return 'Enable Public Registration';
        }

        if ($path === 'user.prefix') {
            return 'Profile URL Prefix';
        }

        if ($path === 'group.privacy') {
            return 'Show Groups';
        }

        if ($path === 'group.prefix') {
            return 'Group URL Prefix';
        }

        if ($path === 'session.brute.max') {
            return 'Max Login Failures';
        }

        if ($path === 'session.brute.window') {
            return 'Login Failure Window (Seconds)';
        }

        if ($path === 'session.brute.lock') {
            return 'Login Lock Duration (Seconds)';
        }

        if ($path === 'debug.show_public') {
            return 'Enable Output Profiler on Public Views';
        }

        if ($path === 'debug.show_private') {
            return 'Enable Output Profiler on Panel Views';
        }

        if ($path === 'debug.show_benchmarks') {
            return 'Benchmarks';
        }

        if ($path === 'debug.show_queries') {
            return 'SQL Queries';
        }

        if ($path === 'debug.show_trace') {
            return 'Render Stack Trace';
        }

        if ($path === 'debug.show_request') {
            return 'Request Data';
        }

        if ($path === 'debug.show_environment') {
            return 'Environment';
        }

        $segments = explode('.', $path);
        $leaf = (string) end($segments);
        $leaf = str_replace('_', ' ', $leaf);

        return ucwords($leaf);
    }

    /**
     * Returns a scalar type hint used for safe form-to-config casting.
     */
    private function detectConfigScalarType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'string',
        };
    }

    /**
     * Converts one scalar config value to a text representation.
     */
    private function stringifyConfigScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Reads one submitted config field from a nested posted array.
     *
     * @param array<string, mixed> $submitted
     * @param array<int, string> $segments
     */
    private function readNestedConfigValue(array $submitted, array $segments): string
    {
        $cursor = $submitted;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        if (is_string($cursor)) {
            return $cursor;
        }

        if (is_int($cursor) || is_float($cursor) || is_bool($cursor)) {
            return (string) $cursor;
        }

        return '';
    }

    /**
     * Writes one scalar value into a nested config array by path segments.
     *
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     */
    private function setNestedConfigValue(array &$config, array $segments, mixed $value): void
    {
        $cursor = &$config;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Casts and validates one submitted config field value by expected type.
     */
    private function normalizeConfigFieldValue(string $path, string $type, string $rawValue, array $workingConfig = []): mixed
    {
        $value = $this->input->text($rawValue, 1000);

        if ($path === 'panel.path') {
            $slug = $this->input->slug($value);
            if ($slug === null) {
                throw new \RuntimeException('panel.path must be a valid slug.');
            }

            return $slug;
        }

        if ($path === 'site.domain') {
            if ($value === '') {
                throw new \RuntimeException('site.domain is required.');
            }

            return $value;
        }

        if ($path === 'site.enabled') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                throw new \RuntimeException('site.enabled must be public, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'database.driver') {
            $driver = strtolower($value);
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new \RuntimeException('database.driver must be sqlite, mysql, or pgsql.');
            }

            return $driver;
        }

        if ($path === 'category.enabled' || $path === 'tag.enabled') {
            return $this->normalizeConfigBool($path, $value);
        }

        if ($path === 'category.prefix' || $path === 'tag.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $isCategoryPath = $path === 'category.prefix';
            $thisEnabled = $isCategoryPath
                ? $this->configBool(
                    $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', true),
                    true
                )
                : $this->configBool(
                    $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', true),
                    true
                );
            if (!$thisEnabled) {
                return $prefix;
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $otherPath = $path === 'category.prefix' ? 'tag.prefix' : 'category.prefix';
            $otherDefault = $path === 'category.prefix' ? 'tag' : 'cat';
            $otherEnabled = $isCategoryPath
                ? $this->configBool(
                    $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', true),
                    true
                )
                : $this->configBool(
                    $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', true),
                    true
                );
            $otherRaw = $otherPath === 'category.prefix'
                ? (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', $otherDefault))
                : (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            if ($otherEnabled && $otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('category.prefix and tag.prefix must be different values.');
            }

            return $prefix;
        }

        if ($path === 'user.privacy') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException('user.privacy must be public_full, public_limited, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'user.auth.login') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['email', 'username'], true)) {
                throw new \RuntimeException('user.auth.login must be email or username.');
            }

            return $mode;
        }

        if ($path === 'user.auth.registration') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['open', 'invite', 'closed'], true)) {
                throw new \RuntimeException('user.auth.registration must be open, invite, or closed.');
            }

            return $mode;
        }

        if ($path === 'user.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('user.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('user.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = $this->configBool(
                $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', true),
                true
            );
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('user.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = $this->configBool(
                $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', true),
                true
            );
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('user.prefix cannot match tag.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ($workingConfig['group']['prefix'] ?? $this->config->get('group.prefix', 'group'))
            );
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException('user.prefix cannot match group.prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('user.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'group.privacy') {
            $mode = strtolower(trim($value));
            if ($mode === 'public') {
                $mode = 'public_full';
            }
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException(
                    'group.privacy must be public_full, public_limited, private, or disabled.'
                );
            }

            return $mode;
        }

        if ($path === 'group.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('group.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('group.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = $this->configBool(
                $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', true),
                true
            );
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('group.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = $this->configBool(
                $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', true),
                true
            );
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('group.prefix cannot match tag.prefix.');
            }

            $profilePrefix = $this->input->slug(
                (string) ($workingConfig['user']['prefix'] ?? $this->config->get('user.prefix', 'user'))
            );
            if ($profilePrefix !== null && $profilePrefix !== '' && $prefix === $profilePrefix) {
                throw new \RuntimeException('group.prefix cannot match user.prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('group.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'captcha.provider') {
            $provider = strtolower($value);
            if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
                throw new \RuntimeException('captcha.provider must be none, hcaptcha, recaptcha2, or recaptcha3.');
            }

            return $provider;
        }

        if ($path === 'mail.agent') {
            $agent = strtolower($value);
            if (!in_array($agent, ['php_mail'], true)) {
                throw new \RuntimeException('mail.agent must be php_mail.');
            }

            return $agent;
        }

        if ($path === 'content.default_editor') {
            $editor = $this->normalizeBodyTextEditorOption($value);
            if ($editor === 'tinymce' && strtolower(trim($value)) !== 'tinymce') {
                throw new \RuntimeException(
                    'content.default_editor must be tinymce, plaintext, autobr, or markdown.'
                );
            }

            return $editor;
        }

        if ($path === 'content.separator') {
            $separator = $this->normalizeGlobalPageUrlSeparator($value);
            if ($separator === '-' && trim($value) !== '-') {
                throw new \RuntimeException(
                    'content.separator must be - or _.'
                );
            }

            return $separator;
        }

        if ($path === 'mail.sender_address') {
            $address = trim($value);
            if ($address === '') {
                return '';
            }

            $normalized = $this->input->email($address);
            if ($normalized === null) {
                throw new \RuntimeException('mail.sender_address must be a valid email address or blank.');
            }

            return $normalized;
        }

        if ($path === 'mail.sender_name') {
            return $this->input->text($value, 120);
        }

        if (in_array($path, ['meta.twitter.image', 'meta.apple_touch_icon', 'panel.brand_logo'], true)) {
            $siteDomain = (string) ($workingConfig['site']['domain'] ?? $this->config->get('site.domain', ''));
            return $this->normalizeMetaAbsoluteUrlPathValue($siteDomain, $value);
        }

        if ($path === 'meta.opengraph.image') {
            $siteDomain = (string) ($workingConfig['site']['domain'] ?? $this->config->get('site.domain', ''));
            return $this->normalizeMetaAbsoluteUrlPathValue($siteDomain, $value, false);
        }

        if ($path === 'panel.default_theme') {
            $theme = $this->normalizePanelThemeChoice($value, false);
            if (!is_string($theme)) {
                throw new \RuntimeException('panel.default_theme must be corp, ice, or midnight.');
            }

            return $theme;
        }

        if ($path === 'site.default_theme') {
            $theme = strtolower($value);
            $options = $this->publicThemeOptions();
            if (!isset($options[$theme])) {
                throw new \RuntimeException('site.default_theme must match one installed theme manifest.');
            }

            return $theme;
        }

        if ($path === 'session.cookie.name') {
            $sessionName = trim($value);
            if ($sessionName === '') {
                throw new \RuntimeException('session.cookie.name is required.');
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
                throw new \RuntimeException('session.cookie.name may contain only letters, numbers, underscores, and hyphens (max 64 chars).');
            }

            return $sessionName;
        }

        if ($path === 'session.cookie.domain') {
            $cookieDomain = strtolower(trim($value));
            if ($cookieDomain === '') {
                return '';
            }

            if (preg_match('/[:\/\s]/', $cookieDomain) === 1) {
                throw new \RuntimeException('session.cookie.domain must be a bare domain (no protocol, path, port, or spaces).');
            }

            if (!preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain)) {
                throw new \RuntimeException('session.cookie.domain must be a valid domain value.');
            }

            return $cookieDomain;
        }

        if ($path === 'session.cookie.prefix') {
            $cookiePrefix = trim($value);
            if ($cookiePrefix === '') {
                return '';
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix)) {
                throw new \RuntimeException('session.cookie.prefix may contain only letters, numbers, underscores, and hyphens (max 40 chars).');
            }

            return $cookiePrefix;
        }

        if ($path === 'session.brute.max') {
            $maxAttempts = $this->normalizeConfigInt($path, $value);
            if ($maxAttempts < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $maxAttempts;
        }

        if ($path === 'session.brute.window' || $path === 'session.brute.lock') {
            $seconds = $this->normalizeConfigInt($path, $value);
            if ($seconds < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $seconds;
        }

        if (str_starts_with($path, 'debug.')) {
            return $this->normalizeConfigBool($path, $value);
        }

        if ($path === 'media.avatars.max_filesize_kb') {
            $size = $this->normalizeConfigInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if (str_starts_with($path, 'media.images.')) {
            // Keep image-config fields strongly typed to avoid invalid values
            // breaking later media-processing features.
            return $this->normalizeImageConfigValue($path, $value);
        }

        return match ($type) {
            'int' => $this->normalizeConfigInt($path, $value),
            'float' => $this->normalizeConfigFloat($path, $value),
            'bool' => $this->normalizeConfigBool($path, $value),
            'null' => $value === '' ? null : $value,
            default => $value,
        };
    }

    /**
     * Normalizes one domain + path-style input into an absolute https URL.
     *
     * Config editor displays these fields with an inline `https://{domain}/` prefix.
     */
    private function normalizeMetaAbsoluteUrlPathValue(string $siteDomain, string $rawPathOrUrl, bool $allowAbsoluteUrlPaste = true): string
    {
        $rawPathOrUrl = trim($rawPathOrUrl);
        if ($rawPathOrUrl === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $rawPathOrUrl) === 1) {
            if (!$allowAbsoluteUrlPaste) {
                throw new \RuntimeException('OpenGraph Image must be a local file path relative to site.domain, not a full URL.');
            }

            if (filter_var($rawPathOrUrl, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('Meta URL fields must be valid absolute URLs or URL paths.');
            }

            return $rawPathOrUrl;
        }

        $normalizedDomain = $this->normalizeDomainHostForUrlPrefix($siteDomain);
        if ($normalizedDomain === '') {
            throw new \RuntimeException('site.domain must be set before saving URL-path meta fields.');
        }

        return 'https://' . $normalizedDomain . '/' . ltrim($rawPathOrUrl, '/');
    }

    /**
     * Normalizes `site.domain` into host[:port] for URL prefix composition.
     */
    private function normalizeDomainHostForUrlPrefix(string $rawDomain): string
    {
        $rawDomain = trim($rawDomain);
        if ($rawDomain === '') {
            return '';
        }

        if (str_contains($rawDomain, '://')) {
            $parsedHost = trim((string) parse_url($rawDomain, PHP_URL_HOST));
            $parsedPort = parse_url($rawDomain, PHP_URL_PORT);
            if ($parsedHost !== '') {
                return $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
            }
        }

        $rawDomain = preg_replace('/[\/?#].*$/', '', $rawDomain) ?? $rawDomain;
        return trim($rawDomain);
    }

    /**
     * Validates one integer config value.
     */
    private function normalizeConfigInt(string $path, string $value): int
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException($path . ' must be an integer.');
        }

        return (int) $value;
    }

    /**
     * Validates one float config value.
     */
    private function normalizeConfigFloat(string $path, string $value): float
    {
        if ($value === '' || !is_numeric($value)) {
            throw new \RuntimeException($path . ' must be numeric.');
        }

        return (float) $value;
    }

    /**
     * Validates one boolean config value from text input.
     */
    private function normalizeConfigBool(string $path, string $value): bool
    {
        $normalized = strtolower($value);

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException($path . ' must be a boolean (true/false).');
    }

    /**
     * Validates one media.images.* config field from configuration editor.
     */
    private function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        if ($path === 'media.images.upload_target') {
            $target = strtolower($value);
            if ($target !== 'local') {
                throw new \RuntimeException('media.images.upload_target currently supports only local.');
            }

            return $target;
        }

        if ($path === 'media.images.strip_exif') {
            return $this->normalizeConfigBool($path, $value);
        }

        if ($path === 'media.images.max_filesize_kb') {
            $size = $this->normalizeConfigInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if ($path === 'media.images.max_files_per_upload') {
            $count = $this->normalizeConfigInt($path, $value);
            if ($count < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $count;
        }

        if ($path === 'media.images.allowed_extensions') {
            $normalized = strtolower($value);
            $parts = array_map('trim', explode(',', $normalized));
            $parts = array_values(array_filter($parts, static fn (string $ext): bool => $ext !== ''));
            if ($parts === []) {
                // Empty allow list is allowed and blocks all image uploads.
                return '';
            }

            foreach ($parts as $ext) {
                if (!preg_match('/^[a-z0-9]+$/', $ext)) {
                    throw new \RuntimeException($path . ' may only contain comma-separated alphanumeric extensions.');
                }
            }

            // Save canonical comma-separated list so downstream checks are deterministic.
            return implode(',', array_values(array_unique($parts)));
        }

        $dimensionPaths = [
            'media.images.small.width',
            'media.images.small.height',
            'media.images.med.width',
            'media.images.med.height',
            'media.images.large.width',
            'media.images.large.height',
        ];
        if (in_array($path, $dimensionPaths, true)) {
            $dimension = $this->normalizeConfigInt($path, $value);
            if ($dimension < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $dimension;
        }

        return $value;
    }

    /**
     * Removes SQLite file map from user-managed config payload.
     *
     * SQLite filenames are core-managed and intentionally not stored in
     * `private/config.php` to prevent drift across installs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function removeSqliteDatabaseFilesConfig(array $config): array
    {
        $database = $config['database'] ?? null;
        if (!is_array($database)) {
            return $config;
        }

        $sqlite = $database['sqlite'] ?? null;
        if (!is_array($sqlite)) {
            return $config;
        }

        unset($sqlite['files']);
        $database['sqlite'] = $sqlite;
        $config['database'] = $database;

        return $config;
    }

    /**
     * Ensures content-editor config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureContentEditorConfig(array $config): array
    {
        $content = $config['content'] ?? null;
        if (!is_array($content)) {
            $content = [];
        }

        if (!array_key_exists('default_editor', $content) && array_key_exists('default_text_editor', $content)) {
            $content['default_editor'] = $content['default_text_editor'];
        }
        if (!array_key_exists('separator', $content) && array_key_exists('page_url_separator', $content)) {
            $content['separator'] = $content['page_url_separator'];
        }

        $content['default_editor'] = $this->normalizeBodyTextEditorOption(
            (string) ($content['default_editor'] ?? 'tinymce')
        );
        $content['separator'] = $this->normalizeGlobalPageUrlSeparator(
            (string) ($content['separator'] ?? '-')
        );
        unset($content['default_text_editor'], $content['page_url_separator']);

        $config['content'] = $content;
        return $config;
    }

    /**
     * Ensures taxonomy route-prefix config keys exist and are valid slugs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureTaxonomyRoutePrefixConfig(array $config): array
    {
        $category = $config['category'] ?? null;
        if (!is_array($category)) {
            $category = [];
        }
        $legacyCategories = $config['categories'] ?? null;
        if (is_array($legacyCategories)) {
            if (!array_key_exists('enabled', $category) && array_key_exists('enabled', $legacyCategories)) {
                $category['enabled'] = $legacyCategories['enabled'];
            }
            if (!array_key_exists('prefix', $category) && array_key_exists('prefix', $legacyCategories)) {
                $category['prefix'] = $legacyCategories['prefix'];
            }
            if (!array_key_exists('pagination', $category) && array_key_exists('pagination', $legacyCategories)) {
                $category['pagination'] = $legacyCategories['pagination'];
            }
        }

        $tag = $config['tag'] ?? null;
        if (!is_array($tag)) {
            $tag = [];
        }
        $legacyTags = $config['tags'] ?? null;
        if (is_array($legacyTags)) {
            if (!array_key_exists('enabled', $tag) && array_key_exists('enabled', $legacyTags)) {
                $tag['enabled'] = $legacyTags['enabled'];
            }
            if (!array_key_exists('prefix', $tag) && array_key_exists('prefix', $legacyTags)) {
                $tag['prefix'] = $legacyTags['prefix'];
            }
            if (!array_key_exists('pagination', $tag) && array_key_exists('pagination', $legacyTags)) {
                $tag['pagination'] = $legacyTags['pagination'];
            }
        }

        if (!array_key_exists('enabled', $category)) {
            $category['enabled'] = true;
        } else {
            $category['enabled'] = $this->configBool($category['enabled'], true);
        }

        if (!array_key_exists('prefix', $category)) {
            $category['prefix'] = 'cat';
        } else {
            $rawCategoryPrefix = trim((string) ($category['prefix'] ?? ''));
            if ($rawCategoryPrefix === '') {
                $category['prefix'] = '';
            } else {
                $categoryPrefix = $this->input->slug($rawCategoryPrefix);
                $category['prefix'] = $categoryPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $category)) {
            $category['pagination'] = 10;
        } else {
            $category['pagination'] = max(1, (int) ($category['pagination'] ?? 10));
        }

        if (!array_key_exists('enabled', $tag)) {
            $tag['enabled'] = true;
        } else {
            $tag['enabled'] = $this->configBool($tag['enabled'], true);
        }

        if (!array_key_exists('prefix', $tag)) {
            $tag['prefix'] = 'tag';
        } else {
            $rawTagPrefix = trim((string) ($tag['prefix'] ?? ''));
            if ($rawTagPrefix === '') {
                $tag['prefix'] = '';
            } else {
                $tagPrefix = $this->input->slug($rawTagPrefix);
                $tag['prefix'] = $tagPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $tag)) {
            $tag['pagination'] = 10;
        } else {
            $tag['pagination'] = max(1, (int) ($tag['pagination'] ?? 10));
        }

        $config['category'] = $category;
        $config['tag'] = $tag;
        // Old taxonomy/pagination keys are removed to keep config layout canonical.
        unset($config['categories'], $config['tags'], $config['tagging'], $config['pagination']);
        return $config;
    }

    /**
     * Ensures public-profile config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensurePublicProfileConfig(array $config): array
    {
        $session = $config['session'] ?? null;
        if (!is_array($session)) {
            $session = [];
        }

        $legacyProfileMode = strtolower(trim((string) ($session['profile_mode'] ?? '')));
        $legacyProfilePrefix = trim((string) ($session['profile_prefix'] ?? ''));
        $legacyProfileContact = $session['profile_contact_options'] ?? null;
        $legacyGroupMode = strtolower(trim((string) ($session['show_groups'] ?? '')));
        $legacyGroupPrefix = trim((string) ($session['group_prefix'] ?? ''));
        $legacySessionName = trim((string) ($session['name'] ?? ''));
        $legacyCookieDomain = strtolower(trim((string) ($session['cookie_domain'] ?? '')));
        $legacyCookiePrefix = trim((string) ($session['cookie_prefix'] ?? ''));
        $legacyBruteMax = (int) ($session['login_attempt_max'] ?? 5);
        $legacyBruteWindow = (int) ($session['login_attempt_window_seconds'] ?? 600);
        $legacyBruteLock = (int) ($session['login_attempt_lock_seconds'] ?? 900);
        unset(
            $session['profile_mode'],
            $session['profile_prefix'],
            $session['profile_contact_options'],
            $session['show_groups'],
            $session['group_prefix'],
            $session['name'],
            $session['cookie_domain'],
            $session['cookie_prefix'],
            $session['login_attempt_max'],
            $session['login_attempt_window_seconds'],
            $session['login_attempt_lock_seconds']
        );

        $cookie = $session['cookie'] ?? null;
        if (!is_array($cookie)) {
            $cookie = [];
        }

        if (!array_key_exists('name', $cookie)) {
            $cookie['name'] = $legacySessionName !== '' ? $legacySessionName : 'session';
        } else {
            $cookie['name'] = trim((string) ($cookie['name'] ?? ''));
        }
        if ($cookie['name'] === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $cookie['name']) !== 1) {
            $cookie['name'] = 'session';
        }

        if (!array_key_exists('domain', $cookie)) {
            $cookie['domain'] = $legacyCookieDomain;
        } else {
            $cookie['domain'] = strtolower(trim((string) ($cookie['domain'] ?? '')));
        }
        $cookieDomain = (string) ($cookie['domain'] ?? '');
        if (
            $cookieDomain !== ''
            && (
                preg_match('/[:\/\s]/', $cookieDomain) === 1
                || preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) !== 1
            )
        ) {
            $cookie['domain'] = '';
        }

        if (!array_key_exists('prefix', $cookie)) {
            $cookie['prefix'] = $legacyCookiePrefix !== '' ? $legacyCookiePrefix : 'rvn_';
        } else {
            $cookie['prefix'] = trim((string) ($cookie['prefix'] ?? ''));
        }
        $cookiePrefix = (string) ($cookie['prefix'] ?? '');
        if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) !== 1) {
            $cookie['prefix'] = '';
        }

        $brute = $session['brute'] ?? null;
        if (!is_array($brute)) {
            $brute = [];
        }
        if (!array_key_exists('max', $brute)) {
            $brute['max'] = $legacyBruteMax;
        }
        if (!array_key_exists('window', $brute)) {
            $brute['window'] = $legacyBruteWindow;
        }
        if (!array_key_exists('lock', $brute)) {
            $brute['lock'] = $legacyBruteLock;
        }
        $brute['max'] = max(1, (int) ($brute['max'] ?? 5));
        $brute['window'] = max(1, (int) ($brute['window'] ?? 600));
        $brute['lock'] = max(1, (int) ($brute['lock'] ?? 900));

        $session['cookie'] = $cookie;
        $session['brute'] = $brute;

        $user = $config['user'] ?? null;
        if (!is_array($user)) {
            $user = [];
        }

        if (!array_key_exists('privacy', $user)) {
            $user['privacy'] = in_array($legacyProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)
                ? $legacyProfileMode
                : 'disabled';
        } else {
            $rawProfileMode = strtolower(trim((string) ($user['privacy'] ?? '')));
            if (!in_array($rawProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawProfileMode = 'disabled';
            }
            $user['privacy'] = $rawProfileMode;
        }

        if (!array_key_exists('prefix', $user)) {
            $user['prefix'] = $legacyProfilePrefix !== '' ? $legacyProfilePrefix : 'user';
        } else {
            $rawProfilePrefix = trim((string) ($user['prefix'] ?? ''));
            if ($rawProfilePrefix === '') {
                $user['prefix'] = '';
            } else {
                $profilePrefix = $this->input->slug($rawProfilePrefix);
                $user['prefix'] = $profilePrefix ?? '';
            }
        }

        $user['contact'] = $this->normalizeProfileContactOptionsConfig(
            array_key_exists('contact', $user) ? $user['contact'] : $legacyProfileContact
        );

        $group = $config['group'] ?? null;
        if (!is_array($group)) {
            $group = [];
        }

        if (!array_key_exists('privacy', $group)) {
            if ($legacyGroupMode === 'public') {
                $legacyGroupMode = 'public_full';
            }
            $group['privacy'] = in_array($legacyGroupMode, ['public_full', 'public_limited', 'private', 'disabled'], true)
                ? $legacyGroupMode
                : 'disabled';
        } else {
            $rawShowGroups = strtolower(trim((string) ($group['privacy'] ?? '')));
            if ($rawShowGroups === 'public') {
                $rawShowGroups = 'public_full';
            }
            if (!in_array($rawShowGroups, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawShowGroups = 'disabled';
            }
            $group['privacy'] = $rawShowGroups;
        }

        if (!array_key_exists('prefix', $group)) {
            $group['prefix'] = $legacyGroupPrefix !== '' ? $legacyGroupPrefix : 'group';
        } else {
            $rawGroupPrefix = trim((string) ($group['prefix'] ?? ''));
            if ($rawGroupPrefix === '') {
                $group['prefix'] = '';
            } else {
                $groupPrefix = $this->input->slug($rawGroupPrefix);
                $group['prefix'] = $groupPrefix ?? '';
            }
        }

        $config['session'] = $session;
        $config['user'] = $user;
        $config['group'] = $group;
        return $config;
    }

    /**
     * Ensures user-auth login identifier config exists with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureUserAuthConfig(array $config): array
    {
        $user = $config['user'] ?? null;
        if (!is_array($user)) {
            $user = [];
        }

        $auth = $user['auth'] ?? null;
        if (!is_array($auth)) {
            $auth = [];
        }

        if (!array_key_exists('login', $auth)) {
            $legacyMode = strtolower(trim((string) ($user['login'] ?? $user['login_mode'] ?? '')));
            if (!in_array($legacyMode, ['email', 'username'], true)) {
                $legacyMode = 'email';
            }
            $auth['login'] = $legacyMode;
        } else {
            $mode = strtolower(trim((string) ($auth['login'] ?? 'email')));
            if (!in_array($mode, ['email', 'username'], true)) {
                $mode = 'email';
            }
            $auth['login'] = $mode;
        }

        if (!array_key_exists('registration', $auth)) {
            $legacyRegistration = strtolower(trim((string) ($user['registration'] ?? $user['registration_mode'] ?? '')));
            if (!in_array($legacyRegistration, ['open', 'invite', 'closed'], true)) {
                $legacyRegistration = 'closed';
            }
            $auth['registration'] = $legacyRegistration;
        } else {
            $registrationMode = strtolower(trim((string) ($auth['registration'] ?? 'closed')));
            if (!in_array($registrationMode, ['open', 'invite', 'closed'], true)) {
                $registrationMode = 'closed';
            }
            $auth['registration'] = $registrationMode;
        }

        unset($user['login'], $user['login_mode'], $user['registration'], $user['registration_mode']);
        $user['auth'] = $auth;
        $config['user'] = $user;

        return $config;
    }

    /**
     * Ensures site-level config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureSiteEnabledConfig(array $config): array
    {
        $site = $config['site'] ?? null;
        if (!is_array($site)) {
            $site = [];
        }

        if (!array_key_exists('enabled', $site)) {
            $site['enabled'] = 'public';
        } else {
            $mode = strtolower(trim((string) ($site['enabled'] ?? '')));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                $mode = 'public';
            }
            $site['enabled'] = $mode;
        }

        if (!array_key_exists('default_theme', $site)) {
            $site['default_theme'] = 'raven';
        } else {
            $configuredTheme = strtolower(trim((string) ($site['default_theme'] ?? '')));
            $options = $this->publicThemeOptions();
            if (isset($options[$configuredTheme])) {
                $site['default_theme'] = $configuredTheme;
            } elseif (isset($options['raven'])) {
                $site['default_theme'] = 'raven';
            } else {
                $slugs = array_keys($options);
                $site['default_theme'] = (string) ($slugs[0] ?? 'raven');
            }
        }

        $config['site'] = $site;
        return $config;
    }

    /**
     * Ensures panel config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensurePanelBrandingConfig(array $config): array
    {
        $panel = $config['panel'] ?? null;
        if (!is_array($panel)) {
            $panel = [];
        }

        if (!array_key_exists('path', $panel)) {
            $panel['path'] = 'panel';
        } else {
            $panelPath = $this->input->slug((string) ($panel['path'] ?? ''));
            $panel['path'] = $panelPath ?? 'panel';
        }

        if (!array_key_exists('default_theme', $panel)) {
            $panel['default_theme'] = 'corp';
        } else {
            $configuredTheme = $this->normalizePanelThemeChoice((string) ($panel['default_theme'] ?? ''), false);
            $panel['default_theme'] = is_string($configuredTheme) ? $configuredTheme : 'corp';
        }

        if (!array_key_exists('brand_name', $panel)) {
            $siteName = trim((string) ($config['site']['name'] ?? 'Raven CMS'));
            $panel['brand_name'] = $siteName !== '' ? $siteName : 'Raven CMS';
        } else {
            $panel['brand_name'] = trim((string) ($panel['brand_name'] ?? ''));
        }

        if (!array_key_exists('brand_logo', $panel)) {
            $panel['brand_logo'] = '';
        } else {
            $panel['brand_logo'] = trim((string) ($panel['brand_logo'] ?? ''));
        }

        $config['panel'] = $panel;
        return $config;
    }

    /**
     * Ensures captcha provider/keys are normalized with explicit reCAPTCHA v2/v3 drivers.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureCaptchaConfig(array $config): array
    {
        $captcha = $config['captcha'] ?? null;
        if (!is_array($captcha)) {
            $captcha = [];
        }

        $provider = strtolower(trim((string) ($captcha['provider'] ?? 'none')));
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            $provider = 'none';
        }
        $captcha['provider'] = $provider;

        $hcaptcha = $captcha['hcaptcha'] ?? null;
        if (!is_array($hcaptcha)) {
            $hcaptcha = [];
        }
        $hcaptcha['public_key'] = trim((string) ($hcaptcha['public_key'] ?? ''));
        $hcaptcha['secret_key'] = trim((string) ($hcaptcha['secret_key'] ?? ''));

        $recaptcha2 = $captcha['recaptcha2'] ?? null;
        if (!is_array($recaptcha2)) {
            $recaptcha2 = [];
        }
        $recaptcha2['public_key'] = trim((string) ($recaptcha2['public_key'] ?? ''));
        $recaptcha2['secret_key'] = trim((string) ($recaptcha2['secret_key'] ?? ''));

        $recaptcha3 = $captcha['recaptcha3'] ?? null;
        if (!is_array($recaptcha3)) {
            $recaptcha3 = [];
        }
        $recaptcha3['public_key'] = trim((string) ($recaptcha3['public_key'] ?? ''));
        $recaptcha3['secret_key'] = trim((string) ($recaptcha3['secret_key'] ?? ''));

        $captcha['hcaptcha'] = $hcaptcha;
        $captcha['recaptcha2'] = $recaptcha2;
        $captcha['recaptcha3'] = $recaptcha3;
        $config['captcha'] = $captcha;

        return $config;
    }

    /**
     * Ensures mail config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureMailConfig(array $config): array
    {
        $mail = $config['mail'] ?? null;
        if (!is_array($mail)) {
            $mail = [];
        }

        $agent = strtolower(trim((string) ($mail['agent'] ?? 'php_mail')));
        if (!in_array($agent, ['php_mail'], true)) {
            $agent = 'php_mail';
        }
        $mail['agent'] = $agent;
        unset($mail['prefix']);

        $senderName = $this->input->text((string) ($mail['sender_name'] ?? 'Postmaster'), 120);
        if ($senderName === '') {
            $senderName = 'Postmaster';
        }
        $mail['sender_name'] = $senderName;

        $senderAddressRaw = trim((string) ($mail['sender_address'] ?? ''));
        if ($senderAddressRaw === '') {
            $mail['sender_address'] = '';
        } else {
            $normalizedAddress = $this->input->email($senderAddressRaw);
            $mail['sender_address'] = $normalizedAddress ?? '';
        }

        $config['mail'] = $mail;

        return $config;
    }

    /**
     * Ensures debug-toolbar config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureDebugToolbarConfig(array $config): array
    {
        $debug = $config['debug'] ?? null;
        if (!is_array($debug)) {
            $debug = [];
        }

        $toBool = static function (mixed $value, bool $default): bool {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return ((int) $value) !== 0;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                    return false;
                }
            }

            return $default;
        };

        if (!array_key_exists('show_public', $debug) && array_key_exists('show_on_public', $debug)) {
            $debug['show_public'] = $debug['show_on_public'];
        }
        if (!array_key_exists('show_private', $debug) && array_key_exists('show_on_panel', $debug)) {
            $debug['show_private'] = $debug['show_on_panel'];
        }
        if (!array_key_exists('show_private', $debug) && array_key_exists('show_on_private', $debug)) {
            $debug['show_private'] = $debug['show_on_private'];
        }
        if (!array_key_exists('show_trace', $debug) && array_key_exists('show_stack_trace', $debug)) {
            $debug['show_trace'] = $debug['show_stack_trace'];
        }

        $debug['show_public'] = $toBool($debug['show_public'] ?? false, false);
        $debug['show_private'] = $toBool($debug['show_private'] ?? false, false);
        $debug['show_benchmarks'] = $toBool($debug['show_benchmarks'] ?? true, true);
        $debug['show_queries'] = $toBool($debug['show_queries'] ?? true, true);
        $debug['show_trace'] = $toBool($debug['show_trace'] ?? true, true);
        $debug['show_request'] = $toBool($debug['show_request'] ?? true, true);
        $debug['show_environment'] = $toBool($debug['show_environment'] ?? true, true);
        unset($debug['show_on_public'], $debug['show_on_panel'], $debug['show_on_private'], $debug['show_stack_trace']);

        $config['debug'] = $debug;
        return $config;
    }

    /**
     * Resolves one media max-filesize limit in bytes.
     */
    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();
        $section = $config['media'][$target] ?? null;
        if (is_array($section) && array_key_exists('max_filesize_kb', $section)) {
            $kilobytes = (int) $section['max_filesize_kb'];
            if ($kilobytes > 0) {
                return max(1, $kilobytes * 1024);
            }

            if ($kilobytes === 0) {
                // `0` means unlimited file size in the config editor.
                return 0;
            }
        }

        return max(1, $defaultBytes);
    }

    /**
     * Resolves avatar allowlist CSV, falling back to image allowlist when avatar is empty.
     */
    private function resolveAvatarAllowedExtensionsCsv(): string
    {
        $avatarAllowList = trim((string) $this->config->get('media.avatars.allowed_extensions', ''));
        if ($avatarAllowList !== '') {
            return $avatarAllowList;
        }

        return trim((string) $this->config->get('media.images.allowed_extensions', ''));
    }

    /**
     * Returns panel-facing extension summary for avatar helper text.
     */
    private function avatarAllowedExtensionsLabel(): string
    {
        $raw = strtolower(trim($this->resolveAvatarAllowedExtensionsCsv()));
        if ($raw === '') {
            return 'none';
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if (!in_array($token, ['gif', 'jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $allowed[$token] = $token;
        }

        if ($allowed === []) {
            return 'none';
        }

        return implode('/', array_values($allowed));
    }

    /**
     * Returns one config-driven avatar upload note for panel forms.
     */
    private function avatarUploadLimitsNote(): string
    {
        $maxBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
        $maxKilobytes = $maxBytes <= 0 ? 0 : (int) max(1, ceil($maxBytes / 1024));
        $maxWidth = max(1, (int) $this->config->get('media.avatars.max_width', 500));
        $maxHeight = max(1, (int) $this->config->get('media.avatars.max_height', 500));
        $extensions = $this->avatarAllowedExtensionsLabel();

        return 'Max: ' . $maxKilobytes . 'KB, ' . $maxWidth . 'x' . $maxHeight . 'px, ' . $extensions;
    }

    /**
     * Returns normalized image-extension allowlist for taxonomy uploads.
     *
     * @return array<int, string>
     */
    private function taxonomyAllowedImageExtensions(): array
    {
        $raw = strtolower(trim((string) $this->config->get('media.images.allowed_extensions', 'gif,jpg,jpeg,png')));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $allowed = [];
        foreach ($parts as $part) {
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            if ($part === '' || preg_match('/^[a-z0-9]+$/', $part) !== 1) {
                continue;
            }

            $allowed[$part] = $part;
        }

        return array_values($allowed);
    }

    /**
     * Returns panel-facing allowlist summary for taxonomy image helper text.
     */
    private function taxonomyAllowedImageExtensionsLabel(): string
    {
        $allowed = $this->taxonomyAllowedImageExtensions();
        return $allowed === [] ? 'none (uploads disabled)' : implode(', ', $allowed);
    }

    /**
     * Returns max taxonomy image filesize in KB, or null for unlimited.
     */
    private function taxonomyMaxImageFilesizeKb(): ?int
    {
        $bytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        if ($bytes <= 0) {
            return null;
        }

        return (int) max(1, ceil($bytes / 1024));
    }

    /**
     * Returns configured variant target sizes used for taxonomy images.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function taxonomyImageVariantSpecs(): array
    {
        return [
            'sm' => [
                'width' => max(0, (int) $this->config->get('media.images.small.width', 200)),
                'height' => max(0, (int) $this->config->get('media.images.small.height', 200)),
            ],
            'md' => [
                'width' => max(0, (int) $this->config->get('media.images.med.width', 600)),
                'height' => max(0, (int) $this->config->get('media.images.med.height', 600)),
            ],
            'lg' => [
                'width' => max(0, (int) $this->config->get('media.images.large.width', 1000)),
                'height' => max(0, (int) $this->config->get('media.images.large.height', 1000)),
            ],
        ];
    }

    /**
     * Returns normalized taxonomy image-path payload from one record row.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    private function taxonomyImagePathsFromRecord(?array $record): array
    {
        $paths = [];
        foreach ([
            'cover_image_path',
            'cover_image_sm_path',
            'cover_image_md_path',
            'cover_image_lg_path',
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ] as $key) {
            $raw = trim((string) ($record[$key] ?? ''));
            $paths[$key] = $raw !== '' ? $raw : null;
        }

        return $paths;
    }

    /**
     * Returns image-path column keys for one taxonomy image slot.
     *
     * @return array<int, string>
     */
    private function taxonomyImageKeysForSlot(string $slot): array
    {
        if ($slot === 'cover') {
            return [
                'cover_image_path',
                'cover_image_sm_path',
                'cover_image_md_path',
                'cover_image_lg_path',
            ];
        }

        return [
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
    }

    /**
     * Returns current image paths that are no longer referenced after update.
     *
     * @param array<string, string|null> $currentPaths
     * @param array<string, string|null> $nextPaths
     * @return array<int, string>
     */
    private function taxonomyRemovedPaths(array $currentPaths, array $nextPaths): array
    {
        $nextLookup = [];
        foreach ($nextPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized !== '') {
                $nextLookup[$normalized] = true;
            }
        }

        $removed = [];
        foreach ($currentPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '' || isset($nextLookup[$normalized])) {
                continue;
            }

            $removed[$normalized] = $normalized;
        }

        return array_values($removed);
    }

    /**
     * Removes newly-created taxonomy image files from one failed save flow.
     *
     * @param array<int, array<string, string|null>> $pathSets
     */
    private function cleanupTaxonomyImagePathSets(string $taxonomyType, int $taxonomyId, array $pathSets): void
    {
        $paths = [];
        foreach ($pathSets as $pathSet) {
            foreach ($pathSet as $path) {
                $normalized = trim((string) $path);
                if ($normalized === '') {
                    continue;
                }

                $paths[$normalized] = $normalized;
            }
        }

        $this->deleteTaxonomyStoredPaths($taxonomyType, $taxonomyId, array_values($paths));
    }

    /**
     * Deletes stored taxonomy image files under `public/uploads/{type}/{id}`.
     *
     * @param array<int, string>|array<string, string|null> $paths
     */
    private function deleteTaxonomyStoredPaths(string $taxonomyType, int $taxonomyId, array $paths): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $projectRoot = dirname(__DIR__, 3);
        $prefix = 'uploads/' . $taxonomyType . '/' . $taxonomyId . '/';

        foreach ($paths as $path) {
            $normalized = ltrim(trim((string) $path), '/');
            if (
                $normalized === ''
                || str_contains($normalized, '..')
                || str_contains($normalized, "\0")
                || str_contains($normalized, '\\')
                || !str_starts_with($normalized, $prefix)
            ) {
                continue;
            }

            $absolute = $projectRoot . '/public/' . $normalized;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $this->removeTaxonomyDirectoryIfEmpty($taxonomyType, $taxonomyId);
    }

    /**
     * Removes empty taxonomy image directory after file deletion.
     */
    private function removeTaxonomyDirectoryIfEmpty(string $taxonomyType, int $taxonomyId): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $directory = dirname(__DIR__, 3) . '/public/uploads/' . $taxonomyType . '/' . $taxonomyId;
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return;
        }

        @rmdir($directory);
    }

    /**
     * Stores one taxonomy slot image with generated sm/md/lg variants.
     *
     * @param array<string, mixed> $upload
     * @return array{
     *   ok: bool,
     *   paths?: array{
     *     cover_image_path?: string,
     *     cover_image_sm_path?: string,
     *     cover_image_md_path?: string,
     *     cover_image_lg_path?: string,
     *     preview_image_path?: string,
     *     preview_image_sm_path?: string,
     *     preview_image_md_path?: string,
     *     preview_image_lg_path?: string
     *   },
     *   error?: string
     * }
     */
    private function storeTaxonomyImageUpload(string $taxonomyType, int $taxonomyId, string $slot, array $upload): array
    {
        if (
            !in_array($taxonomyType, ['categories', 'channels', 'tags'], true)
            || $taxonomyId < 1
            || !in_array($slot, ['cover', 'preview'], true)
        ) {
            return ['ok' => false, 'error' => 'Invalid taxonomy image target.'];
        }

        if (!class_exists(\Imagick::class)) {
            return ['ok' => false, 'error' => 'Image upload requires Imagick (ImageMagick) extension.'];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->taxonomyUploadErrorMessage($uploadError)];
        }

        $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded image could not be validated as an upload.'];
        }

        $uploadTarget = strtolower((string) $this->config->get('media.images.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return ['ok' => false, 'error' => 'Only local image storage is supported in this build.'];
        }

        $maxBytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || ($maxBytes > 0 && $size > $maxBytes)) {
            return ['ok' => false, 'error' => 'Image exceeds configured max filesize.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
        ];
        if (!isset($mimeToExt[$detectedMime])) {
            return ['ok' => false, 'error' => 'Only gif/jpg/jpeg/png images are supported.'];
        }
        $canonicalExtension = $mimeToExt[$detectedMime];

        $allowedExtensions = $this->taxonomyAllowedImageExtensions();
        if ($allowedExtensions === [] || !in_array($canonicalExtension, $allowedExtensions, true)) {
            return ['ok' => false, 'error' => 'Detected image format is not allowed by current configuration.'];
        }

        $originalName = (string) ($upload['name'] ?? 'upload');
        $pathInfo = pathinfo($originalName);
        $originalExtension = strtolower((string) ($pathInfo['extension'] ?? ''));
        $originalExtension = $originalExtension === 'jpeg' ? 'jpg' : $originalExtension;
        if ($originalExtension !== '' && $originalExtension !== $canonicalExtension) {
            return ['ok' => false, 'error' => 'Uploaded extension does not match detected image bytes.'];
        }

        $dimensions = @getimagesize($tmpPath);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            return ['ok' => false, 'error' => 'Failed to read image dimensions.'];
        }

        $projectRoot = dirname(__DIR__, 3);
        $relativeDirectory = 'uploads/' . $taxonomyType . '/' . $taxonomyId;
        $absoluteDirectory = $projectRoot . '/public/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            return ['ok' => false, 'error' => 'Failed to create taxonomy image directory.'];
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Failed to initialize image storage token.'];
        }

        $baseFilename = $slot . '_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = $relativeDirectory . '/' . $originalFilename;
        $originalAbsolutePath = $projectRoot . '/public/' . $originalStoredPath;
        $writtenPaths = [];

        try {
            $source = new \Imagick();
            $source->readImage($tmpPath);
            $source->setIteratorIndex(0);
            $this->autoOrientTaxonomyImage($source);

            if ((bool) $this->config->get('media.images.strip_exif', true)) {
                $source->stripImage();
            }

            $source->setImageFormat($canonicalExtension === 'jpg' ? 'jpeg' : $canonicalExtension);
            if ($canonicalExtension === 'jpg') {
                $source->setImageCompressionQuality(85);
            }

            if (!$source->writeImage($originalAbsolutePath)) {
                throw new \RuntimeException('Failed to store processed source image.');
            }
            @chmod($originalAbsolutePath, 0640);
            $writtenPaths[] = $originalStoredPath;

            $sourceWidth = (int) $source->getImageWidth();
            $sourceHeight = (int) $source->getImageHeight();
            $paths = [
                $slot . '_image_path' => $originalStoredPath,
            ];

            foreach ($this->taxonomyImageVariantSpecs() as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->resolveTaxonomyVariantSize(
                    $sourceWidth,
                    $sourceHeight,
                    (int) ($spec['width'] ?? 0),
                    (int) ($spec['height'] ?? 0)
                );

                if ($target['width'] !== $sourceWidth || $target['height'] !== $sourceHeight) {
                    $variant->resizeImage(
                        $target['width'],
                        $target['height'],
                        \Imagick::FILTER_LANCZOS,
                        1.0,
                        false
                    );
                }

                if ($canonicalExtension === 'jpg') {
                    $variant->setImageCompressionQuality(85);
                }

                $variantFilename = $baseFilename . '_' . $variantKey . '.' . $canonicalExtension;
                $variantStoredPath = $relativeDirectory . '/' . $variantFilename;
                $variantAbsolutePath = $projectRoot . '/public/' . $variantStoredPath;

                if (!$variant->writeImage($variantAbsolutePath)) {
                    throw new \RuntimeException('Failed to store generated image variant.');
                }

                @chmod($variantAbsolutePath, 0640);
                $writtenPaths[] = $variantStoredPath;
                $paths[$slot . '_image_' . $variantKey . '_path'] = $variantStoredPath;
            }

            return [
                'ok' => true,
                'paths' => $paths,
            ];
        } catch (\Throwable $exception) {
            $this->deleteTaxonomyStoredPaths($taxonomyType, $taxonomyId, $writtenPaths);
            return [
                'ok' => false,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Image processing failed.',
            ];
        }
    }

    /**
     * Resolves one contain-style target size for taxonomy image variants.
     *
     * @return array{width: int, height: int}
     */
    private function resolveTaxonomyVariantSize(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return ['width' => 1, 'height' => 1];
        }

        if ($maxWidth <= 0 && $maxHeight <= 0) {
            return ['width' => $sourceWidth, 'height' => $sourceHeight];
        }

        if ($maxWidth <= 0) {
            $scale = min(1.0, $maxHeight / $sourceHeight);
        } elseif ($maxHeight <= 0) {
            $scale = min(1.0, $maxWidth / $sourceWidth);
        } else {
            $scale = min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        }

        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        if ($maxWidth > 0) {
            $targetWidth = min($targetWidth, $maxWidth);
        }
        if ($maxHeight > 0) {
            $targetHeight = min($targetHeight, $maxHeight);
        }

        return ['width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * Applies EXIF orientation transform for taxonomy image storage.
     */
    private function autoOrientTaxonomyImage(\Imagick $image): void
    {
        $orientation = $image->getImageOrientation();
        switch ($orientation) {
            case \Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            default:
                break;
        }

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Maps PHP upload error codes into taxonomy-upload messages.
     */
    private function taxonomyUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image exceeds upload size limits.',
            UPLOAD_ERR_PARTIAL => 'Uploaded image was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose an image file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'Image upload failed with an unknown error.',
        };
    }

    /**
     * Returns true when a root-level slug collides with reserved public prefixes.
     */
    private function isReservedPublicRootSlug(string $slug): bool
    {
        $panelPath = trim((string) $this->config->get('panel.path', 'panel'), '/');
        $reserved = array_values(array_unique(array_filter([
            $panelPath,
            'boot',
            'mce',
            'theme',
            'c',
            'tag',
        ])));

        return in_array($slug, $reserved, true);
    }

    /**
     * Validates redirect target URL format.
     *
     * Allowed target forms:
     * - absolute HTTP/HTTPS URLs (external or same-domain)
     * - root-relative internal URLs starting with `/`
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
     * Enforces dashboard access and group-based panel permission.
     */
    private function requirePanelLogin(): void
    {
        if (!$this->auth->isLoggedIn()) {
            if ($this->isGuestPanelLoginEntryRequest()) {
                redirect($this->panelUrl('/login'));
            }

            $this->renderPublicNotFound();
            exit;
        }

        if (!$this->auth->canAccessPanel()) {
            $this->auth->logout();
            if ($this->isGuestPanelLoginEntryRequest()) {
                redirect($this->panelUrl('/login'));
            }

            $this->renderPublicNotFound();
            exit;
        }

        $userId = $this->auth->userId();
        if ($userId !== null && !$this->auth->isTwoFactorVerifiedForUser($userId)) {
            if ($this->auth->pendingTwoFactorUserId() === $userId) {
                redirect($this->panelUrl('/login/2fa'));
            }

            $this->auth->logout();
            if ($this->isGuestPanelLoginEntryRequest()) {
                redirect($this->panelUrl('/login'));
            }

            $this->renderPublicNotFound();
            exit;
        }

        // Keep a lightweight identity payload in session for shared layout chrome
        // (for example personalized Welcome navigation headings).
        $this->syncPanelIdentityInSession();
    }

    /**
     * Returns true when guest request is the panel root or login path.
     */
    private function isGuestPanelLoginEntryRequest(): bool
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($requestPath === '') {
            $requestPath = '/';
        }

        $normalize = static function (string $path): string {
            $path = '/' . trim($path, '/');
            if ($path === '/' || $path === '//') {
                return '/';
            }

            return rtrim($path, '/');
        };

        $requestPath = $normalize($requestPath);
        $configuredPanel = $normalize((string) $this->config->get('panel.path', 'panel'));

        $allowedPaths = [
            $configuredPanel,
            $configuredPanel . '/login',
            $configuredPanel . '/login/2fa',
        ];

        return in_array($requestPath, $allowedPaths, true);
    }

    /**
     * Caches current panel user's display/username in session for layout rendering.
     */
    private function syncPanelIdentityInSession(): void
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            unset($_SESSION['rvn-panel-identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if ($preferences === null) {
            unset($_SESSION['rvn-panel-identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $_SESSION['rvn-panel-identity'] = [
            'display_name' => trim((string) ($preferences['display_name'] ?? '')),
            'username' => trim((string) ($preferences['username'] ?? '')),
            'email' => trim((string) ($preferences['email'] ?? '')),
        ];
        $_SESSION['_raven_can_manage_content'] = $this->auth->canManageContent();
        $_SESSION['_raven_can_manage_taxonomy'] = $this->auth->canManageTaxonomy();
        $_SESSION['_raven_can_manage_users'] = $this->auth->canManageUsers();
        $_SESSION['_raven_can_manage_groups'] = $this->auth->canManageGroups();
        $_SESSION['_raven_can_manage_configuration'] = $this->auth->canManageConfiguration();
    }

    /**
     * Returns normalized panel identity from session cache.
     *
     * @return array{display_name: string, username: string, email: string}
     */
    private function panelIdentityFromSession(): array
    {
        $raw = $_SESSION['rvn-panel-identity'] ?? null;
        if (!is_array($raw)) {
            return [
                'display_name' => '',
                'username' => '',
                'email' => '',
            ];
        }

        return [
            'display_name' => trim((string) ($raw['display_name'] ?? '')),
            'username' => trim((string) ($raw['username'] ?? '')),
            'email' => trim((string) ($raw['email'] ?? '')),
        ];
    }

    /**
     * Enforces one stock-route action permission for panel sections.
     */
    private function requireRoutePermissionOrForbidden(string $routeKey, string $action): bool
    {
        $routePermission = PanelAccess::stockPanelRoutePermission($routeKey);
        if ($routePermission === null) {
            $this->forbidden('Unknown panel route permission key.');
            return false;
        }

        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, ['view', 'create', 'edit', 'delete'], true)) {
            $this->forbidden('Unknown panel route permission action.');
            return false;
        }

        $requiredBit = (int) ($routePermission[$normalizedAction] ?? 0);
        if ($requiredBit > 0 && $this->auth->hasPanelPermissionBit($requiredBit)) {
            return true;
        }

        $this->forbidden(
            ucfirst($normalizedAction)
            . ' '
            . (string) ($routePermission['label'] ?? 'panel route')
            . ' permission is required for this section.'
        );
        return false;
    }

    /**
     * Renders a themed public not-found page for denied panel routes.
     */
    private function forbidden(string $_message): void
    {
        $this->renderPublicNotFound();
    }

    /**
     * Renders active public theme `messages/404` with wrapper layout.
     *
     * This is used for denied panel pages and can be called by extension routes
     * so unauthorized requests do not reveal panel URL inventory.
     */
    public function renderPublicNotFound(): void
    {
        http_response_code(404);

        $templateFile = $this->resolvePublicFallbackTemplateFile('messages/404');
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }

        $site = $this->publicSiteDataForNotFound();
        $content = $this->renderPublicFallbackTemplateFile($templateFile, [
            'site' => $site,
        ]);

        $layoutFile = $this->resolvePublicFallbackTemplateFile('wrapper');
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $this->renderPublicFallbackTemplateFile($layoutFile, [
            'site' => $site,
            'content' => $content,
        ]);
    }

    /**
     * Creates panel URL for redirects.
     */
    private function panelUrl(string $suffix): string
    {
        $prefix = '/' . trim((string) $this->config->get('panel.path', 'panel'), '/');
        $suffix = '/' . ltrim($suffix, '/');

        return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
    }

    /**
     * Normalizes one list pagination state from total items and requested page.
     *
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    private function panelPaginationState(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalItems = max(0, $totalItems);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = min(max(1, $requestedPage), $totalPages);

        return [
            'current' => $currentPage,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => ($currentPage - 1) * $perPage,
        ];
    }

    /**
     * Builds panel-list pagination payload for view templates.
     *
     * @param array{current: int, per_page: int, total_items: int, total_pages: int, offset: int} $pagination
     * @param array<string, scalar|null> $query
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, base_path: string, query: array<string, string>}
     */
    private function panelPaginationViewData(string $path, array $pagination, array $query = []): array
    {
        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            $stringValue = trim((string) ($value ?? ''));
            if ($stringValue === '') {
                continue;
            }

            $normalizedQuery[$key] = $stringValue;
        }

        return [
            'current' => (int) ($pagination['current'] ?? 1),
            'per_page' => (int) ($pagination['per_page'] ?? 50),
            'total_items' => (int) ($pagination['total_items'] ?? 0),
            'total_pages' => (int) ($pagination['total_pages'] ?? 1),
            'base_path' => $this->panelUrl($path),
            'query' => $normalizedQuery,
        ];
    }

    /**
     * Stores one flash message in session.
     */
    private function flash(string $key, string $value): void
    {
        $_SESSION['_raven_flash'][$key] = $value;
    }

    /**
     * Pulls and removes one flash message.
     */
    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION['_raven_flash'][$key] ?? null;
        unset($_SESSION['_raven_flash'][$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * Stores one flash list payload in session.
     *
     * @param array<int, string> $values
     */
    private function flashList(string $key, array $values): void
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

        $_SESSION['_raven_flash_list'][$key] = $normalized;
    }

    /**
     * Pulls and removes one flash list payload.
     *
     * @return array<int, string>|null
     */
    private function pullFlashList(string $key): ?array
    {
        $value = $_SESSION['_raven_flash_list'][$key] ?? null;
        unset($_SESSION['_raven_flash_list'][$key]);
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
     * Normalizes bulk-selection id arrays from list forms.
     *
     * @param array<string, mixed> $post
     * @param string $key POST field name containing id array payload
     * @return array<int>
     */
    private function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        /** @var mixed $rawIds */
        $rawIds = $post[$key] ?? [];
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            // Ignore invalid ids rather than failing the whole bulk action.
            $parsed = $this->input->int($rawId, 1);
            if ($parsed !== null) {
                $ids[] = $parsed;
            }
        }

        // De-duplicate and sort for deterministic processing/order-independent tests.
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Normalizes Media tab metadata payload for gallery images.
     *
     * @param mixed $raw
     * @return array<int, array{
     *   alt_text: string,
     *   title_text: string,
     *   caption: string,
     *   credit: string,
     *   license: string,
     *   focal_x: float|null,
     *   focal_y: float|null,
     *   sort_order: int,
     *   is_cover: bool,
     *   is_preview: bool,
     *   include_in_gallery: bool
     * }>
     */
    private function normalizeGalleryImageUpdates(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $updates = [];

        foreach ($raw as $rawImageId => $rawData) {
            $imageId = $this->input->int($rawImageId, 1);
            if ($imageId === null || !is_array($rawData)) {
                continue;
            }

            $sortOrder = $this->input->int($rawData['sort_order'] ?? null, 1) ?? 1;
            // Media editor uses one shared field for both alt/title values.
            $sharedAltTitle = $this->input->text($rawData['alt_text'] ?? ($rawData['title_text'] ?? null), 255);

            $updates[$imageId] = [
                'alt_text' => $sharedAltTitle,
                'title_text' => $sharedAltTitle,
                'caption' => $this->input->text($rawData['caption'] ?? null, 2000),
                'credit' => $this->input->text($rawData['credit'] ?? null, 255),
                'license' => $this->input->text($rawData['license'] ?? null, 255),
                'focal_x' => $this->normalizeNullableFloat($rawData['focal_x'] ?? null, 0.0, 100.0),
                'focal_y' => $this->normalizeNullableFloat($rawData['focal_y'] ?? null, 0.0, 100.0),
                'sort_order' => $sortOrder,
                'is_cover' => isset($rawData['is_cover']) && (string) $rawData['is_cover'] === '1',
                'is_preview' => isset($rawData['is_preview']) && (string) $rawData['is_preview'] === '1',
                'include_in_gallery' => isset($rawData['include_in_gallery']) && (string) $rawData['include_in_gallery'] === '1',
            ];
        }

        ksort($updates);

        if ($updates === []) {
            return [];
        }

        // Canonicalize single-select flags so malicious/manual posts cannot store multiple cover/preview rows.
        $orderedImageIds = array_keys($updates);
        usort($orderedImageIds, static function (int $a, int $b) use ($updates): int {
            $aSort = (int) ($updates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($updates[$b]['sort_order'] ?? 1);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        $previewWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($updates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $updates[$imageId]['is_cover'] = false;
                }
            }

            if (!empty($updates[$imageId]['is_preview'])) {
                if ($previewWinner === null) {
                    $previewWinner = $imageId;
                } else {
                    $updates[$imageId]['is_preview'] = false;
                }
            }
        }

        return $updates;
    }

    /**
     * Normalizes one `$_FILES` upload group into a list of upload entries.
     *
     * Supports both a single file input and `multiple` file inputs.
     *
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadedFileSet(mixed $raw): array
    {
        if (!is_array($raw) || !isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error'], $raw['size'])) {
            return [];
        }

        $uploads = [];

        // Recursively flatten upload trees because browsers can submit nested arrays
        // when multiple file inputs share the same `name[]` and `multiple` is enabled.
        $this->flattenUploadedFileNodes(
            $raw['name'],
            $raw['type'],
            $raw['tmp_name'],
            $raw['error'],
            $raw['size'],
            $uploads
        );

        return array_values($uploads);
    }

    /**
     * Walks nested upload arrays and extracts only real selected file entries.
     *
     * @param mixed $nameNode
     * @param mixed $typeNode
     * @param mixed $tmpNameNode
     * @param mixed $errorNode
     * @param mixed $sizeNode
     * @param array<int, array<string, mixed>> $uploads
     */
    private function flattenUploadedFileNodes(
        mixed $nameNode,
        mixed $typeNode,
        mixed $tmpNameNode,
        mixed $errorNode,
        mixed $sizeNode,
        array &$uploads
    ): void {
        if (is_array($nameNode)) {
            foreach ($nameNode as $index => $childNameNode) {
                $childTypeNode = is_array($typeNode) && array_key_exists($index, $typeNode) ? $typeNode[$index] : null;
                $childTmpNameNode = is_array($tmpNameNode) && array_key_exists($index, $tmpNameNode) ? $tmpNameNode[$index] : null;
                $childErrorNode = is_array($errorNode) && array_key_exists($index, $errorNode) ? $errorNode[$index] : UPLOAD_ERR_NO_FILE;
                $childSizeNode = is_array($sizeNode) && array_key_exists($index, $sizeNode) ? $sizeNode[$index] : null;

                $this->flattenUploadedFileNodes(
                    $childNameNode,
                    $childTypeNode,
                    $childTmpNameNode,
                    $childErrorNode,
                    $childSizeNode,
                    $uploads
                );
            }

            return;
        }

        // Missing/empty nodes are treated as "no file selected" and skipped.
        $error = is_array($errorNode) ? UPLOAD_ERR_NO_FILE : (int) $errorNode;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $name = is_array($nameNode) ? '' : trim((string) $nameNode);
        $tmpName = is_array($tmpNameNode) ? '' : trim((string) $tmpNameNode);
        if ($name === '' && $tmpName === '') {
            return;
        }

        $uploads[] = [
            'name' => $name,
            'type' => is_array($typeNode) ? '' : (string) $typeNode,
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => is_array($sizeNode) ? 0 : (int) $sizeNode,
        ];
    }
    /**
     * Returns enabled extension shortcodes insertable from the page editor.
     *
     * @return array<int, array{
     *   extension: string,
     *   label: string,
     *   shortcode: string
     * }>
     */
    private function pageEditorInsertableShortcodes(): array
    {
        $enabledMap = $this->loadExtensionStateMap();
        $items = $this->extensionProvidedShortcodesForEditor($enabledMap);

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $deduped = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['shortcode'] ?? '')));
            if ($key === '' || isset($deduped[$key])) {
                continue;
            }

            $deduped[$key] = $item;
        }

        return array_values($deduped);
    }

    /**
     * Loads extension-provided shortcode definitions for the page editor menu.
     *
     * Each extension may optionally define `private/ext/{name}/lib/shortcodes.php`
     * and return either:
     * - array<int, array{label: string, shortcode: string}>
     * - callable(): array<int, array{label: string, shortcode: string}>
     * - callable(array{extension: string, forms: callable(string): array<int, array{name: string, slug: string}>}):
     *   array<int, array{label: string, shortcode: string}>
     *
     * @param array<string, bool> $enabledMap
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    private function extensionProvidedShortcodesForEditor(array $enabledMap): array
    {
        $items = [];
        foreach ($enabledMap as $extensionName => $enabled) {
            if (!$enabled) {
                continue;
            }

            $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
            $manifest = $this->readExtensionManifest($extensionPath);
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            if (
                !($manifest['valid'] ?? false)
                || $isSystemType
                || !in_array($type, ['helper', 'plugin', 'module'], true)
            ) {
                continue;
            }

            $shortcodes = ExtensionRegistry::shortcodes(
                dirname(__DIR__, 3),
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                    'forms' => function (string $tableName): array {
                        return $this->taxonomy->listEnabledExtensionForms($tableName);
                    },
                ]
            );
            if ($shortcodes === null) {
                continue;
            }

            foreach ($shortcodes as $entry) {
                $label = $this->input->text((string) ($entry['label'] ?? ''), 180);
                $shortcode = trim((string) ($entry['shortcode'] ?? ''));
                if ($label === '' || $shortcode === '') {
                    continue;
                }
                $items[] = [
                    'extension' => (string) $extensionName,
                    'label' => $label,
                    'shortcode' => $shortcode,
                ];
            }
        }

        return $items;
    }

    /**
     * Discovers installed extensions from `private/ext/{name}/`.
     *
     * @return array<int, array{
     *   directory: string,
     *   type: string,
     *   panel_path: string,
     *   has_panel_routes: bool,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   author_url: string,
     *   homepage: string,
     *   valid: bool,
     *   invalid_reason: string,
     *   enabled: bool,
     *   is_stock: bool,
     *   can_delete: bool,
     *   delete_block_reason: string
     * }>
     */
    private function listExtensionsForPanel(): array
    {
        $this->ensureExtensionsDirectory();

        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        $entries = scandir($this->extensionsBasePath()) ?: [];
        $extensions = [];

        foreach ($entries as $entry) {
            // Ignore hidden/system files and keep extensions folder namespace explicit.
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (!$this->isSafeExtensionDirectoryName($entry)) {
                continue;
            }

            $extensionPath = $this->extensionsBasePath() . '/' . $entry;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $this->readExtensionManifest($extensionPath);
            $isValid = (bool) ($manifest['valid'] ?? false);
            $isEnabled = $isValid && !empty($enabledMap[$entry]);
            $hasPanelRoutes = is_file($extensionPath . '/lib/routes_panel.php');
            $isStock = $this->isStockExtensionDirectory($entry);
            $canDelete = !$isStock && !$isEnabled;
            $deleteBlockReason = '';
            if ($isStock) {
                $deleteBlockReason = 'Stock extension cannot be deleted.';
            } elseif ($isEnabled) {
                $deleteBlockReason = 'Disable extension before deleting.';
            }

            $extensions[] = [
                'directory' => $entry,
                'type' => (string) ($manifest['type'] ?? 'plugin'),
                'panel_path' => $hasPanelRoutes ? $entry : '',
                'has_panel_routes' => $hasPanelRoutes,
                'name' => $manifest['name'] !== '' ? $manifest['name'] : $entry,
                'version' => $manifest['version'],
                'description' => $manifest['description'],
                'author' => $manifest['author'],
                'author_url' => $manifest['author_url'],
                'homepage' => $manifest['homepage'],
                'valid' => $isValid,
                'invalid_reason' => (string) ($manifest['invalid_reason'] ?? ''),
                // Invalid extensions can never be active, even if stale state says otherwise.
                'enabled' => $isEnabled,
                'is_stock' => $isStock,
                'can_delete' => $canDelete,
                'delete_block_reason' => $deleteBlockReason,
            ];
        }

        // Keep extension lists deterministic for stable UI ordering.
        usort($extensions, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['directory'], (string) $b['directory']);
        });

        // Remove stale state entries for extension folders that no longer exist.
        $activeKeys = array_map(
            static fn (array $extension): string => !empty($extension['valid']) ? (string) $extension['directory'] : '',
            $extensions
        );
        $activeKeys = array_values(array_filter($activeKeys, static fn (string $value): bool => $value !== ''));
        $activeKeyMap = array_flip($activeKeys);
        $cleanedEnabledMap = array_intersect_key($enabledMap, $activeKeyMap);
        $cleanedPermissionMap = array_intersect_key($permissionMap, $activeKeyMap);
        $cleanedPermissionBitsMap = array_intersect_key($permissionBitsMap, $activeKeyMap);
        if (
            $cleanedEnabledMap !== $enabledMap
            || $cleanedPermissionMap !== $permissionMap
            || $cleanedPermissionBitsMap !== $permissionBitsMap
        ) {
            $this->saveExtensionState($cleanedEnabledMap, $cleanedPermissionMap, $cleanedPermissionBitsMap);
        }

        return $extensions;
    }

    /**
     * Reads optional extension metadata from `ext.json`.
     *
     * @return array{
     *   valid: bool,
     *   invalid_reason: string,
     *   type: string,
     *   panel_path: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   author_url: string,
     *   homepage: string,
     *   permission_levels: array<int, array{key: string, label: string}>,
     *   default_permission_level: string
     * }
     */
    private function readExtensionManifest(string $extensionPath): array
    {
        $defaultPermissionLevels = $this->defaultExtensionPermissionLevels('Extension');
        $defaultPermissionLevel = (string) ($defaultPermissionLevels[0]['key'] ?? 'access');
        $directorySlug = trim((string) basename($extensionPath));
        if (!$this->isSafeExtensionDirectoryName($directorySlug)) {
            $directorySlug = '';
        }

        $manifestPath = rtrim($extensionPath, '/') . '/ext.json';
        if (!is_file($manifestPath)) {
            return [
                'valid' => false,
                'invalid_reason' => 'Missing required ext.json manifest.',
                'type' => 'plugin',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'author_url' => '',
                'homepage' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false || trim($raw) === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json is empty or unreadable.',
                'type' => 'plugin',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'author_url' => '',
                'homepage' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json must contain a JSON object.',
                'type' => 'plugin',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'author_url' => '',
                'homepage' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $name = $this->input->text((string) ($decoded['name'] ?? ''), 120);
        if ($name === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json must include a non-empty "name" value.',
                'type' => 'plugin',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'author_url' => '',
                'homepage' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $type = strtolower(trim((string) ($decoded['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }
        $permissionLevels = $this->normalizeExtensionPermissionLevels($decoded['panel_permissions'] ?? null, $name);
        $defaultPermissionLevel = (string) ($permissionLevels[0]['key'] ?? 'access');
        $panelPath = $directorySlug;
        $author = $this->input->text((string) ($decoded['author'] ?? ''), 120);
        $authorUrlRaw = trim((string) ($decoded['author_url'] ?? ''));
        $authorUrl = '';
        if ($authorUrlRaw !== '' && filter_var($authorUrlRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($authorUrlRaw, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                $authorUrl = $authorUrlRaw;
            }
        }
        $homepageRaw = trim((string) ($decoded['docs_url'] ?? ($decoded['homepage'] ?? '')));
        $homepage = '';
        if ($homepageRaw !== '' && filter_var($homepageRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                $homepage = $homepageRaw;
            }
        }

        $typeContractError = $this->extensionTypeContractError($extensionPath, $type);
        if ($typeContractError !== null) {
            return [
                'valid' => false,
                'invalid_reason' => $typeContractError,
                'type' => $type,
                'panel_path' => $panelPath,
                'name' => $name,
                'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                'author' => $author,
                'author_url' => $authorUrl,
                'homepage' => $homepage,
                'permission_levels' => $permissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        if ($directorySlug !== '') {
            $shortcodesError = ExtensionRegistry::shortcodesValidationError(
                dirname(__DIR__, 3),
                $directorySlug,
                [
                    'extension' => $directorySlug,
                    'forms' => function (string $tableName): array {
                        return $this->taxonomy->listEnabledExtensionForms($tableName);
                    },
                ]
            );
            if ($shortcodesError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid lib/shortcodes.php: ' . $shortcodesError,
                    'type' => $type,
                    'panel_path' => $panelPath,
                    'name' => $name,
                    'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                    'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                    'author' => $author,
                    'author_url' => $authorUrl,
                    'homepage' => $homepage,
                    'permission_levels' => $permissionLevels,
                    'default_permission_level' => $defaultPermissionLevel,
                ];
            }

            $fieldsError = ExtensionRegistry::fieldsValidationError(
                dirname(__DIR__, 3),
                $directorySlug,
                [
                    'extension' => $directorySlug,
                ]
            );
            if ($fieldsError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid lib/fields.php: ' . $fieldsError,
                    'type' => $type,
                    'panel_path' => $panelPath,
                    'name' => $name,
                    'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                    'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                    'author' => $author,
                    'author_url' => $authorUrl,
                    'homepage' => $homepage,
                    'permission_levels' => $permissionLevels,
                    'default_permission_level' => $defaultPermissionLevel,
                ];
            }
        }

        return [
            'valid' => true,
            'invalid_reason' => '',
            'type' => $type,
            'panel_path' => $panelPath,
            'name' => $name,
            'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
            'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
            'author' => $author,
            'author_url' => $authorUrl,
            'homepage' => $homepage,
            'permission_levels' => $permissionLevels,
            'default_permission_level' => $defaultPermissionLevel,
        ];
    }

    /**
     * Returns default extension permission levels for manifests without explicit levels.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function defaultExtensionPermissionLevels(string $extensionName): array
    {
        $label = trim($extensionName);
        $label = $label !== '' ? 'Access ' . $label : 'Access';

        return [[
            'key' => 'access',
            'label' => $label,
        ]];
    }

    /**
     * Normalizes extension-declared panel permission levels from ext.json.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function normalizeExtensionPermissionLevels(mixed $rawLevels, string $extensionName): array
    {
        $normalized = [];
        if (is_array($rawLevels)) {
            foreach ($rawLevels as $key => $entry) {
                $levelKey = '';
                $label = '';
                if (is_array($entry)) {
                    $levelKey = strtolower(trim((string) ($entry['key'] ?? '')));
                    $label = trim((string) ($entry['label'] ?? ''));
                } elseif (is_string($key)) {
                    $levelKey = strtolower(trim($key));
                    $label = trim((string) $entry);
                }

                if ($levelKey === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $label = $this->input->text($label, 80);
                if ($label === '') {
                    continue;
                }

                $normalized[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $label,
                ];
                if (count($normalized) >= 16) {
                    break;
                }
            }
        }

        if ($normalized === []) {
            return $this->defaultExtensionPermissionLevels($extensionName);
        }

        return array_values($normalized);
    }

    /**
     * Validates extension type capability boundaries against on-disk files.
     */
    private function extensionTypeContractError(string $extensionPath, string $type): ?string
    {
        $hasPublicRoutes = is_file(rtrim($extensionPath, '/') . '/lib/routes_public.php');
        $hasShortcodes = is_file(rtrim($extensionPath, '/') . '/lib/shortcodes.php');
        $hasFields = is_file(rtrim($extensionPath, '/') . '/lib/fields.php');

        if ($hasPublicRoutes && $type !== 'module') {
            return 'Only module extensions may define lib/routes_public.php.';
        }
        if ($hasShortcodes && !in_array($type, ['helper', 'plugin', 'module'], true)) {
            return 'Only helper/plugin/module extensions may define lib/shortcodes.php.';
        }
        if ($hasFields && !in_array($type, ['content', 'plugin', 'module'], true)) {
            return 'Only content/plugin/module extensions may define lib/fields.php.';
        }

        return null;
    }

    /**
     * Returns absolute path to `private/ext`.
     */
    private function extensionsBasePath(): string
    {
        return dirname(__DIR__, 2) . '/ext';
    }

    /**
     * Returns absolute path to extension state persistence file.
     */
    private function extensionsStateFilePath(): string
    {
        return $this->extensionsBasePath() . '/.state.php';
    }

    /**
     * Ensures extension base directory exists.
     */
    private function ensureExtensionsDirectory(): void
    {
        $basePath = $this->extensionsBasePath();
        if (is_dir($basePath)) {
            return;
        }

        if (!mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Failed to create private/ext directory.');
        }
    }

    /**
     * Loads extension enablement + permission-mask state from disk.
     *
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>,
     *   permission_bits: array<string, array<string, int>>
     * }
     */
    private function loadExtensionStateData(): array
    {
        $statePath = $this->extensionsStateFilePath();
        if (!is_file($statePath)) {
            return [
                'enabled' => [],
                'permissions' => [],
                'permission_bits' => [],
            ];
        }

        // Force fresh reads when PHP opcache uses delayed timestamp revalidation.
        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $loaded */
        $loaded = require $statePath;
        if (!is_array($loaded)) {
            return [
                'enabled' => [],
                'permissions' => [],
                'permission_bits' => [],
            ];
        }

        /** @var mixed $rawEnabled */
        $rawEnabled = array_key_exists('enabled', $loaded) ? $loaded['enabled'] : $loaded;
        if (!array_key_exists('enabled', $loaded) && array_key_exists('permissions', $loaded)) {
            $rawEnabled = [];
        }
        if (!is_array($rawEnabled)) {
            $rawEnabled = [];
        }

        /** @var mixed $rawPermissions */
        $rawPermissions = $loaded['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            $rawPermissions = [];
        }
        /** @var mixed $rawPermissionBits */
        $rawPermissionBits = $loaded['permission_bits'] ?? [];
        if (!is_array($rawPermissionBits)) {
            $rawPermissionBits = [];
        }

        $enabled = [];
        foreach ($rawEnabled as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            if ((bool) $isEnabled) {
                $enabled[$directory] = true;
            }
        }

        $permissions = [];
        foreach ($rawPermissions as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if ($bit <= 0) {
                continue;
            }

            $permissions[$directory] = $bit;
        }

        $permissionBits = [];
        foreach ($rawPermissionBits as $name => $levelsRaw) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) !== 1) {
                    continue;
                }

                $bit = (int) $rawBit;
                if ($bit <= 0) {
                    continue;
                }

                $normalizedLevels[$level] = $bit;
            }

            if ($normalizedLevels === []) {
                continue;
            }

            ksort($normalizedLevels);
            $permissionBits[$directory] = $normalizedLevels;
        }

        ksort($enabled);
        ksort($permissions);
        ksort($permissionBits);

        return [
            'enabled' => $enabled,
            'permissions' => $permissions,
            'permission_bits' => $permissionBits,
        ];
    }

    /**
     * Loads enabled extension map from disk.
     *
     * @return array<string, bool>
     */
    private function loadExtensionStateMap(): array
    {
        return $this->loadExtensionStateData()['enabled'];
    }

    /**
     * Loads required panel-side permission bit map per extension.
     *
     * @return array<string, int>
     */
    private function loadExtensionPermissionMap(): array
    {
        return $this->loadExtensionStateData()['permissions'];
    }

    /**
     * Loads extension permission-bit map per extension level.
     *
     * @return array<string, array<string, int>>
     */
    private function loadExtensionPermissionBitsMap(): array
    {
        return $this->loadExtensionStateData()['permission_bits'];
    }

    /**
     * Saves enabled extension map to `private/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     */
    private function saveExtensionStateMap(array $enabledMap): void
    {
        $permissionMap = $this->loadExtensionPermissionMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * Saves required extension permission-bit map to `private/ext/.state.php`.
     *
     * @param array<string, int> $permissionMap
     */
    private function saveExtensionPermissionMap(array $permissionMap): void
    {
        $enabledMap = $this->loadExtensionStateMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * Saves extension permission-bit map per extension level.
     *
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    private function saveExtensionPermissionBitsMap(array $permissionBitsMap): void
    {
        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * Saves extension enablement + permission-mask state to `private/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     * @param array<string, int> $permissionMap
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    private function saveExtensionState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        if ($permissionBitsMap === []) {
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        }

        $filteredEnabled = [];
        foreach ($enabledMap as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !$isEnabled) {
                continue;
            }

            $filteredEnabled[$directory] = true;
        }
        ksort($filteredEnabled);

        $filteredPermissions = [];
        foreach ($permissionMap as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if ($bit <= 0) {
                continue;
            }

            $filteredPermissions[$directory] = $bit;
        }
        ksort($filteredPermissions);

        $filteredPermissionBits = [];
        foreach ($permissionBitsMap as $name => $levelsRaw) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) !== 1) {
                    continue;
                }

                $bit = (int) $rawBit;
                if ($bit <= 0) {
                    continue;
                }

                $normalizedLevels[$level] = $bit;
            }

            if ($normalizedLevels === []) {
                continue;
            }

            ksort($normalizedLevels);
            $filteredPermissionBits[$directory] = $normalizedLevels;
        }
        ksort($filteredPermissionBits);

        $export = var_export([
            'enabled' => $filteredEnabled,
            'permissions' => $filteredPermissions,
            'permission_bits' => $filteredPermissionBits,
        ], true);
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * RAVEN CMS\n";
        $content .= " * ~/private/ext/.state.php\n";
        $content .= " * Persisted extension enablement map and permission settings managed by panel.\n";
        $content .= " * Docs: https://raven.lanterns.io\n";
        $content .= " */\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return " . $export . ";\n";

        $written = file_put_contents($this->extensionsStateFilePath(), $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to persist extension state.');
        }

        // Ensure immediate visibility of state changes on the next request.
        $statePath = $this->extensionsStateFilePath();
        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }
    }

    /**
     * Returns extension permission metadata + assigned bits for matching directories.
     *
     * @param array<int, string> $directoryFilter
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }>
     */
    public function extensionPanelPermissionMapForDirectories(array $directoryFilter = []): array
    {
        $catalog = $this->extensionPermissionCatalog($directoryFilter);
        $bitMap = $this->ensureExtensionPermissionBits($catalog);
        $result = [];

        foreach ($catalog as $directory => $meta) {
            $levels = [];
            foreach ($meta['levels'] as $level) {
                $levelKey = (string) ($level['key'] ?? '');
                if ($levelKey === '') {
                    continue;
                }

                $bit = (int) (($bitMap[$directory][$levelKey] ?? 0));
                if ($bit <= 0) {
                    continue;
                }

                $levels[] = [
                    'key' => $levelKey,
                    'label' => (string) ($level['label'] ?? $levelKey),
                    'bit' => $bit,
                ];
            }

            if ($levels === []) {
                continue;
            }

            $result[$directory] = [
                'name' => (string) ($meta['name'] ?? $directory),
                'type' => (string) ($meta['type'] ?? 'plugin'),
                'default_level' => (string) ($meta['default_level'] ?? ($levels[0]['key'] ?? 'access')),
                'levels' => $levels,
            ];
        }

        ksort($result);
        return $result;
    }

    /**
     * Discovers extension permission metadata for helper/content/plugin/module panel routes.
     *
     * @param array<int, string> $directoryFilter
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }>
     */
    private function extensionPermissionCatalog(array $directoryFilter = []): array
    {
        $this->ensureExtensionsDirectory();
        $filter = [];
        foreach ($directoryFilter as $directory) {
            $normalized = strtolower(trim((string) $directory));
            if ($this->isSafeExtensionDirectoryName($normalized)) {
                $filter[$normalized] = true;
            }
        }

        $entries = scandir($this->extensionsBasePath()) ?: [];
        $catalog = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!$this->isSafeExtensionDirectoryName($entry)) {
                continue;
            }
            if ($filter !== [] && !isset($filter[$entry])) {
                continue;
            }

            $extensionPath = $this->extensionsBasePath() . '/' . $entry;
            if (!is_dir($extensionPath) || !is_file($extensionPath . '/lib/routes_panel.php')) {
                continue;
            }

            $manifest = $this->readExtensionManifest($extensionPath);
            if (!($manifest['valid'] ?? false)) {
                continue;
            }

            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            if ($isSystemType || !in_array($type, ['helper', 'content', 'plugin', 'module'], true)) {
                continue;
            }

            $levels = is_array($manifest['permission_levels'] ?? null)
                ? $manifest['permission_levels']
                : $this->defaultExtensionPermissionLevels((string) ($manifest['name'] ?? $entry));
            $normalizedLevels = [];
            foreach ($levels as $level) {
                if (!is_array($level)) {
                    continue;
                }

                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $levelKey) !== 1) {
                    continue;
                }

                $label = trim((string) ($level['label'] ?? ''));
                if ($label === '') {
                    $label = ucwords(str_replace(['-', '_'], ' ', $levelKey));
                }

                $normalizedLevels[$levelKey] = [
                    'key' => $levelKey,
                    'label' => $this->input->text($label, 80),
                ];
            }
            if ($normalizedLevels === []) {
                $normalizedLevels = [];
                foreach ($this->defaultExtensionPermissionLevels((string) ($manifest['name'] ?? $entry)) as $defaultLevel) {
                    $normalizedLevels[(string) $defaultLevel['key']] = $defaultLevel;
                }
            }

            $defaultLevel = strtolower(trim((string) ($manifest['default_permission_level'] ?? '')));
            if ($defaultLevel === '' || !isset($normalizedLevels[$defaultLevel])) {
                $firstLevel = array_values($normalizedLevels)[0] ?? ['key' => 'access'];
                $defaultLevel = (string) ($firstLevel['key'] ?? 'access');
            }

            $catalog[$entry] = [
                'name' => (string) ($manifest['name'] ?? $entry),
                'type' => $type,
                'default_level' => $defaultLevel,
                'levels' => array_values($normalizedLevels),
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * Ensures stable permission-bit assignments for extension levels.
     *
     * @param array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string}>
     * }> $catalog
     * @return array<string, array<string, int>>
     */
    private function ensureExtensionPermissionBits(array $catalog): array
    {
        $existing = $this->loadExtensionPermissionBitsMap();
        $normalized = [];
        $usedBits = [];
        foreach ($existing as $directory => $levels) {
            $normalized[$directory] = [];
            foreach ($levels as $levelKey => $bit) {
                $candidateBit = (int) $bit;
                if ($candidateBit <= 0 || !$this->isPowerOfTwoBit($candidateBit) || isset($usedBits[$candidateBit])) {
                    continue;
                }

                $normalized[$directory][(string) $levelKey] = $candidateBit;
                $usedBits[$candidateBit] = true;
            }
            if ($normalized[$directory] === []) {
                unset($normalized[$directory]);
            }
        }

        $changed = $normalized !== $existing;
        foreach ($catalog as $directory => $meta) {
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                $levelKey = strtolower(trim((string) ($level['key'] ?? '')));
                if ($levelKey === '') {
                    continue;
                }

                $assignedBit = (int) ($normalized[$directory][$levelKey] ?? 0);
                if ($assignedBit > 0 && $this->isPowerOfTwoBit($assignedBit) && isset($usedBits[$assignedBit])) {
                    continue;
                }

                $nextBit = $this->nextAvailableExtensionPermissionBit($usedBits);
                $normalized[$directory][$levelKey] = $nextBit;
                $usedBits[$nextBit] = true;
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveExtensionPermissionBitsMap($normalized);
        }

        return $normalized;
    }

    /**
     * Returns next available extension permission bit.
     *
     * @param array<int, bool> $usedBits
     */
    private function nextAvailableExtensionPermissionBit(array $usedBits): int
    {
        $bit = PanelAccess::EXTENSION_PERMISSION_START;
        while (isset($usedBits[$bit])) {
            if ($bit > intdiv(PHP_INT_MAX, 2)) {
                throw new \RuntimeException('No free extension permission bits remain.');
            }

            $bit *= 2;
        }

        return $bit;
    }

    /**
     * Returns true when one integer value is power-of-two.
     */
    private function isPowerOfTwoBit(int $bit): bool
    {
        if ($bit <= 0) {
            return false;
        }

        return ($bit & ($bit - 1)) === 0;
    }

    /**
     * Returns canonical stock extension directory names that are protected from deletion.
     *
     * @return array<int, string>
     */
    private function stockExtensionDirectories(): array
    {
        return ['contact', 'database', 'phpinfo', 'signups'];
    }

    /**
     * Returns true when one extension directory is part of the stock bundle.
     */
    private function isStockExtensionDirectory(string $directoryName): bool
    {
        $normalized = strtolower(trim($directoryName));
        return in_array($normalized, $this->stockExtensionDirectories(), true);
    }

    /**
     * Validates extension directory names for filesystem-safe usage.
     */
    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }

    /**
     * Derives one extension directory name from archive filename.
     */
    private function extensionNameFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 120));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        if ($base === '' || !$this->isSafeExtensionDirectoryName($base)) {
            return null;
        }

        return $base;
    }

    /**
     * Validates ZIP entry paths to prevent zip-slip traversal.
     */
    private function isSafeZipEntryPath(string $entryName): bool
    {
        $path = str_replace('\\', '/', trim($entryName));
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\//', $path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            // Empty segments can happen on directory entries that end with `/`.
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when directory contains at least one file or child directory.
     */
    private function directoryHasFiles(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Removes a directory tree recursively; used for failed extension uploads.
     */
    private function removeDirectoryRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    /**
     * Creates one temporary ZIP archive from a directory tree and returns archive path.
     */
    private function buildZipArchiveFromDirectory(string $sourceDirectory, string $archiveRoot): string
    {
        $sourceRoot = realpath($sourceDirectory);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Source directory could not be resolved.');
        }

        $sanitizedRoot = preg_replace('/[^a-z0-9._-]+/i', '-', $archiveRoot) ?? '';
        $sanitizedRoot = trim($sanitizedRoot, '-_.');
        if ($sanitizedRoot === '') {
            $sanitizedRoot = 'package';
        }

        $tmpArchivePath = $this->allocateTemporaryArchivePath();

        $zip = new ZipArchive();
        $opened = $zip->open($tmpArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmpArchivePath);
            throw new \RuntimeException('Failed to initialize ZIP archive.');
        }

        try {
            if (!$zip->addEmptyDir($sanitizedRoot)) {
                throw new \RuntimeException('Failed to initialize ZIP root directory.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                $sourcePath = $item->getPathname();
                $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
                if ($relativePath === '') {
                    continue;
                }

                $zipPath = $sanitizedRoot . '/' . str_replace('\\', '/', $relativePath);
                if ($item->isDir()) {
                    if (!$zip->addEmptyDir($zipPath)) {
                        throw new \RuntimeException('Failed to add directory "' . $relativePath . '" to ZIP archive.');
                    }
                    continue;
                }

                if (!$zip->addFile($sourcePath, $zipPath)) {
                    throw new \RuntimeException('Failed to add file "' . $relativePath . '" to ZIP archive.');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($tmpArchivePath);
            throw new \RuntimeException($exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to build ZIP archive.');
        }

        $zip->close();
        return $tmpArchivePath;
    }

    /**
     * Allocates one writable temporary file path for ZIP exports.
     */
    private function allocateTemporaryArchivePath(): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $candidateDirectories = [
            (string) sys_get_temp_dir(),
            $projectRoot . '/private/tmp',
            $projectRoot . '/private/tmp/exports',
        ];

        foreach ($candidateDirectories as $candidateDirectory) {
            $directory = trim($candidateDirectory);
            if ($directory === '') {
                continue;
            }

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                continue;
            }

            if (!is_writable($directory)) {
                continue;
            }

            $path = @tempnam($directory, 'rvn-export-');
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        throw new \RuntimeException('Failed to allocate temporary archive path.');
    }

    /**
     * Streams one local file as a download and removes it afterwards.
     */
    private function streamDownloadFile(string $path, string $downloadFilename, string $contentType): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            @unlink($path);
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        $size = (int) @filesize($path);
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        if (fpassthru($stream) === false) {
            http_response_code(500);
        }

        fclose($stream);
        @unlink($path);
    }

    /**
     * Maps PHP upload error codes into extension-upload messages.
     */
    private function extensionUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Extension archive exceeds server upload limits.',
            UPLOAD_ERR_PARTIAL => 'Extension archive upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose a ZIP file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded extension archive.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the extension upload.',
            default => 'Extension upload failed with an unknown error.',
        };
    }

    /**
     * Creates a minimal extension scaffold on disk.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   author_url: string
     * } $meta
     */
    private function createExtensionSkeleton(
        string $extensionPath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false
    ): void
    {
        $type = strtolower(trim((string) ($meta['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }
        $generatesPanelRoutes = true;
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['helper', 'plugin', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'plugin', 'module'], true);
        if (!mkdir($extensionPath, 0700, true) && !is_dir($extensionPath)) {
            throw new \RuntimeException('Failed to create extension directory.');
        }

        $libPath = $extensionPath . '/lib';
        if (!mkdir($libPath, 0700, true) && !is_dir($libPath)) {
            throw new \RuntimeException('Failed to create extension lib directory.');
        }

        $visPath = $extensionPath . '/vis';
        if ($generatesPanelRoutes && !mkdir($visPath, 0700, true) && !is_dir($visPath)) {
            throw new \RuntimeException('Failed to create extension vis directory.');
        }

        $manifestPath = $extensionPath . '/ext.json';
        $bootstrapPath = $extensionPath . '/ext.php';
        $routesPath = $extensionPath . '/lib/routes_panel.php';
        $publicRoutesPath = $extensionPath . '/lib/routes_public.php';
        $schemaPath = $extensionPath . '/lib/schema.php';
        $shortcodesPath = $extensionPath . '/lib/shortcodes.php';
        $fieldsPath = $extensionPath . '/lib/fields.php';
        $composerPath = $extensionPath . '/composer.json';
        $panelIndexViewPath = $visPath . '/panel_index.php';
        $publicIndexViewPath = $visPath . '/public_index.php';
        $agentsFilePath = $extensionPath . '/AGENTS.md';

        $manifestContent = $this->renderExtensionManifestJson($meta);
        $bootstrapContent = $this->renderExtensionBootstrapSkeleton($meta);
        $schemaContent = $this->renderExtensionSchemaSkeleton($meta);
        $shortcodesContent = $this->renderExtensionShortcodesSkeleton($meta);
        $fieldsContent = $this->renderExtensionFieldsSkeleton($meta);
        $publicViewContent = $this->renderExtensionPublicViewSkeleton($meta);
        $agentsContent = $this->renderExtensionAgentsSkeleton($meta);
        $composerContent = $this->renderExtensionComposerSkeleton($meta);

        if (file_put_contents($manifestPath, $manifestContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write ext.json.');
        }

        if (file_put_contents($bootstrapPath, $bootstrapContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write ext.php.');
        }

        if (file_put_contents($schemaPath, $schemaContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/schema.php.');
        }

        if ($generatesShortcodes && file_put_contents($shortcodesPath, $shortcodesContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/shortcodes.php.');
        }

        if ($generatesContentBlocks && file_put_contents($fieldsPath, $fieldsContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/fields.php.');
        }

        if ($generateComposerFile && file_put_contents($composerPath, $composerContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write composer.json.');
        }

        if ($generatesPanelRoutes) {
            $routesContent = $this->renderExtensionRoutesSkeleton($meta);
            $viewContent = $this->renderExtensionPanelViewSkeleton($meta);

            if (file_put_contents($routesPath, $routesContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write lib/routes_panel.php.');
            }

            if (file_put_contents($panelIndexViewPath, $viewContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write vis/panel_index.php.');
            }
        }
        if ($generatesPublicRoutes) {
            $publicRoutesContent = $this->renderExtensionPublicRoutesSkeleton($meta);
            if (file_put_contents($publicRoutesPath, $publicRoutesContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write lib/routes_public.php.');
            }
            if (file_put_contents($publicIndexViewPath, $publicViewContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write vis/public_index.php.');
            }
        }
        if ($generateAgentsFile && file_put_contents($agentsFilePath, $agentsContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write AGENTS.md.');
        }

        // Keep scaffold file modes aligned with private-directory policy.
        @chmod($extensionPath, 0700);
        @chmod($manifestPath, 0600);
        @chmod($bootstrapPath, 0600);
        @chmod($schemaPath, 0600);
        if ($generatesShortcodes) {
            @chmod($shortcodesPath, 0600);
        }
        if ($generatesContentBlocks) {
            @chmod($fieldsPath, 0600);
        }
        @chmod($libPath, 0700);
        if ($generatesPanelRoutes) {
            @chmod($visPath, 0700);
            @chmod($routesPath, 0600);
            @chmod($panelIndexViewPath, 0600);
        }
        if ($generatesPublicRoutes) {
            @chmod($publicRoutesPath, 0600);
            @chmod($publicIndexViewPath, 0600);
        }
        if ($generateAgentsFile) {
            @chmod($agentsFilePath, 0600);
        }
        if ($generateComposerFile) {
            @chmod($composerPath, 0600);
        }
    }

    /**
     * Returns JSON content for one generated extension manifest.
     *
     * @param array{
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   author_url: string
     * } $meta
     */
    private function renderExtensionManifestJson(array $meta): string
    {
        $manifest = [
            'name' => $meta['name'],
            'version' => $meta['version'],
            'description' => $meta['description'],
            'type' => $meta['type'],
        ];

        if ($meta['author'] !== '') {
            $manifest['author'] = $meta['author'];
        }

        if ($meta['author_url'] !== '') {
            $manifest['author_url'] = $meta['author_url'];
        }

        if ($meta['homepage'] !== '') {
            $manifest['docs_url'] = $meta['homepage'];
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to encode extension manifest JSON.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `composer.json` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   description: string,
     *   author: string,
     *   author_url: string
     * } $meta
     */
    private function renderExtensionComposerSkeleton(array $meta): string
    {
        $directory = strtolower(trim((string) ($meta['directory'] ?? 'extension')));
        $directory = preg_replace('/[^a-z0-9._-]+/', '-', $directory) ?? 'extension';
        $directory = trim($directory, '-');
        if ($directory === '') {
            $directory = 'extension';
        }

        $composer = [
            'name' => 'raven/' . $directory,
            'description' => trim((string) ($meta['description'] ?? '')) !== ''
                ? (string) $meta['description']
                : ((string) ($meta['name'] ?? 'Raven Extension') . ' extension for Raven CMS.'),
            'type' => 'library',
            'require' => new \stdClass(),
        ];

        $authorName = trim((string) ($meta['author'] ?? ''));
        $authorUrl = trim((string) ($meta['author_url'] ?? ''));
        if ($authorName !== '' || $authorUrl !== '') {
            $author = [];
            if ($authorName !== '') {
                $author['name'] = $authorName;
            }
            if ($authorUrl !== '') {
                $author['homepage'] = $authorUrl;
            }
            $composer['authors'] = [$author];
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to encode composer.json scaffold.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `ext.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionBootstrapSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $directoryLiteral = var_export($meta['directory'], true);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/ext.php
 * __NAME_DOC__ extension service bootstrap provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Registers extension-owned services into shared app container.
 *
 * @param array<string, mixed> $app
 */
return static function (array &$app): void {
    $extensionKey = __DIRECTORY_LITERAL__;

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $app['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawServices */
    $rawServices = $rawExtensionServices[$extensionKey] ?? [];
    if (!is_array($rawServices)) {
        $rawServices = [];
    }

    // Register extension services here, for example:
    // $rawServices['repository'] = new MyRepository(...);

    $rawExtensionServices[$extensionKey] = $rawServices;
    $app['extension_services'] = $rawExtensionServices;
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__', '__DIRECTORY_LITERAL__'],
            [$meta['directory'], $nameForDoc, $directoryLiteral],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/routes_panel.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionRoutesSkeleton(array $meta): string
    {
        $routePath = '/' . ltrim((string) ($meta['directory'] ?? ''), '/');
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $routePathLiteral = var_export($routePath, true);
        $sectionLiteral = var_export((string) ($meta['directory'] ?? ''), true);
        $directoryLiteral = var_export($meta['directory'], true);
        $nameLiteral = var_export($meta['name'], true);
        $typeLiteral = var_export($meta['type'], true);
        $panelPathLiteral = var_export((string) ($meta['directory'] ?? ''), true);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/routes_panel.php
 * __NAME_DOC__ extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold route registrar.

declare(strict_types=1);

use Raven\Lib\Routing\Router;

/**
 * Registers __NAME_DOC__ routes into the panel router.
 *
 * @param array{
 *   app: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $app */
    $app = (array) ($context['app'] ?? []);

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'light';

    if (!isset($app['view'], $app['config'], $app['csrf'])) {
        return;
    }

    $extensionRoot = dirname(__DIR__);
    $viewFile = $extensionRoot . '/vis/panel_index.php';
    $routePath = __ROUTE_PATH_LITERAL__;
    $section = __SECTION_LITERAL__;
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $extensionMeta = [
        'directory' => __DIRECTORY_LITERAL__,
        'name' => __NAME_LITERAL__,
        'type' => __TYPE_LITERAL__,
        'panel_path' => __PANEL_PATH_LITERAL__,
        'version' => '',
        'author' => '',
        'description' => '',
        'docs_url' => 'https://raven.lanterns.io',
    ];
    if (is_file($extensionManifestFile)) {
        $manifestRaw = file_get_contents($extensionManifestFile);
        if ($manifestRaw !== false && trim($manifestRaw) !== '') {
            /** @var mixed $manifestDecoded */
            $manifestDecoded = json_decode($manifestRaw, true);
            if (is_array($manifestDecoded)) {
                $manifestName = trim((string) ($manifestDecoded['name'] ?? ''));
                if ($manifestName !== '') {
                    $extensionMeta['name'] = $manifestName;
                }

                $extensionMeta['version'] = trim((string) ($manifestDecoded['version'] ?? ''));
                $extensionMeta['author'] = trim((string) ($manifestDecoded['author'] ?? ''));
                $extensionMeta['description'] = trim((string) ($manifestDecoded['description'] ?? ''));

                $docsUrlRaw = trim((string) ($manifestDecoded['docs_url'] ?? ($manifestDecoded['homepage'] ?? '')));
                if ($docsUrlRaw !== '' && filter_var($docsUrlRaw, FILTER_VALIDATE_URL) !== false) {
                    $docsScheme = strtolower((string) parse_url($docsUrlRaw, PHP_URL_SCHEME));
                    if (in_array($docsScheme, ['http', 'https'], true)) {
                        $extensionMeta['docs_url'] = $docsUrlRaw;
                    }
                }
            }
        }
    }

    /**
     * Renders extension body inside the shared panel layout.
     */
    $renderExtensionView = static function () use (
        $app,
        $viewFile,
        $currentUserTheme,
        $section,
        $extensionMeta
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Extension view template is missing.';
            return;
        }

        $site = [
            'name' => (string) $app['config']->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $app['config']->get('panel.path', 'panel'),
            'panel_brand_name' => (string) $app['config']->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $app['config']->get('panel.brand_logo', ''),
        ];
        $csrfField = $app['csrf']->field();

        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $app['view']->render('panel/wrapper', [
            'site' => $site,
            'csrfField' => $csrfField,
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', $routePath, static function () use ($requirePanelLogin, $renderExtensionView): void {
        $requirePanelLogin();
        $renderExtensionView();
    });
};
PHP;

        return str_replace(
            [
                '__DIRECTORY__',
                '__NAME_DOC__',
                '__DIRECTORY_LITERAL__',
                '__NAME_LITERAL__',
                '__TYPE_LITERAL__',
                '__PANEL_PATH_LITERAL__',
                '__ROUTE_PATH_LITERAL__',
                '__SECTION_LITERAL__',
            ],
            [
                $meta['directory'],
                $nameForDoc,
                $directoryLiteral,
                $nameLiteral,
                $typeLiteral,
                $panelPathLiteral,
                $routePathLiteral,
                $sectionLiteral,
            ],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/routes_public.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionPublicRoutesSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/routes_public.php
 * __NAME_DOC__ extension public route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Lib\Routing\Router;

/**
 * Registers extension routes into the public router.
 *
 * @param array{
 *   app: array<string, mixed>,
 *   controller: object,
 *   input: mixed,
 *   extensionDirectory: string
 * } $context
 */
return static function (Router $router, array $context): void {
    // Add public extension routes here. Keep routes extension-owned and avoid core edits.
    // Generated public view stub is available at: /vis/public_index.php
    // Example:
    // $router->add('GET', '/my-extension', static function () use ($context): void { ... });
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/schema.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionSchemaSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/schema.php
 * __NAME_DOC__ extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures extension-owned schema changes (tables/columns/indexes).
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): void {
    if (
        !isset($context['db'], $context['driver'], $context['table'])
        || !$context['db'] instanceof \PDO
        || !is_callable($context['table'])
    ) {
        return;
    }

    $db = $context['db'];
    $driver = (string) $context['driver'];
    $tableResolver = $context['table'];

    // Resolve one logical table to the active backend:
    // $table = $tableResolver('ext___DIRECTORY__');
    //
    // Keep schema operations idempotent. This provider runs on bootstrap/install.
    //
    // Example:
    // if ($driver === 'sqlite') {
    //     $db->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (...)');
    // }
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/shortcodes.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionShortcodesSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/shortcodes.php
 * __NAME_DOC__ extension shortcode provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Returns editor-insertable shortcode entries.
 *
 * @return array<int, array{label: string, shortcode: string}>
 */
return static function (): array {
    return [];
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/fields.php` scaffold content for content/plugin/module extensions.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionFieldsSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/fields.php
 * __NAME_DOC__ fields provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Returns page-editor body-block definitions exposed by this extension.
 *
 * Each row supports:
 * - slug: unique block key within this extension
 * - label: panel-visible menu label
 * - editor: tinymce|plaintext|autobr|markdown|markdown_file
 *
 * @return array<int, array{slug: string, label: string, editor: string}>
 */
return static function (): array {
    return [
        [
            'slug' => 'example',
            'label' => 'Example Content',
            'editor' => 'tinymce',
        ],
    ];
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `vis/public_index.php` scaffold content for module extensions.
     *
     * @param array{
     *   name: string,
     *   directory: string
     * } $meta
     */
    private function renderExtensionPublicViewSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/vis/public_index.php
 * __NAME_DOC__ extension public view scaffold.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
<section class="card">
    <div class="card-body">
        <h1 class="h4 mb-2">__NAME_DOC__</h1>
        <p class="mb-0 text-muted">Generated public extension view scaffold.</p>
    </div>
</section>
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `vis/panel_index.php` scaffold content.
     *
     * @param array{
     *   name: string,
     *   directory: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionPanelViewSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $type = strtolower(trim((string) ($meta['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['helper', 'plugin', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'plugin', 'module'], true);
        $starterFiles = [
            'private/ext/__DIRECTORY__/ext.php',
            'private/ext/__DIRECTORY__/lib/routes_panel.php',
            'private/ext/__DIRECTORY__/lib/schema.php',
        ];
        if ($generatesPublicRoutes) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/routes_public.php';
            $starterFiles[] = 'private/ext/__DIRECTORY__/vis/public_index.php';
        }
        if ($generatesShortcodes) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/shortcodes.php';
        }
        if ($generatesContentBlocks) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/fields.php';
        }
        $starterFiles[] = 'private/ext/__DIRECTORY__/vis/panel_index.php';
        $starterFilesListHtml = '';
        foreach ($starterFiles as $starterFile) {
            $starterFilesListHtml .= "\n            <li><code>" . $starterFile . "</code></li>";
        }
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/vis/panel_index.php
 * __NAME_DOC__ extension panel index view.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs_url?: string, directory?: string} $extensionMeta */
/** @var string $csrfField */

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Extension'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h1 class="mb-1">
                    <?= e($extensionName !== '' ? $extensionName : 'Extension') ?>
                    <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
                </h1>
                <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
                <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Generated starter extension page.') ?></p>
            </div>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-3">
            This is the generated starter page for <code><?= e((string) ($extensionMeta['directory'] ?? '')) ?></code>.
        </p>
        <p class="mb-2">Edit these generated files to build this extension:</p>
        <ul class="mb-0">
__STARTER_FILES_LIST__
        </ul>
    </div>
</div>
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__', '__STARTER_FILES_LIST__'],
            [$meta['directory'], $nameForDoc, $starterFilesListHtml],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `AGENTS.md` extension-local guidance.
     *
     * @param array{
     *   name: string,
     *   directory: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionAgentsSkeleton(array $meta): string
    {
        $name = trim(str_replace(["\r", "\n"], [' ', ' '], (string) ($meta['name'] ?? 'Extension')));
        if ($name === '') {
            $name = 'Extension';
        }

        $directory = trim((string) ($meta['directory'] ?? ''));
        $directory = $directory !== '' ? $directory : 'example_extension';
        $type = strtolower(trim((string) ($meta['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['helper', 'plugin', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'plugin', 'module'], true);
        $starterFiles = [
            '- `ext.json`',
            '- `ext.php`',
            '- `lib/schema.php`',
            '- `lib/routes_panel.php`',
            '- `vis/panel_index.php`',
        ];
        if ($generatesPublicRoutes) {
            $starterFiles[] = '- `lib/routes_public.php`';
            $starterFiles[] = '- `vis/public_index.php`';
        }
        if ($generatesShortcodes) {
            $starterFiles[] = '- `lib/shortcodes.php`';
        }
        if ($generatesContentBlocks) {
            $starterFiles[] = '- `lib/fields.php`';
        }
        $starterFilesMarkdown = implode("\n", $starterFiles);

        $content = <<<'MARKDOWN'
# __NAME__ Extension Guide

This file applies to this extension only:

- `private/ext/__DIRECTORY__/`

For Raven-wide extension contracts not restated here, use:

- [private/ext/AGENTS.md](../AGENTS.md)

## Local Scope

- Keep extension logic and state self-contained under this directory.
- Do not modify Raven core files for extension-only behavior.
- Keep panel routes and state-changing handlers protected by login + CSRF + sanitization.

## Starter Files

__STARTER_FILES__

## Update Discipline

- Update this file when this extension's local contracts, routes, or storage conventions change.
MARKDOWN;

        return str_replace(
            ['__NAME__', '__DIRECTORY__', '__STARTER_FILES__'],
            [$name, $directory, $starterFilesMarkdown],
            $content
        ) . "\n";
    }

    /**
     * Converts user input to bounded float or null.
     */
    private function normalizeNullableFloat(mixed $value, float $min, float $max): ?float
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;

        if ($floatValue < $min || $floatValue > $max) {
            return null;
        }

        return $floatValue;
    }

    /**
     * Returns editable permission-bit definitions for usergroups UI.
     *
     * @return array<int, array{
     *   bit: int,
     *   label: string,
     *   section?: string,
     *   group?: string,
     *   action?: string,
     *   extension?: string
     * }>
     */
    private function permissionDefinitions(): array
    {
        $definitions = [
            ['bit' => PanelAccess::VIEW_PUBLIC_SITE, 'label' => 'View Public Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_public'],
            ['bit' => PanelAccess::VIEW_PRIVATE_SITE, 'label' => 'View Private Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_private'],
            ['bit' => PanelAccess::VIEW_DISABLED_SITE, 'label' => 'View Disabled Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_disabled'],
            ['bit' => PanelAccess::PANEL_LOGIN, 'label' => 'Access Dashboard', 'section' => 'panel', 'group' => 'Panel', 'action' => 'login'],
        ];

        foreach (PanelAccess::stockPanelRoutePermissions() as $routeKey => $routeDefinition) {
            $groupLabel = (string) ($routeDefinition['label'] ?? ucfirst($routeKey));
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $bit = (int) ($routeDefinition[$action] ?? 0);
                if ($bit <= 0) {
                    continue;
                }

                $definitions[] = [
                    'bit' => $bit,
                    'label' => $groupLabel . ': ' . ucfirst($action),
                    'section' => 'panel',
                    'group' => $groupLabel,
                    'action' => $action,
                ];
            }
        }

        try {
            $extensionPermissionMap = $this->extensionPanelPermissionMapForDirectories();
        } catch (\Throwable) {
            $extensionPermissionMap = [];
        }

        foreach ($extensionPermissionMap as $directory => $meta) {
            $extensionLabel = trim((string) ($meta['name'] ?? $directory));
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                $bit = (int) ($level['bit'] ?? 0);
                if ($bit <= 0) {
                    continue;
                }

                $levelLabel = trim((string) ($level['label'] ?? 'Access'));
                $definitions[] = [
                    'bit' => $bit,
                    'label' => $extensionLabel . ': ' . $levelLabel,
                    'section' => 'extension',
                    'group' => $extensionLabel,
                    'action' => (string) ($level['key'] ?? 'access'),
                    'extension' => (string) $directory,
                ];
            }
        }

        return $definitions;
    }

    /**
     * Returns combined bitmask for all extension-level permissions.
     */
    private function extensionPermissionBitsMask(): int
    {
        $mask = 0;
        try {
            $extensionPermissionMap = $this->extensionPanelPermissionMapForDirectories();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($extensionPermissionMap as $meta) {
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                $mask |= (int) ($level['bit'] ?? 0);
            }
        }

        return $mask;
    }

    /**
     * Stores one avatar upload after decode/re-encode metadata stripping.
     *
     * Returns `null` on success, otherwise one user-facing error message.
     *
     * @param array<string, mixed> $upload
     */
    private function storeSanitizedAvatarUpload(array $upload, string $destination): ?string
    {
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return 'Failed to read uploaded avatar file.';
        }

        $extension = strtolower((string) pathinfo($destination, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return 'Avatar upload format is not supported.';
        }

        $imagickError = null;
        $stored = false;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeSanitizedAvatarWithImagick($tmpPath, $destination, $extension);
            if ($imagickError === null) {
                $stored = true;
            }
        }

        if (!$stored && function_exists('imagecreatefromstring')) {
            $gdError = $this->storeSanitizedAvatarWithGd($tmpPath, $destination, $extension);
            if ($gdError === null) {
                $stored = true;
            } else {
                return $gdError;
            }
        }

        if (!$stored && $imagickError !== null) {
            return $imagickError;
        }

        if (!$stored) {
            return 'Avatar processing requires Imagick or GD extension.';
        }

        $thumbnailPath = dirname($destination) . '/' . $this->avatarThumbnailFilename((string) basename($destination));
        $thumbError = $this->storeAvatarThumbnail($destination, $thumbnailPath);
        if ($thumbError !== null) {
            @unlink($destination);
            @unlink($thumbnailPath);
            return $thumbError;
        }

        @chmod($destination, 0640);
        @chmod($thumbnailPath, 0640);

        return null;
    }

    /**
     * Re-encodes avatar upload with Imagick to strip metadata/profiles.
     */
    private function storeSanitizedAvatarWithImagick(string $tmpPath, string $destination, string $extension): ?string
    {
        try {
            $image = new \Imagick();
            $image->readImage($tmpPath);
            $image = $image->coalesceImages();

            $format = $extension === 'jpg' ? 'jpeg' : $extension;
            foreach ($image as $frame) {
                if ($frame instanceof \Imagick) {
                    if (method_exists($frame, 'autoOrientImage')) {
                        $frame->autoOrientImage();
                    }
                    $frame->stripImage();
                    $frame->setImageFormat($format);
                    if ($format === 'jpeg') {
                        $frame->setImageCompression(\Imagick::COMPRESSION_JPEG);
                        $frame->setImageCompressionQuality(90);
                    }
                }
            }

            if ($format === 'gif') {
                $written = $image->writeImages($destination, true);
            } else {
                $image->setFirstIterator();
                $written = $image->writeImage($destination);
            }

            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to store uploaded avatar file.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to sanitize avatar upload.';
        }
    }

    /**
     * Re-encodes avatar upload with GD fallback to strip metadata/profiles.
     */
    private function storeSanitizedAvatarWithGd(string $tmpPath, string $destination, string $extension): ?string
    {
        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to read uploaded avatar file.';
        }

        $image = @imagecreatefromstring($bytes);
        if (!is_object($image)) {
            return 'Failed to sanitize avatar upload.';
        }

        try {
            $written = false;
            if ($extension === 'jpg' || $extension === 'jpeg') {
                $written = imagejpeg($image, $destination, 90);
            } elseif ($extension === 'png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $written = imagepng($image, $destination, 6);
            } elseif ($extension === 'gif') {
                $written = imagegif($image, $destination);
            }
        } finally {
            imagedestroy($image);
        }

        if (!$written || !is_file($destination)) {
            @unlink($destination);
            return 'Failed to store uploaded avatar file.';
        }

        return null;
    }

    /**
     * Generates one fixed-size avatar thumbnail JPEG beside stored original.
     */
    private function storeAvatarThumbnail(string $sourcePath, string $destination): ?string
    {
        $sourceInfo = @getimagesize($sourcePath);
        if (!is_array($sourceInfo) || !isset($sourceInfo[0], $sourceInfo[1])) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = (int) $sourceInfo[0];
        $sourceHeight = (int) $sourceInfo[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return 'Failed to generate avatar thumbnail.';
        }

        if ($sourceWidth <= self::AVATAR_THUMB_SIZE && $sourceHeight <= self::AVATAR_THUMB_SIZE) {
            // Small avatars should keep exact sanitized bytes for thumb path.
            if (!@copy($sourcePath, $destination) || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        }

        $imagickError = null;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeAvatarThumbnailWithImagick($sourcePath, $destination);
            if ($imagickError === null) {
                return null;
            }
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->storeAvatarThumbnailWithGd($sourcePath, $destination);
        }

        if ($imagickError !== null) {
            return $imagickError;
        }

        return 'Avatar thumbnail generation requires Imagick or GD extension.';
    }

    /**
     * Generates one avatar thumbnail using Imagick.
     */
    private function storeAvatarThumbnailWithImagick(string $sourcePath, string $destination): ?string
    {
        try {
            $image = new \Imagick();
            // Restrict to first frame so animated GIF avatars produce deterministic thumbs.
            $image->readImage($sourcePath . '[0]');

            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            $sourceWidth = (int) $image->getImageWidth();
            $sourceHeight = (int) $image->getImageHeight();
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                $image->clear();
                $image->destroy();
                return 'Failed to generate avatar thumbnail.';
            }

            $cropSize = min($sourceWidth, $sourceHeight);
            $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
            $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

            // Crop to centered square before resizing so thumb fill is always exact 120x120.
            $image->cropImage($cropSize, $cropSize, $cropX, $cropY);
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage(
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                \Imagick::FILTER_LANCZOS,
                1.0,
                true
            );

            $image->setImageBackgroundColor('#ffffff');
            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $image->clear();
                    $image->destroy();
                    $image = $flattened;
                }
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(85);
            $image->stripImage();

            $written = $image->writeImage($destination);
            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }
    }

    /**
     * Generates one avatar thumbnail using GD.
     */
    private function storeAvatarThumbnailWithGd(string $sourcePath, string $destination): ?string
    {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to generate avatar thumbnail.';
        }

        $source = @imagecreatefromstring($bytes);
        if (!is_object($source)) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
        $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

        $thumbnail = imagecreatetruecolor(self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE);
        if (!is_object($thumbnail)) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        try {
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefilledrectangle($thumbnail, 0, 0, self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE, $white);

            $written = imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                $cropSize,
                $cropSize
            );
            if (!$written) {
                return 'Failed to generate avatar thumbnail.';
            }

            if (!imagejpeg($thumbnail, $destination, 85)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }

        if (!is_file($destination)) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }

        return null;
    }

    /**
     * Returns canonical avatar storage directory and ensures it exists.
     */
    private function avatarStorageDirectory(): string
    {
        $avatarsDir = dirname(__DIR__, 3) . '/public/uploads/avatars';
        if (!is_dir($avatarsDir)) {
            @mkdir($avatarsDir, 0775, true);
        }

        return $avatarsDir;
    }

    /**
     * Normalizes one avatar extension token to the canonical storage extension.
     */
    private function normalizeAvatarExtension(string $extension): ?string
    {
        $normalized = strtolower(trim($extension));
        if ($normalized === 'jpeg') {
            $normalized = 'jpg';
        }

        if (!in_array($normalized, ['jpg', 'png', 'gif'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Returns deterministic avatar filename for one user id and extension.
     */
    private function avatarFilenameForUserId(int $userId, string $extension): string
    {
        $normalizedExtension = $this->normalizeAvatarExtension($extension) ?? 'jpg';

        return (string) $userId . '.' . $normalizedExtension;
    }

    /**
     * Returns deterministic thumbnail filename for one avatar filename.
     */
    private function avatarThumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }

    /**
     * Removes one avatar file from public avatar storage if present.
     */
    private function deleteAvatarFile(string $filename): void
    {
        // Normalize to basename to prevent path traversal on deletion.
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $path = dirname(__DIR__, 3) . '/public/uploads/avatars/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        // Keep thumbnail lifecycle tied to original avatar lifecycle.
        $thumbPath = dirname(__DIR__, 3) . '/public/uploads/avatars/' . $this->avatarThumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }

    /**
     * Resolves currently logged-in user's chosen panel theme.
     */
    private function currentUserTheme(): string
    {
        $defaultTheme = $this->defaultPanelTheme();
        $userId = $this->auth->userId();
        if ($userId === null) {
            return $defaultTheme;
        }

        $preferences = $this->auth->userPreferences($userId);
        $theme = is_array($preferences)
            ? $this->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true)
            : 'default';
        if (!is_string($theme)) {
            return $defaultTheme;
        }

        if ($theme === 'default') {
            return $defaultTheme;
        }

        return $theme;
    }

    /**
     * Resolves global default panel theme from configuration.
     */
    private function defaultPanelTheme(): string
    {
        $theme = $this->normalizePanelThemeChoice(
            (string) $this->config->get('panel.default_theme', 'corp'),
            false
        );
        if (!is_string($theme)) {
            return 'corp';
        }

        return $theme;
    }

    /**
     * Normalizes panel-theme identifiers and maps legacy values.
     *
     * Legacy compatibility:
     * - `light`/`default` map to `corp`
     * - `dark` maps to `midnight`
     */
    private function normalizePanelThemeChoice(string $theme, bool $allowDefault): ?string
    {
        $normalized = strtolower(trim($theme));
        if ($normalized === '') {
            return $allowDefault ? 'default' : 'corp';
        }

        if ($allowDefault && $normalized === 'default') {
            return 'default';
        }

        if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
            return $normalized;
        }

        if (in_array($normalized, ['light', 'raven', 'default'], true)) {
            return 'corp';
        }

        if ($normalized === 'dark') {
            return 'midnight';
        }

        return null;
    }

    /**
     * Resolves configured panel login identifier mode.
     */
    private function panelLoginIdentifierMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.login', 'email')));
        if (!in_array($mode, ['email', 'username'], true)) {
            $mode = 'email';
        }

        return $mode;
    }

    /**
     * Resolves configured public registration mode.
     */
    private function registrationMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.registration', 'closed')));
        if (!in_array($mode, ['open', 'invite', 'closed'], true)) {
            $mode = 'closed';
        }

        return $mode;
    }

    /**
     * Parses one optional invite-expiration datetime into a unix timestamp.
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
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * Accepts canonical usernames and email-shaped values.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        $normalizedText = $this->input->text($rawValue, 254);
        if ($normalizedText === '') {
            return null;
        }

        $normalizedUsername = $this->input->username($normalizedText);
        if ($normalizedUsername !== null && $normalizedUsername !== '') {
            return $normalizedUsername;
        }

        $normalizedEmail = $this->input->email($normalizedText);
        if ($normalizedEmail !== null && $normalizedEmail !== '') {
            return $normalizedEmail;
        }

        return null;
    }

    /**
     * Builds panel-visible routing inventory rows for pages/channels/categories/tags/redirects/users/groups.
     *
     * @return array<int, array{
     *   type_key: string,
     *   type_label: string,
     *   source_label: string,
     *   edit_url: string,
     *   public_url: string,
     *   target_url: string,
     *   status_key: string,
     *   status_label: string,
     *   notes: string,
     *   is_conflict: bool
     * }>
     */
    private function routingRowsForPanel(): array
    {
        $rows = [];
        $pathUsage = [];
        $reservedPrefixes = $this->reservedPublicPrefixes();
        $channelIndexTemplateExists = $this->channelIndexTemplateExistsForRouting();
        $categoryPrefix = $this->categoryRoutePrefix();
        $tagPrefix = $this->tagRoutePrefix();
        $profilePrefix = $this->profileRoutePrefix();
        $profileRoutesEnabled = $this->profileRoutesEnabledForRoutingTable();
        $groupPrefix = $this->groupRoutePrefix();
        $groupRoutesEnabled = $this->groupRoutesEnabledForRoutingTable();
        $canEditPages = $this->auth->hasPanelPermissionBit(PanelAccess::PAGES_EDIT);
        $canEditChannels = $this->auth->hasPanelPermissionBit(PanelAccess::CHANNELS_EDIT);
        $canEditCategories = $this->auth->hasPanelPermissionBit(PanelAccess::CATEGORIES_EDIT);
        $canEditTags = $this->auth->hasPanelPermissionBit(PanelAccess::TAGS_EDIT);
        $canEditRedirects = $this->auth->hasPanelPermissionBit(PanelAccess::REDIRECTS_EDIT);
        $canEditUsers = $this->auth->hasPanelPermissionBit(PanelAccess::USERS_EDIT);
        $canEditGroups = $this->auth->hasPanelPermissionBit(PanelAccess::GROUPS_EDIT);
        $groupRoutingEnabled = $groupRoutesEnabled && $groupPrefix !== '';
        $userRoutingEnabled = $profileRoutesEnabled && $profilePrefix !== '';
        $routingAuthData = $this->users->listRoutingData($groupRoutingEnabled, $userRoutingEnabled);
        $routingGroups = is_array($routingAuthData['groups'] ?? null) ? $routingAuthData['groups'] : [];
        $routingUsers = is_array($routingAuthData['users'] ?? null) ? $routingAuthData['users'] : [];
        $categoryRoutesEnabled = $categoryPrefix !== '';
        $tagRoutesEnabled = $tagPrefix !== '';
        $taxonomyRoutingData = $this->taxonomy->listRoutingInventoryData(
            $categoryRoutesEnabled,
            $tagRoutesEnabled,
            true
        );
        $channelRoutingOptions = is_array($taxonomyRoutingData['channels'] ?? null)
            ? $taxonomyRoutingData['channels']
            : [];
        $categoryRoutingOptions = is_array($taxonomyRoutingData['categories'] ?? null)
            ? $taxonomyRoutingData['categories']
            : [];
        $tagRoutingOptions = is_array($taxonomyRoutingData['tags'] ?? null)
            ? $taxonomyRoutingData['tags']
            : [];
        $redirectRoutingRows = is_array($taxonomyRoutingData['redirects'] ?? null)
            ? $taxonomyRoutingData['redirects']
            : [];
        $pagesForRouting = $this->pages->listAllForRouting();
        $channelsById = [];
        foreach ($channelRoutingOptions as $channelOption) {
            $channelId = (int) ($channelOption['id'] ?? 0);
            if ($channelId > 0) {
                $channelsById[$channelId] = [
                    'slug' => (string) ($channelOption['slug'] ?? ''),
                    'name' => (string) ($channelOption['name'] ?? ''),
                    'page_route_mode' => $this->normalizeChannelPageRouteMode(
                        (string) ($channelOption['page_route_mode'] ?? 'slug')
                    ),
                    'page_url_separator' => $this->normalizeChannelPageUrlSeparator(
                        (string) ($channelOption['page_url_separator'] ?? 'inherit')
                    ),
                ];
            }
        }
        foreach ($pagesForRouting as &$pageForRouting) {
            $channelId = (int) ($pageForRouting['channel_id'] ?? 0);
            $pageForRouting['channel_slug'] = (string) ($channelsById[$channelId]['slug'] ?? '');
            $pageForRouting['channel_name'] = (string) ($channelsById[$channelId]['name'] ?? '');
            $pageForRouting['channel_page_route_mode'] = (string) ($channelsById[$channelId]['page_route_mode'] ?? 'slug');
            $pageForRouting['channel_page_url_separator'] = (string) ($channelsById[$channelId]['page_url_separator'] ?? 'inherit');
        }
        unset($pageForRouting);
        $channelLandingMap = $this->channelLandingMapFromPagesForRouting($pagesForRouting);

        foreach ($channelRoutingOptions as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $channelSlug = trim((string) ($channel['slug'] ?? ''));
            if ($channelId <= 0 || $channelSlug === '') {
                continue;
            }

            $landingSlug = trim((string) ($channelLandingMap[$channelSlug] ?? ''));
            $hasLanding = $landingSlug !== '';
            $statusKey = $hasLanding ? 'active' : 'missing';
            $statusLabel = $hasLanding
                ? 'Active'
                : ($channelIndexTemplateExists ? 'Missing Index' : 'Missing Template');
            $notes = $hasLanding
                ? ('Channel landing resolves using slug "' . $landingSlug . '".')
                : 'No published channel landing page found (requires slug home or index).';
            if (in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this channel route is not publicly reachable.';
            }

            $publicUrl = '/' . $channelSlug;
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'channel',
                'type_label' => 'Channel',
                'source_label' => trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug,
                'edit_url' => $canEditChannels ? $this->panelUrl('/channels/edit/' . $channelId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        foreach ($pagesForRouting as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            if ($pageId <= 0 || $pageSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            $publicUrl = $this->routingPublicPathForPage(
                $pageSlug,
                $channelSlug,
                (string) ($page['published_at'] ?? ''),
                (string) ($page['channel_page_route_mode'] ?? 'slug'),
                (string) ($page['channel_page_url_separator'] ?? 'inherit')
            );

            $statusKey = (int) ($page['is_published'] ?? 0) === 1 ? 'published' : 'draft';
            $statusLabel = $statusKey === 'published' ? 'Published' : 'Draft';
            $notes = '';

            if ($channelSlug === '' && in_array($pageSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level page route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled page route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'page',
                'type_label' => 'Page',
                'source_label' => trim((string) ($page['title'] ?? '')) !== '' ? (string) $page['title'] : $pageSlug,
                'edit_url' => $canEditPages ? $this->panelUrl('/pages/edit/' . $pageId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        if ($categoryRoutesEnabled) {
            foreach ($categoryRoutingOptions as $category) {
                $categoryId = (int) ($category['id'] ?? 0);
                $categorySlug = trim((string) ($category['slug'] ?? ''));
                if ($categoryId <= 0 || $categorySlug === '') {
                    continue;
                }

                $publicUrl = '/' . $categoryPrefix . '/' . $categorySlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'category',
                    'type_label' => 'Category',
                    'source_label' => trim((string) ($category['name'] ?? '')) !== ''
                        ? (string) $category['name']
                        : $categorySlug,
                    'edit_url' => $canEditCategories ? $this->panelUrl('/categories/edit/' . $categoryId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($tagRoutesEnabled) {
            foreach ($tagRoutingOptions as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                $tagSlug = trim((string) ($tag['slug'] ?? ''));
                if ($tagId <= 0 || $tagSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $tagPrefix . '/' . $tagSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'tag',
                    'type_label' => 'Tag',
                    'source_label' => trim((string) ($tag['name'] ?? '')) !== '' ? (string) $tag['name'] : $tagSlug,
                    'edit_url' => $canEditTags ? $this->panelUrl('/tags/edit/' . $tagId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($groupRoutingEnabled) {
            foreach ($routingGroups as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $groupName = trim((string) ($group['name'] ?? ''));
                if ($groupId <= 0 || $groupName === '') {
                    continue;
                }
                $groupRoleSlug = strtolower(trim((string) ($group['slug'] ?? '')));
                if (in_array($groupRoleSlug, ['guest', 'validating', 'banned'], true)) {
                    continue;
                }

                $routeEnabled = (int) ($group['route_enabled'] ?? 0) === 1;
                if (!$routeEnabled) {
                    continue;
                }

                $groupSlug = $this->input->slug((string) ($group['slug'] ?? ''));
                if ($groupSlug === null || $groupSlug === '') {
                    $groupSlug = $this->slugifyGroupName($groupName);
                }
                if ($groupSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $groupPrefix . '/' . $groupSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $memberCount = max(0, (int) ($group['member_count'] ?? 0));
                $statusLabel = $memberCount . ' Users';

                $rows[] = [
                    'type_key' => 'group',
                    'type_label' => 'Group',
                    'source_label' => $groupName,
                    'edit_url' => $canEditGroups ? $this->panelUrl('/groups/edit/' . $groupId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'users_' . $memberCount,
                    'status_label' => $statusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($userRoutingEnabled) {
            foreach ($routingUsers as $user) {
                $userId = (int) ($user['id'] ?? 0);
                $username = $this->normalizeUserIdentifierValue((string) ($user['username'] ?? ''));
                if ($userId <= 0 || $username === null) {
                    continue;
                }

                $publicUrl = '/' . $profilePrefix . '/' . rawurlencode($username);
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $groupStatusLabel = trim((string) ($user['groups_text'] ?? ''));
                if ($groupStatusLabel === '') {
                    $groupStatusLabel = 'No Groups';
                }

                $statusKey = 'groups_' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $groupStatusLabel) ?? 'none');
                $statusKey = trim($statusKey, '-');
                if ($statusKey === '') {
                    $statusKey = 'groups_none';
                }

                $rows[] = [
                    'type_key' => 'user',
                    'type_label' => 'User',
                    'source_label' => $username,
                    'edit_url' => $canEditUsers ? $this->panelUrl('/users/edit/' . $userId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => $statusKey,
                    'status_label' => $groupStatusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        foreach ($redirectRoutingRows as $redirect) {
            $redirectId = (int) ($redirect['id'] ?? 0);
            $redirectSlug = trim((string) ($redirect['slug'] ?? ''));
            if ($redirectId <= 0 || $redirectSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($redirect['channel_slug'] ?? ''));
            $publicUrl = $channelSlug === ''
                ? '/' . $redirectSlug
                : '/' . $channelSlug . '/' . $redirectSlug;

            $statusKey = (int) ($redirect['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
            $statusLabel = $statusKey === 'active' ? 'Active' : 'Inactive';
            $notes = '';

            if ($channelSlug === '' && in_array($redirectSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level redirect route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled redirect route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'redirect',
                'type_label' => 'Redirect',
                'source_label' => trim((string) ($redirect['title'] ?? '')) !== '' ? (string) $redirect['title'] : $redirectSlug,
                'edit_url' => $canEditRedirects ? $this->panelUrl('/redirects/edit/' . $redirectId) : '',
                'public_url' => $publicUrl,
                'target_url' => trim((string) ($redirect['target_url'] ?? '')),
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        foreach ($rows as $index => $row) {
            $conflictKey = (string) ($row['_conflict_key'] ?? '');
            if ($conflictKey === '') {
                continue;
            }

            $usageCount = (int) ($pathUsage[$conflictKey] ?? 0);
            if ($usageCount <= 1) {
                unset($rows[$index]['_conflict_key']);
                continue;
            }

            $rows[$index]['is_conflict'] = true;
            $suffix = 'Path conflict with ' . (string) ($usageCount - 1) . ' other route(s).';
            $existingNotes = trim((string) ($rows[$index]['notes'] ?? ''));
            $rows[$index]['notes'] = $existingNotes === '' ? $suffix : ($existingNotes . ' ' . $suffix);
            unset($rows[$index]['_conflict_key']);
        }

        usort($rows, static function (array $a, array $b): int {
            $pathCompare = strcasecmp((string) ($a['public_url'] ?? ''), (string) ($b['public_url'] ?? ''));
            if ($pathCompare !== 0) {
                return $pathCompare;
            }

            $typeCompare = strcasecmp((string) ($a['type_label'] ?? ''), (string) ($b['type_label'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($a['source_label'] ?? ''), (string) ($b['source_label'] ?? ''));
        });

        return $rows;
    }

    /**
     * Builds one routing-table public URL path for a page row.
     */
    private function routingPublicPathForPage(
        string $pageSlug,
        string $channelSlug,
        string $publishedAt,
        string $channelPageRouteMode,
        string $channelPageUrlSeparator
    ): string {
        $normalizedSlug = $this->input->slug($pageSlug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            return '/';
        }

        $normalizedChannel = $this->input->slug($channelSlug);
        if ($normalizedChannel === null || $normalizedChannel === '') {
            return '/' . $normalizedSlug;
        }

        $normalizedMode = $this->normalizeChannelPageRouteMode($channelPageRouteMode);
        $resolvedSeparator = $this->resolveChannelPageUrlSeparator($channelPageUrlSeparator);
        $routeSlug = $resolvedSeparator === '_'
            ? str_replace('-', '_', $normalizedSlug)
            : $normalizedSlug;
        $routeSegment = $routeSlug;
        if ($normalizedMode === 'date_slug') {
            $datePrefix = $this->routingDatePrefixFromPublishedAt($publishedAt);
            $routeSegment = $datePrefix . '-' . $routeSlug;
        }

        return '/' . $normalizedChannel . '/' . $routeSegment;
    }

    /**
     * Returns one `YYYY-MM-DD` date prefix for channel date-slug routes.
     */
    private function routingDatePrefixFromPublishedAt(string $publishedAt): string
    {
        $publishedAt = trim($publishedAt);
        if ($publishedAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $publishedAt, $matches) === 1) {
            return (string) ($matches[0] ?? gmdate('Y-m-d'));
        }

        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
        }

        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Derives one channel -> landing page slug map from routing page rows.
     *
     * Landing priority per channel:
     * - published `home` first
     * - published `index` fallback
     * - for duplicate slug candidates, latest `published_at` wins
     *
     * @param array<int, array<string, mixed>> $pagesForRouting
     * @return array<string, string>
     */
    private function channelLandingMapFromPagesForRouting(array $pagesForRouting): array
    {
        /** @var array<string, array{slug: string, priority: int, published_ts: int}> $best */
        $best = [];

        foreach ($pagesForRouting as $page) {
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            if ((int) ($page['is_published'] ?? 0) !== 1) {
                continue;
            }

            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $priority = match ($pageSlug) {
                'home' => 0,
                'index' => 1,
                default => null,
            };
            if ($priority === null) {
                continue;
            }

            $publishedAt = trim((string) ($page['published_at'] ?? ''));
            $publishedTs = $publishedAt !== '' ? (int) strtotime($publishedAt) : 0;
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            $candidate = [
                'slug' => $pageSlug,
                'priority' => $priority,
                'published_ts' => $publishedTs,
            ];

            if (!isset($best[$channelSlug])) {
                $best[$channelSlug] = $candidate;
                continue;
            }

            $current = $best[$channelSlug];
            if (
                $candidate['priority'] < $current['priority']
                || (
                    $candidate['priority'] === $current['priority']
                    && $candidate['published_ts'] > $current['published_ts']
                )
            ) {
                $best[$channelSlug] = $candidate;
            }
        }

        $result = [];
        foreach ($best as $channelSlug => $candidate) {
            $result[$channelSlug] = (string) ($candidate['slug'] ?? '');
        }

        return $result;
    }

    /**
     * Returns true when public channel index template resolves in active theme chain or core fallback.
     */
    private function channelIndexTemplateExistsForRouting(): bool
    {
        $themeSlug = strtolower($this->input->text((string) $this->config->get('site.default_theme', 'raven'), 80));
        $options = $this->publicThemeOptions();
        if (!isset($options[$themeSlug])) {
            if (isset($options['raven'])) {
                $themeSlug = 'raven';
            } else {
                $slugs = array_keys($options);
                $themeSlug = (string) ($slugs[0] ?? 'raven');
            }
        }

        $themesRoot = dirname(__DIR__, 3) . '/public/theme';
        $chain = PublicThemeRegistry::inheritanceChain($themesRoot, $themeSlug);
        if ($chain === []) {
            $chain = [$themeSlug];
        }

        foreach ($chain as $candidateThemeSlug) {
            $candidate = $themesRoot . '/' . $candidateThemeSlug . '/vis/channels/index.php';
            if (is_file($candidate)) {
                return true;
            }
        }

        return is_file(dirname(__DIR__, 3) . '/private/vis/channels/index.php');
    }

    /**
     * Returns reserved root/channel slugs blocked by public router prefixes.
     *
     * @return array<int, string>
     */
    private function reservedPublicPrefixes(): array
    {
        $panelPath = trim((string) $this->config->get('panel.path', 'panel'), '/');
        $prefixes = [
            $panelPath,
            'panel',
            'boot',
            'mce',
            'theme',
            $this->categoryRoutePrefix(),
            $this->tagRoutePrefix(),
            $this->profileRoutePrefix(),
            $this->groupRoutePrefix(),
        ];

        $normalized = [];
        foreach ($prefixes as $prefix) {
            $clean = strtolower(trim((string) $prefix));
            if ($clean !== '') {
                $normalized[$clean] = $clean;
            }
        }

        return array_values($normalized);
    }

    /**
     * Returns default profile-contact option map (slug => metadata).
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function defaultProfileContactOptions(): array
    {
        return [
            'email' => ['label' => 'Email', 'url_prefix' => 'mailto:'],
            'phone' => ['label' => 'Phone', 'url_prefix' => 'tel:'],
            'website' => ['label' => 'Website', 'url_prefix' => 'https://'],
            'x' => ['label' => 'X', 'url_prefix' => 'https://x.com/'],
        ];
    }

    /**
     * Returns contact-option defaults that are mandatory and cannot be removed.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function requiredProfileContactOptions(): array
    {
        $defaults = $this->defaultProfileContactOptions();
        $required = [];
        foreach (['email', 'phone', 'website', 'x'] as $slug) {
            if (!isset($defaults[$slug])) {
                continue;
            }

            $required[$slug] = $defaults[$slug];
        }

        return $required;
    }

    /**
     * Normalizes one profile-contact option map from config.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function normalizeProfileContactOptionsConfig(mixed $raw): array
    {
        $source = is_array($raw) ? $raw : $this->defaultProfileContactOptions();
        $defaults = $this->defaultProfileContactOptions();
        $requiredDefaults = $this->requiredProfileContactOptions();
        $normalized = [];
        foreach ($source as $key => $definition) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $slug = $this->input->slug((string) $key);
            if ($slug === null || $slug === '') {
                continue;
            }

            $defaultLabel = (string) ($defaults[$slug]['label'] ?? ucwords(str_replace('-', ' ', $slug)));
            $defaultPrefix = (string) ($defaults[$slug]['url_prefix'] ?? '');

            $safeLabel = $defaultLabel;
            $safePrefix = $defaultPrefix;
            if (is_array($definition)) {
                $safeLabel = $this->input->text((string) ($definition['label'] ?? $defaultLabel), 80);
                $safePrefix = $this->input->text((string) ($definition['url_prefix'] ?? $defaultPrefix), 255);
            } else {
                $safeLabel = $this->input->text((string) $definition, 80);
            }

            if ($safeLabel === '') {
                continue;
            }
            $safePrefix = trim($safePrefix);

            if (!isset($normalized[$slug])) {
                $normalized[$slug] = [
                    'label' => $safeLabel,
                    'url_prefix' => $safePrefix,
                ];
            }
        }

        foreach ($requiredDefaults as $requiredSlug => $requiredConfig) {
            if (isset($normalized[$requiredSlug])) {
                continue;
            }

            $normalized[$requiredSlug] = [
                'label' => (string) ($requiredConfig['label'] ?? ucwords(str_replace('-', ' ', $requiredSlug))),
                'url_prefix' => trim((string) ($requiredConfig['url_prefix'] ?? '')),
            ];
        }

        if ($normalized === []) {
            return $requiredDefaults;
        }

        return $normalized;
    }

    /**
     * Normalizes submitted profile-contact option rows from configuration editor.
     *
     * @param mixed $rawOptions
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function normalizeSubmittedProfileContactOptionsConfig(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }

        $normalized = [];
        foreach ($rawOptions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->input->slug((string) ($entry['type'] ?? ''));
            if ($type === null || $type === '') {
                continue;
            }

            $label = $this->input->text((string) ($entry['label'] ?? ''), 80);
            if ($label === '') {
                continue;
            }

            $urlPrefix = trim($this->input->text((string) ($entry['url_prefix'] ?? ''), 255));
            if (isset($normalized[$type])) {
                continue;
            }

            $normalized[$type] = [
                'label' => $label,
                'url_prefix' => $urlPrefix,
            ];

            if (count($normalized) >= 100) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Returns normalized profile-contact option map from runtime config.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function profileContactOptions(): array
    {
        return $this->normalizeProfileContactOptionsConfig(
            $this->config->get('user.contact', $this->defaultProfileContactOptions())
        );
    }

    /**
     * Normalizes submitted profile-contact rows from panel forms.
     *
     * @param mixed $rawProfiles
     * @param array<string, array{label: string, url_prefix: string}> $allowedOptions
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeSubmittedContactProfiles(mixed $rawProfiles, array $allowedOptions): array
    {
        if (!is_array($rawProfiles) || $allowedOptions === []) {
            return [];
        }

        $normalized = [];
        foreach ($rawProfiles as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $this->input->slug((string) ($row['type'] ?? ''));
            if ($type === null || !array_key_exists($type, $allowedOptions)) {
                continue;
            }

            $value = $this->input->text((string) ($row['value'] ?? ''), 255);
            if ($value === '') {
                continue;
            }

            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type' => $type,
                'value' => $value,
            ];

            if (count($normalized) >= 20) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array<string, string>
     */
    private function twoFactorTypeOptions(): array
    {
        return [
            'none' => '<none>',
            'totp' => 'Authenticator App (TOTP)',
            'recovery' => 'Recovery Code',
            'webauthn' => 'Security Key (WebAuthn)',
            'email' => 'Email Code (Stub)',
        ];
    }

    /**
     * @param mixed $rawMethods
     * @return array<int, int>
     */
    private function normalizeSubmittedTwoFactorExistingIndices(mixed $rawMethods): array
    {
        if (!is_array($rawMethods)) {
            return [];
        }

        $normalized = [];
        foreach ($rawMethods as $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingIndex = $this->input->int($row['existing_index'] ?? null, 0, 1000);
            if ($existingIndex === null) {
                continue;
            }

            $normalized[$existingIndex] = $existingIndex;
            if (count($normalized) >= 100) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param mixed $rawMethods
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSubmittedTwoFactorMethods(mixed $rawMethods, string $fallbackEmail): array
    {
        if (!is_array($rawMethods)) {
            return [];
        }

        return TwoFactorMethodNormalizer::normalizeSubmitted($rawMethods, $fallbackEmail, $this->totpIssuer());
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    private function prepareTwoFactorMethodsForView(array $methods, string $fallbackEmail): array
    {
        return TwoFactorMethodNormalizer::prepareForView($methods, $fallbackEmail, $this->totpIssuer());
    }

    private function totpIssuer(): string
    {
        $issuer = trim((string) $this->config->get('site.name', 'Raven CMS'));
        return $issuer !== '' ? $issuer : 'Raven CMS';
    }

    private function createWebAuthnServer(): ?WebAuthn
    {
        return WebAuthnService::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $_SERVER
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns configured public category index route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        if (!$this->categoryEnabled()) {
            return '';
        }

        return $this->normalizePublicRoutePrefix((string) $this->config->get('category.prefix', 'cat'), 'cat', true);
    }

    /**
     * Returns configured public tag index route prefix.
     */
    private function tagRoutePrefix(): string
    {
        if (!$this->tagEnabled()) {
            return '';
        }

        return $this->normalizePublicRoutePrefix((string) $this->config->get('tag.prefix', 'tag'), 'tag', true);
    }

    /**
     * Returns true when categories are enabled in runtime config.
     */
    private function categoryEnabled(): bool
    {
        return $this->configBool($this->config->get('category.enabled', true), true);
    }

    /**
     * Returns true when tags are enabled in runtime config.
     */
    private function tagEnabled(): bool
    {
        return $this->configBool($this->config->get('tag.enabled', true), true);
    }

    /**
     * Normalizes one config scalar to a boolean value.
     */
    private function configBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Returns configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('user.prefix', 'user'), 'user', true);
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     */
    private function profileRoutesEnabledForRoutingTable(): bool
    {
        if ($this->profileRoutePrefix() === '') {
            return false;
        }

        $mode = strtolower(trim((string) $this->config->get('user.privacy', 'disabled')));
        return in_array($mode, ['public_full', 'public_limited', 'private'], true);
    }

    /**
     * Returns configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('group.prefix', 'group'), 'group', true);
    }

    /**
     * Returns true when public group URLs are enabled for routing inventory.
     */
    private function groupRoutesEnabledForRoutingTable(): bool
    {
        if ($this->groupRoutePrefix() === '') {
            return false;
        }

        $mode = strtolower(trim((string) $this->config->get('group.privacy', 'disabled')));
        if ($mode === 'public') {
            $mode = 'public_full';
        }
        return in_array($mode, ['public_full', 'public_limited', 'private'], true);
    }

    /**
     * Derives one stable URL slug from a group name.
     */
    private function slugifyGroupName(string $groupName): string
    {
        $normalized = strtolower(trim($groupName));
        if (in_array($normalized, ['super admin', 'super-admin', 'super'], true)) {
            return 'super';
        }

        $slug = $this->input->slug($groupName);
        if ($slug === null || $slug === '') {
            return '';
        }

        return $slug;
    }

    /**
     * Normalizes one configured route prefix and falls back safely.
     */
    private function normalizePublicRoutePrefix(string $value, string $fallback, bool $allowBlank = false): string
    {
        $value = trim($value);
        if ($allowBlank && $value === '') {
            return '';
        }

        $slug = $this->input->slug($value);
        if ($slug === null || $slug === '') {
            return $fallback;
        }

        return $slug;
    }

    /**
     * Normalizes one tab key against an allowed editor-tab set.
     *
     * @param array<int, string> $allowed
     */
    private function normalizeEditorTab(mixed $value, array $allowed, string $default): string
    {
        $tab = strtolower($this->input->text(is_string($value) ? $value : null, 40));
        if ($tab === '' || !in_array($tab, $allowed, true)) {
            return $default;
        }

        return $tab;
    }

    /**
     * Builds one panel editor URL preserving selected tab.
     */
    private function panelEditorUrlWithTab(string $basePath, ?int $id, string $tab, string $defaultTab): string
    {
        $path = $basePath . ($id !== null ? '/' . $id : '');
        if ($tab === $defaultTab) {
            return $this->panelUrl($path);
        }

        return $this->panelUrl($path . '?tab=' . rawurlencode($tab));
    }

    /**
     * Returns one normalized config-editor tab key.
     */
    private function normalizeConfigEditorTab(mixed $value): string
    {
        $tab = strtolower($this->input->text(is_string($value) ? $value : null, 40));
        $allowed = ['basic', 'content', 'database', 'debug', 'media', 'meta', 'security', 'users'];
        if (!in_array($tab, $allowed, true)) {
            return 'basic';
        }

        return $tab;
    }

    /**
     * Builds configuration URL preserving selected tab.
     */
    private function configurationUrlForTab(string $tab): string
    {
        $tab = $this->normalizeConfigEditorTab($tab);
        $query = $tab === 'basic' ? '' : ('?tab=' . rawurlencode($tab));
        return $this->panelUrl('/configuration' . $query);
    }

    /**
     * Returns public theme rows for Theme Manager list view.
     *
     * @return array<int, array{
     *   slug: string,
     *   name: string,
     *   is_stock: bool,
     *   is_child_theme: bool,
     *   parent_theme: string,
     *   has_css: bool,
     *   has_wrapper: bool,
     *   inheritance_chain: string
     * }>
     */
    private function listPublicThemesForPanel(): array
    {
        $themesRoot = $this->publicThemesRoot();
        $manifests = PublicThemeRegistry::manifests($themesRoot);
        $rows = [];

        foreach ($manifests as $slug => $manifest) {
            $chain = PublicThemeRegistry::inheritanceChain($themesRoot, (string) $slug);
            $rows[] = [
                'slug' => (string) $slug,
                'name' => (string) ($manifest['name'] ?? $slug),
                'is_stock' => $this->isStockPublicThemeSlug((string) $slug),
                'is_child_theme' => (bool) ($manifest['is_child_theme'] ?? false),
                'parent_theme' => (string) ($manifest['parent_theme'] ?? ''),
                'has_css' => is_file($themesRoot . '/' . $slug . '/css/style.css'),
                'has_wrapper' => is_file($themesRoot . '/' . $slug . '/vis/wrapper.php'),
                'inheritance_chain' => implode(' -> ', $chain),
            ];
        }

        return $rows;
    }

    /**
     * Validates public-theme slugs for safe filesystem usage.
     */
    private function isSafePublicThemeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    /**
     * Derives one public-theme slug from archive filename.
     */
    private function themeSlugFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 80));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        if ($base === '' || !$this->isSafePublicThemeSlug($base)) {
            return null;
        }

        return $base;
    }

    /**
     * Resolves the next available public-theme slug by appending copy suffixes.
     */
    private function nextAvailablePublicThemeSlug(string $baseSlug): ?string
    {
        $normalizedBase = strtolower(trim($baseSlug));
        if (!$this->isSafePublicThemeSlug($normalizedBase)) {
            return null;
        }

        $themesRoot = $this->publicThemesRoot();
        $candidate = $normalizedBase;
        if (!file_exists($themesRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 250; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 64 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            if ($trimmedBase === '') {
                $trimmedBase = 'theme';
            }

            $candidate = $trimmedBase . $suffix;
            if (!$this->isSafePublicThemeSlug($candidate)) {
                continue;
            }

            if (!file_exists($themesRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolves the next available extension directory name by appending copy suffixes.
     */
    private function nextAvailableExtensionDirectoryName(string $baseName): ?string
    {
        $normalizedBase = strtolower(trim($baseName));
        if (!$this->isSafeExtensionDirectoryName($normalizedBase)) {
            return null;
        }

        $extensionsRoot = $this->extensionsBasePath();
        $candidate = $normalizedBase;
        if (!file_exists($extensionsRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 500; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 120 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            if ($trimmedBase === '') {
                $trimmedBase = 'extension';
            }

            $candidate = $trimmedBase . $suffix;
            if (!$this->isSafeExtensionDirectoryName($candidate)) {
                continue;
            }

            if (!file_exists($extensionsRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns canonical stock public theme slugs that are protected from deletion.
     *
     * @return array<int, string>
     */
    private function stockPublicThemeSlugs(): array
    {
        return ['raven'];
    }

    /**
     * Returns true when one public theme slug is part of the stock bundle.
     */
    private function isStockPublicThemeSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return in_array($normalized, $this->stockPublicThemeSlugs(), true);
    }

    /**
     * Maps PHP upload error codes into theme-upload messages.
     */
    private function themeUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Theme archive exceeds server upload limits.',
            UPLOAD_ERR_PARTIAL => 'Theme archive upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose a ZIP file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded theme archive.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the theme upload.',
            default => 'Theme upload failed with an unknown error.',
        };
    }

    /**
     * Creates one minimal public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta
     */
    private function createPublicThemeSkeleton(
        string $themePath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false,
        bool $generatePackageFile = false
    ): void
    {
        if (!is_dir($themePath) && !mkdir($themePath, 0775, true) && !is_dir($themePath)) {
            throw new \RuntimeException('Failed to create theme directory.');
        }

        $safeNameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $wrapper = "<?php\n\n"
            . "/**\n"
            . " * RAVEN CMS\n"
            . " * ~/public/theme/" . $meta['slug'] . "/vis/wrapper.php\n"
            . " * " . $safeNameForDoc . " theme wrapper template.\n"
            . " * Docs: https://raven.lanterns.io\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
            . "    http_response_code(404);\n"
            . "    exit;\n"
            . "}\n"
            . "?>\n"
            . "<!doctype html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "    <meta charset=\"utf-8\">\n"
            . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "    <title>{view_meta:document_title}</title>\n"
            . "    <meta name=\"description\" content=\"{view_meta:description}\">\n"
            . "    <link rel=\"stylesheet\" href=\"/theme/{site:public_theme_css}/css/style.css\">\n"
            . "</head>\n"
            . "<body>\n"
            . "{raw:content}\n"
            . "</body>\n"
            . "</html>\n";

        $home = "<?php\n\n"
            . "/**\n"
            . " * RAVEN CMS\n"
            . " * ~/public/theme/" . $meta['slug'] . "/vis/home.php\n"
            . " * " . $safeNameForDoc . " homepage template scaffold.\n"
            . " * Docs: https://raven.lanterns.io\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
            . "    http_response_code(404);\n"
            . "    exit;\n"
            . "}\n"
            . "?>\n"
            . "<section class=\"container py-4\">\n"
            . "    <h1>{site:name}</h1>\n"
            . "    {if page:display_title_resolved}<h2>{page:title}</h2>{/if}\n"
            . "    <div>{raw:page:content}</div>\n"
            . "</section>\n";

        $css = "/* RAVEN CMS */\n"
            . "/* ~/public/theme/" . $meta['slug'] . "/css/style.css */\n"
            . "/* " . $safeNameForDoc . " public-theme stylesheet scaffold. */\n\n"
            . ":root {\n"
            . "  --rvn-theme-bg: #f6f7fb;\n"
            . "  --rvn-theme-fg: #1d2433;\n"
            . "  --rvn-theme-accent: #2f5ee5;\n"
            . "}\n\n"
            . "body {\n"
            . "  background: var(--rvn-theme-bg);\n"
            . "  color: var(--rvn-theme-fg);\n"
            . "  font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif;\n"
            . "}\n\n"
            . "a {\n"
            . "  color: var(--rvn-theme-accent);\n"
            . "}\n";

        $this->writePublicThemeManifest(
            $themePath . '/theme.json',
            [
                'name' => $meta['name'],
                'is_child_theme' => $meta['is_child_theme'],
                'parent_theme' => $meta['is_child_theme'] ? $meta['parent_theme'] : '',
            ]
        );
        $this->writePublicThemeScaffoldFile($themePath . '/css/style.css', $css);
        $this->writePublicThemeScaffoldFile($themePath . '/vis/wrapper.php', $wrapper);
        $this->writePublicThemeScaffoldFile($themePath . '/vis/home.php', $home);
        if ($generateAgentsFile) {
            $this->writePublicThemeScaffoldFile(
                $themePath . '/AGENTS.md',
                $this->publicThemeAgentsFileContent($meta)
            );
        }
        if ($generateComposerFile) {
            $this->writePublicThemeScaffoldFile(
                $themePath . '/composer.json',
                $this->publicThemeComposerFileContent($meta)
            );
        }
        if ($generatePackageFile) {
            $this->writePublicThemeScaffoldFile(
                $themePath . '/package.json',
                $this->publicThemePackageFileContent($meta)
            );
        }
    }

    /**
     * Returns generated theme-local AGENTS guidance content.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta
     */
    private function publicThemeAgentsFileContent(array $meta): string
    {
        $name = trim((string) ($meta['name'] ?? 'Theme'));
        $slug = trim((string) ($meta['slug'] ?? 'theme'));
        $isChildTheme = !empty($meta['is_child_theme']);
        $parentTheme = trim((string) ($meta['parent_theme'] ?? ''));

        $content = "# {$name} Theme Agent Guide\n\n";
        $content .= "Last updated: " . gmdate('Y-m-d') . "\n\n";
        $content .= "## Scope\n";
        $content .= "- This file applies only to `public/theme/{$slug}/`.\n";
        $content .= "- Follow project-wide theme contracts in `public/theme/AGENTS.md`.\n";
        if ($isChildTheme && $parentTheme !== '') {
            $content .= "- This theme is a child theme of `{$parentTheme}`.\n";
        }
        $content .= "\n## Required Files\n";
        $content .= "- `theme.json`\n";
        $content .= "- `vis/wrapper.php`\n";
        $content .= "- `css/style.css`\n";
        $content .= "\n## Safety Rules\n";
        $content .= "- Keep customizations inside this theme directory.\n";
        $content .= "- Do not edit core templates under `private/vis/` for theme-only changes.\n";
        $content .= "- Use escaped brace tags by default; reserve `{raw:...}` for trusted HTML only.\n";

        return $content;
    }

    /**
     * Returns generated `composer.json` content for one public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    private function publicThemeComposerFileContent(array $meta): string
    {
        $slug = strtolower(trim((string) ($meta['slug'] ?? 'theme')));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? 'theme';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            $slug = 'theme';
        }

        $name = trim((string) ($meta['name'] ?? 'Raven Theme'));
        if ($name === '') {
            $name = 'Raven Theme';
        }

        $payload = [
            'name' => 'noveltylanterns/raven-theme-' . $slug,
            'description' => $name . ' public theme package for Raven CMS.',
            'type' => 'project',
            'version' => '0.1.0',
            'license' => 'GPL-3.0-or-later',
            'require' => [
                'php' => '^8.5',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to generate theme composer.json content.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `package.json` content for one public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    private function publicThemePackageFileContent(array $meta): string
    {
        $slug = strtolower(trim((string) ($meta['slug'] ?? 'theme')));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? 'theme';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            $slug = 'theme';
        }

        $name = trim((string) ($meta['name'] ?? 'Raven Theme'));
        if ($name === '') {
            $name = 'Raven Theme';
        }

        $payload = [
            'name' => '@raven/theme-' . $slug,
            'version' => '0.1.0',
            'private' => true,
            'description' => $name . ' frontend theme package for Raven CMS.',
            'scripts' => [
                'build' => 'echo "Add your theme build pipeline here"',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to generate theme package.json content.');
        }

        return $encoded . "\n";
    }

    /**
     * Writes `theme.json` with normalized manifest payload.
     *
     * @param array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $manifest
     */
    private function writePublicThemeManifest(string $manifestPath, array $manifest): void
    {
        $payload = [
            'name' => (string) $manifest['name'],
            'is_child_theme' => (bool) $manifest['is_child_theme'],
            'parent_theme' => (bool) $manifest['is_child_theme'] ? (string) $manifest['parent_theme'] : '',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to build theme manifest JSON.');
        }

        $this->writePublicThemeScaffoldFile($manifestPath, $encoded . "\n");
    }

    /**
     * Writes one scaffold file for a public theme.
     */
    private function writePublicThemeScaffoldFile(string $targetPath, string $content): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create directory: ' . $directory);
        }

        $written = file_put_contents($targetPath, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to write file: ' . $targetPath);
        }

        @chmod($targetPath, 0644);
    }

    /**
     * Copies one directory tree recursively for local scaffold cloning.
     */
    private function copyDirectoryRecursively(string $sourceDirectory, string $targetDirectory): void
    {
        if (!is_dir($sourceDirectory)) {
            throw new \RuntimeException('Clone source directory not found: ' . $sourceDirectory);
        }

        $sourceRoot = realpath($sourceDirectory);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Failed to resolve clone source directory.');
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Failed to create clone target directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            if ($item->isLink()) {
                throw new \RuntimeException('Theme clone source contains symlinks, which are not supported.');
            }

            $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $targetPath = rtrim($targetDirectory, '/\\') . '/' . str_replace('\\', '/', $relativePath);
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException('Failed to create clone directory: ' . $targetPath);
                }
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Failed to create clone directory: ' . $targetDir);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Failed to copy clone file: ' . $relativePath);
            }

            @chmod($targetPath, 0644);
        }
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string>
     */
    private function publicThemeOptions(): array
    {
        $options = PublicThemeRegistry::options($this->publicThemesRoot());
        if ($options === []) {
            // Keep configuration editor usable even when no manifests are present yet.
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
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function activePublicThemeSlug(): string
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
     * Resolves active public theme inheritance chain, child first.
     *
     * @return array<int, string>
     */
    private function activePublicThemeInheritanceChain(string $themeSlug): array
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
    private function activePublicThemeCssSlug(string $themeSlug): string
    {
        foreach ($this->activePublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/css/style.css';
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
     * Returns ordered public template lookup roots, child theme first.
     *
     * @return array<int, string>
     */
    private function publicFallbackTemplateRoots(): array
    {
        $roots = [];
        $themeSlug = $this->activePublicThemeSlug();
        foreach ($this->activePublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $themeViewsRoot = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/views';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        $roots[] = dirname(__DIR__, 3) . '/private/vis';
        return $roots;
    }

    /**
     * Resolves one public fallback template path from ordered roots.
     */
    private function resolvePublicFallbackTemplateFile(string $template): ?string
    {
        $relative = trim($template, '/') . '.php';
        foreach ($this->publicFallbackTemplateRoots() as $root) {
            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Executes one resolved public fallback template file in isolated scope.
     *
     * @param array<string, mixed> $data
     */
    private function renderPublicFallbackTemplateFile(string $file, array $data): string
    {
        $templateTags = new \Raven\Core\View\TemplateTagEngine(dirname(__DIR__, 3) . '/private/tmp/template_tag_cache');
        return $templateTags->renderFile($file, $data);
    }

    /**
     * Site context passed to public fallback templates.
     *
     * @return array<string, string>
     */
    private function publicSiteDataForNotFound(): array
    {
        $publicTheme = $this->activePublicThemeSlug();

        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'current_url' => '',
            'apple_touch_icon' => trim((string) $this->config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $this->config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $this->config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $this->config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $this->config->get('meta.twitter.creator', '')),
            'twitter_image' => trim((string) $this->config->get('meta.twitter.image', '')),
            'og_image' => trim((string) $this->config->get('meta.opengraph.image', '')),
            'og_type' => trim((string) $this->config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $this->config->get('meta.opengraph.locale', 'en_US')),
            'public_theme' => $publicTheme,
            'public_theme_css' => $this->activePublicThemeCssSlug($publicTheme),
        ];
    }

    /**
     * Site context passed to panel views.
     *
     * @return array<string, mixed>
     */
    private function siteData(): array
    {
        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'panel_brand_name' => (string) $this->config->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $this->config->get('panel.brand_logo', ''),
            'category_enabled' => $this->categoryEnabled(),
            'tag_enabled' => $this->tagEnabled(),
        ];
    }
}
