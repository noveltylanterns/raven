<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/lib/routes_panel.php
 * Contact Forms extension panel route and CRUD registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Routing\Router;
use Raven\Ext\ContactFormRepository;
use Raven\Ext\ContactSubmissionRepository;
use Raven\Lib\Archive\Types\Csv;

use function Raven\Lib\Support\redirect;

/**
 * Registers Contact Forms extension routes into the panel router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   extensionServices?: callable(?string=): array<string, mixed>,
 *   extensionDirectory?: string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);

    /** @var callable(string): string $panelUrl */
    $panelUrl = $context['panelUrl'] ?? static fn (string $suffix = ''): string => '/' . ltrim($suffix, '/');

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'default';

    /** @var callable(bool=): array<string, mixed> $panelSiteData */
    $panelSiteData = is_callable($rvn['panel_site_data'] ?? null)
        ? $rvn['panel_site_data']
        : static function (bool $includeDomain = true) use ($rvn): array {
            $site = [
                'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
                'panel_path' => (string) $rvn['config']->get('panel.path', 'panel'),
                'panel_brand_name' => (string) $rvn['config']->get('panel.brand_name', ''),
                'panel_brand_logo' => (string) $rvn['config']->get('panel.brand_logo', ''),
            ];
            if ($includeDomain) {
                $site['domain'] = (string) $rvn['config']->get('site.domain', 'localhost');
            }
            return $site;
        };

    if (!isset($rvn['root'], $rvn['view'], $rvn['config'], $rvn['csrf'])) {
        return;
    }

    /** @var callable(?string=): array<string, mixed> $extensionServices */
    $extensionServices = is_callable($context['extensionServices'] ?? null)
        ? $context['extensionServices']
        : static function (?string $extensionDirectory = null) use ($rvn): array {
            $directory = is_string($extensionDirectory) && trim($extensionDirectory) !== '' ? trim($extensionDirectory) : 'contact';
            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            /** @var mixed $rawServices */
            $rawServices = is_array($rawExtensionServices) ? ($rawExtensionServices[$directory] ?? []) : [];
            return is_array($rawServices) ? $rawServices : [];
        };

    /**
     * Resolves Contact extension repositories only when one Contact route is actually used.
     *
     * @return array{forms: ContactFormRepository, submissions: ContactSubmissionRepository}
     */
    $requireContactRepositories = static function () use ($extensionServices): array {
        $services = $extensionServices('contact');
        $formsService = $services['forms'] ?? null;
        $submissionsService = $services['submissions'] ?? null;
        if (
            !$formsService instanceof ContactFormRepository
            || !$submissionsService instanceof ContactSubmissionRepository
        ) {
            http_response_code(404);
            echo 'Not Found';
            exit;
        }

        return [
            'forms' => $formsService,
            'submissions' => $submissionsService,
        ];
    };

    $csv = new Csv();

    $contactSubmissionsRepository = new class($requireContactRepositories) {
        /** @var \Closure(): array{forms: ContactFormRepository, submissions: ContactSubmissionRepository} */
        private \Closure $resolver;

        /**
         * @param callable(): array{forms: ContactFormRepository, submissions: ContactSubmissionRepository} $resolver
         */
        public function __construct(callable $resolver)
        {
            $this->resolver = \Closure::fromCallable($resolver);
        }

        /**
         * Proxies submission-repository calls through the per-request lazy extension resolver.
         *
         * @param string $name      Repository method name.
         * @param array<int, mixed> $arguments Repository call arguments.
         * @return mixed
         */
        public function __call(string $name, array $arguments): mixed
        {
            $repositories = ($this->resolver)();
            return $repositories['submissions']->$name(...$arguments);
        }
    };

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/contact';
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $listViewFile = $extensionRoot . '/tpl/panel_index.php';
    $editViewFile = $extensionRoot . '/tpl/panel_edit.php';
    $submissionsViewFile = $extensionRoot . '/tpl/panel_submissions.php';

    $indexPath = $panelUrl('/contact');
    $editBasePath = $panelUrl('/contact/edit');
    $submissionsBasePath = $panelUrl('/contact/submissions');
    $submissionsDeletePath = $panelUrl('/contact/submissions/delete');
    $submissionsClearPath = $panelUrl('/contact/submissions/clear');
    $savePath = $panelUrl('/contact/save');
    $deletePath = $panelUrl('/contact/delete');
    $extensionMeta = [
        'name' => 'Contact Forms',
        'version' => '',
        'author' => '',
        'description' => '',
        'docs' => 'https://raven.lanterns.io',
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

                // "docs" is the canonical ext.json key for extension documentation links.
                $docsRaw = trim((string) ($manifestDecoded['docs'] ?? ''));
                if ($docsRaw !== '' && filter_var($docsRaw, FILTER_VALIDATE_URL) !== false) {
                    $docsScheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
                    if (in_array($docsScheme, ['http', 'https'], true)) {
                        $extensionMeta['docs'] = $docsRaw;
                    }
                }
            }
        }
    }

    /**
     * Stores one flash message scoped to Contact Forms extension pages.
     */
    $flash = static function (string $type, string $message): void {
        $_SESSION['_raven_contact_flash_' . $type] = $message;
    };

    /**
     * Returns and clears one flash message scoped to Contact Forms pages.
     */
    $pullFlash = static function (string $type): ?string {
        $key = '_raven_contact_flash_' . $type;
        if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            return null;
        }

        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    };

    /**
     * Reads configured forms from file-backed extension storage.
     *
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   save_mail_locally: bool,
     *   destination: string,
     *   cc: string,
     *   bcc: string,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool,
     *     options: array<int, string>
     *   }>
     * }>
     */
    $loadForms = static function () use ($requireContactRepositories): array {
        $repositories = $requireContactRepositories();
        return $repositories['forms']->listAll();
    };

    /**
     * Persists contact forms into file-backed extension storage.
     *
     * @param array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   save_mail_locally: bool,
     *   destination: string,
     *   cc: string,
     *   bcc: string,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool,
     *     options: array<int, string>
     *   }>
     * }> $forms
     */
    $saveForms = static function (array $forms) use ($requireContactRepositories): void {
        $repositories = $requireContactRepositories();
        $repositories['forms']->replaceAll($forms);
    };

    /**
     * Finds one configured contact form by slug.
     *
     * @return array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   save_mail_locally: bool,
     *   destination: string,
     *   cc: string,
     *   bcc: string,
     *   additional_fields: array<int, array{label: string, name: string, type: string, required: bool, options: array<int, string>}>
     * }|null
     */
    $findFormBySlug = static function (string $slug) use ($loadForms): ?array {
        foreach ($loadForms() as $form) {
            if ((string) ($form['slug'] ?? '') === $slug) {
                return $form;
            }
        }

        return null;
    };

    /**
     * Builds one submissions listing URL with optional query + page state.
     */
    $submissionsListPath = static function (string $slug, string $search = '', int $page = 1) use ($submissionsBasePath): string {
        $path = $submissionsBasePath . '/' . rawurlencode($slug);
        $query = [];
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    };

    /**
     * Parses comma/semicolon-delimited email lists and returns normalized values + invalid entries.
     *
     * @return array{emails: array<int, string>, invalid: array<int, string>}
     */
    $parseEmailList = static function (string $rawValue) use ($rvn): array {
        if (!isset($rvn['input'])) {
            return [
                'emails' => [],
                'invalid' => [],
            ];
        }

        $normalized = $rvn['input']->text($rawValue, 2000);
        if ($normalized === '') {
            return [
                'emails' => [],
                'invalid' => [],
            ];
        }

        $parts = preg_split('/[;,]+/', $normalized) ?: [];
        $emailsMap = [];
        $invalid = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }

            $candidate = trim($part);
            if ($candidate === '') {
                continue;
            }

            $email = $rvn['input']->email($candidate);
            if ($email === null) {
                $invalid[] = $candidate;
                continue;
            }

            $emailsMap[$email] = $email;
        }

        return [
            'emails' => array_values($emailsMap),
            'invalid' => $invalid,
        ];
    };

    /**
     * Renders extension body within shared panel layout.
     *
     * @param array<string, mixed> $viewData
     */
    $renderView = static function (array $viewData) use ($rvn, $currentUserTheme, $panelSiteData): void {
        $viewFile = (string) ($viewData['_view'] ?? '');
        unset($viewData['_view']);
        if ($viewFile === '' || !is_file($viewFile)) {
            http_response_code(500);
            echo 'Contact Forms view template is missing.';
            return;
        }

        // Extension partials render forms directly and require a CSRF hidden field token.
        $csrfField = $rvn['csrf']->field();
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $panelSiteData(),
            'csrfField' => $rvn['csrf']->field(),
            'section' => 'contact',
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', '/contact', static function () use (
        $requirePanelLogin,
        $loadForms,
        $renderView,
        $pullFlash,
        $editBasePath,
        $submissionsBasePath,
        $savePath,
        $deletePath,
        $listViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        $renderView([
            '_view' => $listViewFile,
            'forms' => $loadForms(),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'editBasePath' => $editBasePath,
            'contactSubmissionsBasePath' => $submissionsBasePath,
            'savePath' => $savePath,
            'deletePath' => $deletePath,
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/contact/edit', static function () use (
        $requirePanelLogin,
        $renderView,
        $pullFlash,
        $indexPath,
        $submissionsBasePath,
        $savePath,
        $deletePath,
        $editViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        $renderView([
            '_view' => $editViewFile,
            'formData' => null,
            'formAction' => $savePath,
            'deleteAction' => $deletePath,
            'indexPath' => $indexPath,
            'contactSubmissionsBasePath' => $submissionsBasePath,
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/contact/edit/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $findFormBySlug,
        $renderView,
        $pullFlash,
        $indexPath,
        $submissionsBasePath,
        $savePath,
        $deletePath,
        $editViewFile,
        $rvn,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $rvn['input']->slug((string) ($params['slug'] ?? ''));
        if ($slug === null) {
            redirect($indexPath);
        }

        $formData = $findFormBySlug($slug);
        if ($formData === null) {
            redirect($indexPath);
        }

        $renderView([
            '_view' => $editViewFile,
            'formData' => $formData,
            'formAction' => $savePath,
            'deleteAction' => $deletePath,
            'indexPath' => $indexPath,
            'contactSubmissionsBasePath' => $submissionsBasePath,
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/contact/submissions/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $findFormBySlug,
        $contactSubmissionsRepository,
        $renderView,
        $flash,
        $pullFlash,
        $indexPath,
        $editBasePath,
        $submissionsListPath,
        $submissionsDeletePath,
        $submissionsClearPath,
        $submissionsBasePath,
        $submissionsViewFile,
        $rvn,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $rvn['input']->slug((string) ($params['slug'] ?? ''));
        if ($slug === null) {
            redirect($indexPath);
        }

        $formData = $findFormBySlug($slug);
        if ($formData === null) {
            redirect($indexPath);
        }

        $searchQuery = $rvn['input']->text((string) ($_GET['q'] ?? ''), 160);
        $page = $rvn['input']->int($_GET['page'] ?? 1, 1, 100000) ?? 1;
        $perPage = 50;

        try {
            $offset = ($page - 1) * $perPage;
            $pageResult = $contactSubmissionsRepository->listPageByFormSlug($slug, $perPage, $offset, $searchQuery);
            $totalSubmissions = (int) ($pageResult['total'] ?? 0);
            $submissions = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $totalPages = max(1, (int) ceil($totalSubmissions / $perPage));

            if ($totalSubmissions > 0 && $page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $perPage;
                $pageResult = $contactSubmissionsRepository->listPageByFormSlug($slug, $perPage, $offset, $searchQuery);
                $submissions = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            }
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($indexPath);
        }

        $renderView([
            '_view' => $submissionsViewFile,
            'formData' => $formData,
            'submissions' => $submissions,
            'searchQuery' => $searchQuery,
            'pagination' => [
                'current' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalSubmissions,
                'base_path' => $submissionsListPath($slug, $searchQuery),
            ],
            'indexPath' => $indexPath,
            'editPath' => $editBasePath . '/' . rawurlencode($slug),
            'deleteSubmissionPath' => $submissionsDeletePath,
            'clearSubmissionsPath' => $submissionsClearPath,
            'searchAction' => $submissionsBasePath . '/' . rawurlencode($slug),
            'exportPath' => $submissionsBasePath . '/' . rawurlencode($slug) . '/export'
                . ($searchQuery !== '' ? ('?q=' . rawurlencode($searchQuery)) : ''),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/contact/submissions/{slug}/export', static function (array $params) use (
        $requirePanelLogin,
        $findFormBySlug,
        $contactSubmissionsRepository,
        $csv,
        $flash,
        $indexPath,
        $submissionsListPath,
        $rvn
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $rvn['input']->slug((string) ($params['slug'] ?? ''));
        if ($slug === null) {
            redirect($indexPath);
        }

        if ($findFormBySlug($slug) === null) {
            redirect($indexPath);
        }

        $searchQuery = $rvn['input']->text((string) ($_GET['q'] ?? ''), 160);
        try {
            $rows = $contactSubmissionsRepository->listForExportByFormSlug($slug, $searchQuery);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $safeFileSlug = preg_replace('/[^a-z0-9_-]+/i', '-', $slug) ?? 'contact';
        $safeFileSlug = trim($safeFileSlug, '-');
        if ($safeFileSlug === '') {
            $safeFileSlug = 'contact';
        }

        $csv->streamToOutput(
            'contact-' . $safeFileSlug . '-submissions.csv',
            (static function (array $rows): \Generator {
                foreach ($rows as $row) {
                    yield [
                        (string) ($row['id'] ?? ''),
                        (string) ($row['sender_name'] ?? ''),
                        (string) ($row['sender_email'] ?? ''),
                        (string) ($row['message_text'] ?? ''),
                        (string) ($row['additional_fields_json'] ?? ''),
                        (string) ($row['source_url'] ?? ''),
                        (string) ($row['ip_address'] ?? ''),
                        (string) ($row['hostname'] ?? ''),
                        (string) ($row['user_agent'] ?? ''),
                        (string) ($row['created'] ?? ''),
                    ];
                }
            })($rows),
            ['ID', 'Sender Name', 'Sender Email', 'Message', 'Additional Fields JSON', 'Source URL', 'IP Address', 'Hostname', 'User Agent', 'Created At']
        );
    });

    $router->add('POST', '/contact/submissions/delete', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $contactSubmissionsRepository,
        $flash,
        $indexPath,
        $submissionsListPath,
        $rvn
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$rvn['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $rvn['input']->slug((string) ($_POST['slug'] ?? ''));
        $submissionId = $rvn['input']->int($_POST['submission_id'] ?? null, 1);
        $searchQuery = $rvn['input']->text((string) ($_POST['return_q'] ?? ''), 160);
        $page = $rvn['input']->int($_POST['return_page'] ?? 1, 1, 100000) ?? 1;

        if ($slug === null || $submissionId === null) {
            $flash('error', 'Invalid contact submission request.');
            redirect($indexPath);
        }

        if ($findFormBySlug($slug) === null) {
            $flash('error', 'Selected contact form does not exist.');
            redirect($indexPath);
        }

        try {
            $deleted = $contactSubmissionsRepository->deleteById($slug, $submissionId);
            if ($deleted) {
                $flash('success', 'Submission deleted.');
            } else {
                $flash('error', 'Submission record was not found.');
            }
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($submissionsListPath($slug, $searchQuery, $page));
    });

    $router->add('POST', '/contact/submissions/clear', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $contactSubmissionsRepository,
        $flash,
        $indexPath,
        $submissionsListPath,
        $rvn
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$rvn['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $rvn['input']->slug((string) ($_POST['slug'] ?? ''));
        $searchQuery = $rvn['input']->text((string) ($_POST['return_q'] ?? ''), 160);
        if ($slug === null) {
            $flash('error', 'Invalid form slug.');
            redirect($indexPath);
        }

        if ($findFormBySlug($slug) === null) {
            $flash('error', 'Selected contact form does not exist.');
            redirect($indexPath);
        }

        try {
            $deletedCount = $contactSubmissionsRepository->deleteAllByFormSlug($slug);
            $flash('success', 'Cleared ' . $deletedCount . ' submission(s).');
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($submissionsListPath($slug, $searchQuery, 1));
    });

    $router->add('POST', '/contact/save', static function () use (
        $requirePanelLogin,
        $rvn,
        $loadForms,
        $saveForms,
        $contactSubmissionsRepository,
        $parseEmailList,
        $flash,
        $indexPath,
        $editBasePath
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$rvn['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $name = $rvn['input']->text((string) ($_POST['name'] ?? ''), 160);
        $slug = $rvn['input']->slug((string) ($_POST['slug'] ?? ''));
        $originalSlug = $rvn['input']->slug((string) ($_POST['original_slug'] ?? ''));
        $destinationRaw = $rvn['input']->text((string) ($_POST['destination'] ?? ''), 2000);
        $ccRaw = $rvn['input']->text((string) ($_POST['cc'] ?? ''), 2000);
        $bccRaw = $rvn['input']->text((string) ($_POST['bcc'] ?? ''), 2000);
        $parsedDestination = $parseEmailList($destinationRaw);
        $parsedCc = $parseEmailList($ccRaw);
        $parsedBcc = $parseEmailList($bccRaw);
        $destination = implode(', ', $parsedDestination['emails']);
        $cc = implode(', ', $parsedCc['emails']);
        $bcc = implode(', ', $parsedBcc['emails']);
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $saveMailLocally = isset($_POST['save_mail_locally']) && (string) $_POST['save_mail_locally'] === '1';
        $redirectPath = $originalSlug !== null ? ($editBasePath . '/' . rawurlencode($originalSlug)) : $editBasePath;
        $parseFieldOptions = static function (mixed $rawOptions) use ($rvn): array {
            $optionCandidates = [];
            $rawInput = '';
            if (is_array($rawOptions)) {
                $optionCandidates = $rawOptions;
            } else {
                $rawInput = (string) $rawOptions;
                // Keep newlines for delimiter parsing; only strip non-newline control bytes.
                $rawInput = str_replace("\0", '', $rawInput);
                $rawInput = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawInput) ?? '';
                if (mb_strlen($rawInput) > 4000) {
                    $rawInput = mb_substr($rawInput, 0, 4000);
                }
                $optionCandidates = preg_split('/[\r\n,]+/', $rawInput) ?: [];
            }

            $options = [];
            foreach ($optionCandidates as $optionCandidate) {
                if (!is_scalar($optionCandidate)) {
                    continue;
                }

                $option = trim($rvn['input']->text((string) $optionCandidate, 120));
                if ($option === '' || isset($options[$option])) {
                    continue;
                }

                $options[$option] = $option;
                if (count($options) >= 100) {
                    break;
                }
            }

            $normalizedInput = str_replace(["\r\n", "\r"], "\n", $rawInput);
            $normalizedInput = trim($normalizedInput);
            if ($normalizedInput === '' && $options !== []) {
                $normalizedInput = implode(', ', array_values($options));
            }

            return [
                'options' => array_values($options),
                'input' => $normalizedInput,
            ];
        };

        /** @var mixed $rawAdditionalFields */
        $rawAdditionalFields = $_POST['additional_fields'] ?? [];
        $additionalFields = [];
        $seenAdditionalFieldNames = [];
        if (is_array($rawAdditionalFields)) {
            foreach ($rawAdditionalFields as $rawField) {
                if (!is_array($rawField)) {
                    continue;
                }

                $fieldLabel = $rvn['input']->text((string) ($rawField['label'] ?? ''), 120);
                $fieldNameInput = strtolower($rvn['input']->text((string) ($rawField['name'] ?? ''), 120));
                $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldNameInput) ?? '';
                $fieldName = trim($fieldName, '_');
                if ($fieldName === '' && $fieldLabel !== '') {
                    $fieldName = strtolower($fieldLabel);
                    $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldName) ?? '';
                    $fieldName = trim($fieldName, '_');
                }

                $fieldType = strtolower($rvn['input']->text((string) ($rawField['type'] ?? 'text'), 20));
                if ($fieldType === 'dropdown') {
                    $fieldType = 'select';
                }
                if (!in_array($fieldType, ['text', 'email', 'textarea', 'radio', 'checkbox', 'select'], true)) {
                    $fieldType = 'text';
                }
                $fieldOptionsResult = $parseFieldOptions($rawField['options'] ?? '');
                $fieldOptions = is_array($fieldOptionsResult['options'] ?? null)
                    ? (array) $fieldOptionsResult['options']
                    : [];
                $fieldOptionsInput = trim((string) ($fieldOptionsResult['input'] ?? ''));

                $required = isset($rawField['required']) && (string) $rawField['required'] === '1';

                if ($fieldLabel === '' && $fieldName === '') {
                    // Empty builder rows are ignored for easier UI editing.
                    continue;
                }

                if ($fieldLabel === '' || $fieldName === '') {
                    $flash('error', 'Each additional field must include both label and field name.');
                    redirect($redirectPath);
                }

                if (isset($seenAdditionalFieldNames[$fieldName])) {
                    $flash('error', 'Additional field names must be unique.');
                    redirect($redirectPath);
                }
                if (in_array($fieldType, ['radio', 'select'], true) && $fieldOptions === []) {
                    $flash('error', 'Radio and dropdown additional fields must include one or more options.');
                    redirect($redirectPath);
                }

                $seenAdditionalFieldNames[$fieldName] = true;
                $additionalFields[] = [
                    'label' => $fieldLabel,
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'required' => $required,
                    'options' => $fieldOptions,
                    'options_input' => $fieldOptionsInput,
                ];
            }
        }

        if ($name === '' || $slug === null) {
            $flash('error', 'Name and a valid slug are required.');
            redirect($redirectPath);
        }

        if ($destination === '' || $parsedDestination['invalid'] !== []) {
            $flash('error', 'Destination must contain one or more valid email addresses, delimited with commas or semicolons.');
            redirect($redirectPath);
        }

        if ($parsedCc['invalid'] !== []) {
            $flash('error', 'CC must contain only valid email addresses, delimited with commas or semicolons.');
            redirect($redirectPath);
        }

        if ($parsedBcc['invalid'] !== []) {
            $flash('error', 'BCC must contain only valid email addresses, delimited with commas or semicolons.');
            redirect($redirectPath);
        }

        $forms = $loadForms();
        $updated = false;
        $updatedFromSlug = null;

        foreach ($forms as $index => $form) {
            $existingSlug = (string) ($form['slug'] ?? '');
            if ($existingSlug === $slug && $existingSlug !== (string) $originalSlug) {
                $flash('error', 'A contact form with that slug already exists.');
                redirect($redirectPath);
            }

            if ($originalSlug !== null && $existingSlug === $originalSlug) {
                $updatedFromSlug = $existingSlug;
                $forms[$index] = [
                    'name' => $name,
                    'slug' => $slug,
                    'enabled' => $enabled,
                    'save_mail_locally' => $saveMailLocally,
                    'destination' => $destination,
                    'cc' => $cc,
                    'bcc' => $bcc,
                    'additional_fields' => $additionalFields,
                ];
                $updated = true;
            }
        }

        if (!$updated) {
            $forms[] = [
                'name' => $name,
                'slug' => $slug,
                'enabled' => $enabled,
                'save_mail_locally' => $saveMailLocally,
                'destination' => $destination,
                'cc' => $cc,
                'bcc' => $bcc,
                'additional_fields' => $additionalFields,
            ];
        }

        try {
            $saveForms($forms);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($redirectPath);
        }

        if ($updated && $updatedFromSlug !== null) {
            try {
                $contactSubmissionsRepository->syncFormIdentity($updatedFromSlug, $slug);
            } catch (RuntimeException $exception) {
                $flash('error', 'Form saved but submission metadata sync failed: ' . $exception->getMessage());
                redirect($editBasePath . '/' . rawurlencode($slug));
            }
        }

        $flash('success', 'Contact form saved.');
        redirect($editBasePath . '/' . rawurlencode($slug));
    });

    $router->add('POST', '/contact/delete', static function () use (
        $requirePanelLogin,
        $rvn,
        $loadForms,
        $saveForms,
        $contactSubmissionsRepository,
        $flash,
        $indexPath
    ): void {
        $requirePanelLogin();

        if (!isset($rvn['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$rvn['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $rvn['input']->slug((string) ($_POST['slug'] ?? ''));
        if ($slug === null) {
            $flash('error', 'Invalid form slug.');
            redirect($indexPath);
        }

        $forms = array_values(array_filter($loadForms(), static function (array $form) use ($slug): bool {
            return (string) ($form['slug'] ?? '') !== $slug;
        }));

        try {
            $saveForms($forms);
            $contactSubmissionsRepository->deleteAllByFormSlug($slug);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($indexPath);
        }

        $flash('success', 'Contact form deleted.');
        redirect($indexPath);
    });
};
