<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/lib/routes_panel.php
 * Signup Sheets extension panel route and CRUD registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Ext\SignupFormRepository;
use Raven\Ext\SignupSubmissionRepository;
use Raven\Lib\Routing\Router;

use function Raven\Lib\Support\redirect;

/**
 * Registers Signup Sheets extension routes into the panel router.
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
            $directory = is_string($extensionDirectory) && trim($extensionDirectory) !== '' ? trim($extensionDirectory) : 'signups';
            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            /** @var mixed $rawServices */
            $rawServices = is_array($rawExtensionServices) ? ($rawExtensionServices[$directory] ?? []) : [];
            return is_array($rawServices) ? $rawServices : [];
        };

    /**
     * Resolves Signup Sheets repositories only when one signup route is actually used.
     *
     * @return array{forms: SignupFormRepository, submissions: SignupSubmissionRepository}
     */
    $requireSignupRepositories = static function () use ($extensionServices): array {
        $services = $extensionServices('signups');
        $formsService = $services['forms'] ?? null;
        $submissionsService = $services['submissions'] ?? null;
        if (
            !$formsService instanceof SignupFormRepository
            || !$submissionsService instanceof SignupSubmissionRepository
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

    $signupsRepository = new class($requireSignupRepositories) {
        /** @var \Closure(): array{forms: SignupFormRepository, submissions: SignupSubmissionRepository} */
        private \Closure $resolver;

        /**
         * @param callable(): array{forms: SignupFormRepository, submissions: SignupSubmissionRepository} $resolver
         */
        public function __construct(callable $resolver)
        {
            $this->resolver = \Closure::fromCallable($resolver);
        }

        /**
         * Proxies signup-submission repository calls through the lazy extension resolver.
         *
         * @param string $name Repository method name.
         * @param array<int, mixed> $arguments Repository call arguments.
         * @return mixed
         */
        public function __call(string $name, array $arguments): mixed
        {
            $repositories = ($this->resolver)();
            return $repositories['submissions']->$name(...$arguments);
        }
    };

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/signups';
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $listViewFile = $extensionRoot . '/tpl/panel_index.php';
    $editViewFile = $extensionRoot . '/tpl/panel_edit.php';
    $submissionsViewFile = $extensionRoot . '/tpl/panel_signups.php';

    $indexPath = $panelUrl('/signups');
    $editBasePath = $panelUrl('/signups/edit');
    $submissionsBasePath = $panelUrl('/signups/submissions');
    $submissionsDeletePath = $panelUrl('/signups/submissions/delete');
    $submissionsClearPath = $panelUrl('/signups/submissions/clear');
    $submissionsImportPath = $panelUrl('/signups/submissions/import');
    $savePath = $panelUrl('/signups/save');
    $deletePath = $panelUrl('/signups/delete');
    $extensionMeta = [
        'name' => 'Signup Sheets',
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
     * Stores one flash message scoped to Signup Sheets pages.
     */
    $flash = static function (string $type, string $message): void {
        $_SESSION['_raven_signups_flash_' . $type] = $message;
    };

    /**
     * Returns and clears one flash message scoped to Signup Sheets pages.
     */
    $pullFlash = static function (string $type): ?string {
        $key = '_raven_signups_flash_' . $type;
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
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool,
     *     options: array<int, string>
     *   }>
     * }>
     */
    $loadForms = static function () use ($requireSignupRepositories): array {
        $repositories = $requireSignupRepositories();
        return $repositories['forms']->listAll();
    };

    /**
     * Persists signup-sheet form definitions into file-backed extension storage.
     *
     * @param array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool,
     *     options: array<int, string>
     *   }>
     * }> $forms
     */
    $saveForms = static function (array $forms) use ($requireSignupRepositories): void {
        $repositories = $requireSignupRepositories();
        $repositories['forms']->replaceAll($forms);
    };

    /**
     * Finds one configured signup sheet form by slug.
     *
     * @return array{name: string, slug: string, enabled: bool, additional_fields: array<int, array{label: string, name: string, type: string, required: bool, options: array<int, string>}>}|null
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
     * Renders extension body within shared panel layout.
     *
     * @param array<string, mixed> $viewData
     */
    $renderView = static function (array $viewData) use ($rvn, $currentUserTheme, $panelSiteData): void {
        $viewFile = (string) ($viewData['_view'] ?? '');
        unset($viewData['_view']);
        if ($viewFile === '' || !is_file($viewFile)) {
            http_response_code(500);
            echo 'Signup Sheets view template is missing.';
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
            'section' => 'signups',
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', '/signups', static function () use (
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
            'signupsBasePath' => $submissionsBasePath,
            'savePath' => $savePath,
            'deletePath' => $deletePath,
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/signups/edit', static function () use (
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
            'signupsBasePath' => $submissionsBasePath,
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/signups/edit/{slug}', static function (array $params) use (
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
            'signupsBasePath' => $submissionsBasePath,
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/signups/submissions/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $findFormBySlug,
        $signupsRepository,
        $renderView,
        $flash,
        $pullFlash,
        $indexPath,
        $editBasePath,
        $submissionsListPath,
        $submissionsDeletePath,
        $submissionsClearPath,
        $submissionsImportPath,
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
            $pageResult = $signupsRepository->listPageByFormSlug($slug, $perPage, $offset, $searchQuery);
            $totalSignups = (int) ($pageResult['total'] ?? 0);
            $signups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $totalPages = max(1, (int) ceil($totalSignups / $perPage));

            if ($totalSignups > 0 && $page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $perPage;
                $pageResult = $signupsRepository->listPageByFormSlug($slug, $perPage, $offset, $searchQuery);
                $signups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            }
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($indexPath);
        }

        $renderView([
            '_view' => $submissionsViewFile,
            'formData' => $formData,
            'signups' => $signups,
            'searchQuery' => $searchQuery,
            'pagination' => [
                'current' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalSignups,
                'base_path' => $submissionsListPath($slug, $searchQuery),
            ],
            'indexPath' => $indexPath,
            'editPath' => $editBasePath . '/' . rawurlencode($slug),
            'deleteSignupPath' => $submissionsDeletePath,
            'clearSignupsPath' => $submissionsClearPath,
            'importPath' => $submissionsImportPath,
            'searchAction' => $submissionsBasePath . '/' . rawurlencode($slug),
            'exportPath' => $submissionsBasePath . '/' . rawurlencode($slug) . '/export'
                . ($searchQuery !== '' ? ('?q=' . rawurlencode($searchQuery)) : ''),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'extensionMeta' => $extensionMeta,
        ]);
    });

    $router->add('GET', '/signups/submissions/{slug}/export', static function (array $params) use (
        $requirePanelLogin,
        $findFormBySlug,
        $signupsRepository,
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
            $rows = $signupsRepository->listForExportByFormSlug($slug, $searchQuery);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $safeFileSlug = preg_replace('/[^a-z0-9_-]+/i', '-', $slug) ?? 'signups';
        $safeFileSlug = trim($safeFileSlug, '-');
        if ($safeFileSlug === '') {
            $safeFileSlug = 'signups';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="signups-' . $safeFileSlug . '-submissions.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['ID', 'Email', 'Display Name', 'Country', 'Additional Fields JSON', 'Source URL', 'IP Address', 'Hostname', 'User Agent', 'Created At']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['id'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (string) ($row['country'] ?? ''),
                (string) ($row['additional_fields_json'] ?? ''),
                (string) ($row['source_url'] ?? ''),
                (string) ($row['ip_address'] ?? ''),
                (string) ($row['hostname'] ?? ''),
                (string) ($row['user_agent'] ?? ''),
                (string) ($row['created'] ?? ''),
            ]);
        }
        fclose($stream);
    });

    $router->add('POST', '/signups/submissions/import', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $signupsRepository,
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
            $flash('error', 'Selected signup sheet form does not exist.');
            redirect($indexPath);
        }

        /** @var mixed $rawUpload */
        $rawUpload = $_FILES['import_csv'] ?? null;
        if (!is_array($rawUpload)) {
            $flash('error', 'Please choose a CSV file to import.');
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $uploadError = (int) ($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessage = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'CSV upload exceeds server upload size limits.',
                UPLOAD_ERR_PARTIAL => 'CSV upload was only partially received.',
                UPLOAD_ERR_NO_FILE => 'Please choose a CSV file to import.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded CSV file.',
                UPLOAD_ERR_EXTENSION => 'A server extension blocked CSV upload.',
                default => 'CSV upload failed with an unknown error.',
            };
            $flash('error', $uploadMessage);
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $tmpPath = trim((string) ($rawUpload['tmp_name'] ?? ''));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            $flash('error', 'Uploaded CSV could not be validated as an upload.');
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $originalName = strtolower(trim((string) ($rawUpload['name'] ?? '')));
        if ($originalName !== '' && !str_ends_with($originalName, '.csv')) {
            $flash('error', 'Signup submissions import currently supports .csv files only.');
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $maxImportBytes = 10 * 1024 * 1024;
        $uploadSize = max(0, (int) ($rawUpload['size'] ?? 0));
        if ($uploadSize > $maxImportBytes) {
            $flash('error', 'CSV import file exceeds the 10MB limit.');
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $stream = fopen($tmpPath, 'rb');
        if (!is_resource($stream)) {
            $flash('error', 'Failed to open uploaded CSV file.');
            redirect($submissionsListPath($slug, $searchQuery, 1));
        }

        $normalizeHeader = static function (string $value): string {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
            return trim($value, '_');
        };

        $headerMap = null;
        $fileRowNumber = 0;
        $processedRows = 0;
        $maxRows = 10000;
        $importedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        $errorCount = 0;
        $rowErrors = [];
        $reachedRowLimit = false;

        while (($rawRow = fgetcsv($stream)) !== false) {
            $fileRowNumber++;

            if (!is_array($rawRow)) {
                continue;
            }

            $row = [];
            foreach ($rawRow as $index => $cell) {
                $cellText = is_string($cell) ? trim($cell) : '';
                if ($index === 0) {
                    $cellText = preg_replace('/^\xEF\xBB\xBF/', '', $cellText) ?? $cellText;
                }
                $row[] = $cellText;
            }

            $hasAnyCell = false;
            foreach ($row as $cellText) {
                if ($cellText !== '') {
                    $hasAnyCell = true;
                    break;
                }
            }
            if (!$hasAnyCell) {
                continue;
            }

            if ($headerMap === null) {
                $normalizedHeaders = array_map($normalizeHeader, $row);
                $hasEmailHeader = in_array('email', $normalizedHeaders, true);
                $hasDisplayHeader = in_array('display_name', $normalizedHeaders, true)
                    || in_array('display', $normalizedHeaders, true)
                    || in_array('displayname', $normalizedHeaders, true)
                    || in_array('name', $normalizedHeaders, true);

                if ($hasEmailHeader && $hasDisplayHeader) {
                    $findHeaderIndex = static function (array $headers, array $aliases): ?int {
                        foreach ($aliases as $alias) {
                            $index = array_search($alias, $headers, true);
                            if ($index !== false) {
                                return (int) $index;
                            }
                        }

                        return null;
                    };

                    $headerMap = [
                        'email' => $findHeaderIndex($normalizedHeaders, ['email']),
                        'display_name' => $findHeaderIndex($normalizedHeaders, ['display_name', 'display', 'displayname', 'name']),
                        'country' => $findHeaderIndex($normalizedHeaders, ['country']),
                        'additional_fields_json' => $findHeaderIndex($normalizedHeaders, ['additional_fields_json', 'additional_fields', 'additional_json', 'additional']),
                        'source_url' => $findHeaderIndex($normalizedHeaders, ['source_url', 'source', 'url']),
                        'ip_address' => $findHeaderIndex($normalizedHeaders, ['ip_address', 'ip']),
                        'hostname' => $findHeaderIndex($normalizedHeaders, ['hostname', 'host']),
                        'user_agent' => $findHeaderIndex($normalizedHeaders, ['user_agent', 'useragent', 'ua']),
                        'created' => $findHeaderIndex($normalizedHeaders, [
                            'created',
                            'created_at',
                            'submitted_at',
                            'submission_date',
                            'submitted_on',
                            'timestamp',
                        ]),
                    ];
                    continue;
                }

                // Fallback format: exported Raven CSV row order (with optional leading ID column).
                $headerMap = [];
            }

            $processedRows++;
            if ($processedRows > $maxRows) {
                $reachedRowLimit = true;
                break;
            }

            $fieldValue = static function (array $rowData, ?int $index): string {
                if ($index === null || !array_key_exists($index, $rowData)) {
                    return '';
                }

                return trim((string) $rowData[$index]);
            };

            if ($headerMap !== []) {
                $rawEmail = $fieldValue($row, $headerMap['email'] ?? null);
                $rawDisplayName = $fieldValue($row, $headerMap['display_name'] ?? null);
                $rawCountry = $fieldValue($row, $headerMap['country'] ?? null);
                $rawAdditionalFieldsJson = $fieldValue($row, $headerMap['additional_fields_json'] ?? null);
                $rawSourceUrl = $fieldValue($row, $headerMap['source_url'] ?? null);
                $rawIpAddress = $fieldValue($row, $headerMap['ip_address'] ?? null);
                $rawHostname = $fieldValue($row, $headerMap['hostname'] ?? null);
                $rawUserAgent = $fieldValue($row, $headerMap['user_agent'] ?? null);
                $rawCreatedAt = $fieldValue($row, $headerMap['created'] ?? null);
            } else {
                $emailIndex = 0;
                if (isset($row[1]) && $rvn['input']->email((string) $row[1]) !== null) {
                    $emailIndex = 1;
                }

                $rawEmail = $fieldValue($row, $emailIndex);
                $rawDisplayName = $fieldValue($row, $emailIndex + 1);
                $rawCountry = $fieldValue($row, $emailIndex + 2);
                $rawAdditionalFieldsJson = $fieldValue($row, $emailIndex + 3);
                $rawSourceUrl = $fieldValue($row, $emailIndex + 4);
                $rawIpAddress = $fieldValue($row, $emailIndex + 5);
                $rawHostname = $fieldValue($row, $emailIndex + 6);
                $rawUserAgent = $fieldValue($row, $emailIndex + 7);
                $rawCreatedAt = $fieldValue($row, $emailIndex + 8);
            }

            $email = $rvn['input']->email($rawEmail);
            $displayName = $rvn['input']->text($rawDisplayName, 160);
            $country = strtolower($rvn['input']->text($rawCountry, 16));
            if ($email === null || $displayName === '' || $country === '') {
                $invalidCount++;
                if (count($rowErrors) < 5) {
                    $rowErrors[] = 'Row ' . $fileRowNumber . ': required values are missing (email, display name, country).';
                }
                continue;
            }

            $sourceUrl = $rvn['input']->text($rawSourceUrl, 2048);
            $ipAddress = $rvn['input']->text($rawIpAddress, 45);
            if ($ipAddress !== '' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                $ipAddress = '';
            }

            $hostname = strtolower($rvn['input']->text($rawHostname, 255));
            $userAgent = $rvn['input']->text($rawUserAgent, 500);
            $createdAt = $rvn['input']->text($rawCreatedAt, 120);

            $additionalFieldsJson = '[]';
            $rawAdditionalFieldsJson = $rvn['input']->text($rawAdditionalFieldsJson, 20000);
            if ($rawAdditionalFieldsJson !== '') {
                /** @var mixed $decodedAdditionalFields */
                $decodedAdditionalFields = json_decode($rawAdditionalFieldsJson, true);
                if (is_array($decodedAdditionalFields)) {
                    $encodedAdditionalFields = json_encode(
                        $decodedAdditionalFields,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                    if (is_string($encodedAdditionalFields) && $encodedAdditionalFields !== '') {
                        $additionalFieldsJson = $rvn['input']->text($encodedAdditionalFields, 20000);
                    }
                }
            }

            try {
                $signupsRepository->create([
                    'form_slug' => $slug,
                    'email' => (string) $email,
                    'display_name' => $displayName,
                    'country' => $country,
                    'additional_fields_json' => $additionalFieldsJson,
                    'source_url' => $sourceUrl,
                    'ip_address' => $ipAddress !== '' ? $ipAddress : null,
                    'hostname' => $hostname !== '' ? $hostname : null,
                    'user_agent' => $userAgent !== '' ? $userAgent : null,
                    'created' => $createdAt,
                ]);
                $importedCount++;
            } catch (RuntimeException $exception) {
                $message = trim($exception->getMessage());
                if (str_contains(strtolower($message), 'already signed up')) {
                    $duplicateCount++;
                    continue;
                }

                $errorCount++;
                if (count($rowErrors) < 5) {
                    $rowErrors[] = 'Row ' . $fileRowNumber . ': ' . ($message !== '' ? $message : 'Failed to import submission.');
                }
            }
        }

        fclose($stream);

        $skippedCount = $duplicateCount + $invalidCount + $errorCount;

        if ($importedCount > 0) {
            $summary = 'Imported ' . $importedCount . ' submission(s) from CSV.';
            if ($skippedCount > 0) {
                $skipParts = [];
                if ($duplicateCount > 0) {
                    $skipParts[] = $duplicateCount . ' duplicate';
                }
                if ($invalidCount > 0) {
                    $skipParts[] = $invalidCount . ' invalid';
                }
                if ($errorCount > 0) {
                    $skipParts[] = $errorCount . ' error';
                }
                $summary .= ' Skipped ' . $skippedCount . ' row(s) (' . implode(', ', $skipParts) . ').';
            }
            if ($reachedRowLimit) {
                $summary .= ' Processing stopped after ' . $maxRows . ' data rows.';
            }

            $flash('success', $summary);
        } else {
            $message = 'No submissions were imported.';
            if ($skippedCount > 0) {
                $message .= ' Skipped ' . $skippedCount . ' row(s).';
            }
            if ($reachedRowLimit) {
                $message .= ' Processing stopped after ' . $maxRows . ' data rows.';
            }

            if ($rowErrors !== []) {
                $message .= ' ' . implode(' ', $rowErrors);
            }

            $flash('error', trim($message));
        }

        if ($importedCount > 0 && $rowErrors !== []) {
            $flash('error', implode(' ', $rowErrors));
        }

        redirect($submissionsListPath($slug, $searchQuery, 1));
    });

    $router->add('POST', '/signups/submissions/delete', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $signupsRepository,
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
        $signupId = $rvn['input']->int($_POST['signup_id'] ?? null, 1);
        $searchQuery = $rvn['input']->text((string) ($_POST['return_q'] ?? ''), 160);
        $page = $rvn['input']->int($_POST['return_page'] ?? 1, 1, 100000) ?? 1;

        if ($slug === null || $signupId === null) {
            $flash('error', 'Invalid signup submission request.');
            redirect($indexPath);
        }

        if ($findFormBySlug($slug) === null) {
            $flash('error', 'Selected signup sheet form does not exist.');
            redirect($indexPath);
        }

        try {
            $deleted = $signupsRepository->deleteById($slug, $signupId);
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

    $router->add('POST', '/signups/submissions/clear', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $signupsRepository,
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
            $flash('error', 'Selected signup sheet form does not exist.');
            redirect($indexPath);
        }

        try {
            $deletedCount = $signupsRepository->deleteAllByFormSlug($slug);
            $flash('success', 'Cleared ' . $deletedCount . ' submission(s).');
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($submissionsListPath($slug, $searchQuery, 1));
    });

    $router->add('POST', '/signups/save', static function () use (
        $requirePanelLogin,
        $rvn,
        $loadForms,
        $saveForms,
        $signupsRepository,
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
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
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

        $forms = $loadForms();
        $updated = false;
        $updatedFromSlug = null;

        foreach ($forms as $index => $form) {
            $existingSlug = (string) ($form['slug'] ?? '');
            if ($existingSlug === $slug && $existingSlug !== (string) $originalSlug) {
                $flash('error', 'A signup sheet form with that slug already exists.');
                redirect($redirectPath);
            }

            if ($originalSlug !== null && $existingSlug === $originalSlug) {
                $updatedFromSlug = $existingSlug;
                $forms[$index] = [
                    'name' => $name,
                    'slug' => $slug,
                    'enabled' => $enabled,
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
                $signupsRepository->syncFormIdentity($updatedFromSlug, $slug);
            } catch (RuntimeException $exception) {
                $flash('error', 'Form saved but submission metadata sync failed: ' . $exception->getMessage());
                redirect($editBasePath . '/' . rawurlencode($slug));
            }
        }

        $flash('success', 'Signup sheet form saved.');
        redirect($editBasePath . '/' . rawurlencode($slug));
    });

    $router->add('POST', '/signups/delete', static function () use (
        $requirePanelLogin,
        $rvn,
        $loadForms,
        $saveForms,
        $signupsRepository,
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
            $signupsRepository->deleteAllByFormSlug($slug);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($indexPath);
        }

        $flash('success', 'Signup sheet form deleted.');
        redirect($indexPath);
    });
};
