<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/panel_routes.php
 * Signup Sheets extension panel route and CRUD registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Auth\PanelAccess;
use Raven\Core\Routing\Router;
use Raven\Repository\SignupFormRepository;
use Raven\Repository\WaitlistSignupRepository;

use function Raven\Core\Support\redirect;

/**
 * Registers Signup Sheets extension routes into the panel router.
 *
 * @param array{
 *   app: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   extensionDirectory?: string,
 *   extensionRequiredPermissionBit?: int,
 *   extensionPermissionOptions?: array<int, string>,
 *   setExtensionPermissionPath?: string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $app */
    $app = (array) ($context['app'] ?? []);

    /** @var callable(string): string $panelUrl */
    $panelUrl = $context['panelUrl'] ?? static fn (string $suffix = ''): string => '/' . ltrim($suffix, '/');

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'default';

    if (!isset($app['root'], $app['view'], $app['config'], $app['csrf'], $app['signup_forms'], $app['signup_submissions'])) {
        return;
    }

    if (!$app['signup_forms'] instanceof SignupFormRepository || !$app['signup_submissions'] instanceof WaitlistSignupRepository) {
        return;
    }
    $signupFormsRepository = $app['signup_forms'];
    $signupsRepository = $app['signup_submissions'];

    $extensionRoot = rtrim((string) $app['root'], '/') . '/private/ext/signups';
    $extensionManifestFile = $extensionRoot . '/extension.json';
    $listViewFile = $extensionRoot . '/views/panel_index.php';
    $editViewFile = $extensionRoot . '/views/panel_edit.php';
    $submissionsViewFile = $extensionRoot . '/views/panel_signups.php';

    $indexPath = $panelUrl('/signups');
    $editBasePath = $panelUrl('/signups/edit');
    $submissionsBasePath = $panelUrl('/signups/submissions');
    $submissionsDeletePath = $panelUrl('/signups/submissions/delete');
    $submissionsClearPath = $panelUrl('/signups/submissions/clear');
    $submissionsImportPath = $panelUrl('/signups/submissions/import');
    $savePath = $panelUrl('/signups/save');
    $deletePath = $panelUrl('/signups/delete');
    $setExtensionPermissionPath = trim((string) ($context['setExtensionPermissionPath'] ?? $panelUrl('/extensions/permission')));
    if ($setExtensionPermissionPath === '') {
        $setExtensionPermissionPath = $panelUrl('/extensions/permission');
    }
    $extensionDirectory = trim((string) ($context['extensionDirectory'] ?? 'signups'));
    if ($extensionDirectory === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $extensionDirectory) !== 1) {
        $extensionDirectory = 'signups';
    }
    $permissionOptions = [
        PanelAccess::PANEL_LOGIN => 'Access Dashboard',
        PanelAccess::MANAGE_CONTENT => 'Manage Content',
        PanelAccess::MANAGE_TAXONOMY => 'Manage Taxonomy',
        PanelAccess::MANAGE_USERS => 'Manage Users',
        PanelAccess::MANAGE_GROUPS => 'Manage Groups',
        PanelAccess::MANAGE_CONFIGURATION => 'Manage System Configuration',
    ];
    /** @var mixed $rawPermissionOptions */
    $rawPermissionOptions = $context['extensionPermissionOptions'] ?? [];
    if (is_array($rawPermissionOptions)) {
        foreach ($rawPermissionOptions as $bit => $label) {
            $parsedBit = is_int($bit) ? $bit : (is_numeric((string) $bit) ? (int) $bit : null);
            if ($parsedBit === null || !isset($permissionOptions[$parsedBit])) {
                continue;
            }

            $labelText = trim((string) $label);
            if ($labelText !== '') {
                $permissionOptions[$parsedBit] = $labelText;
            }
        }
    }
    $extensionRequiredPermissionBit = (int) ($context['extensionRequiredPermissionBit'] ?? PanelAccess::PANEL_LOGIN);
    if (!isset($permissionOptions[$extensionRequiredPermissionBit])) {
        $extensionRequiredPermissionBit = PanelAccess::PANEL_LOGIN;
    }
    $permissionOptionList = [];
    foreach ($permissionOptions as $bit => $label) {
        $permissionOptionList[] = [
            'bit' => $bit,
            'label' => $label,
        ];
    }
    $extensionMeta = [
        'name' => 'Signup Sheets',
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

                $docsUrlRaw = trim((string) ($manifestDecoded['homepage'] ?? ''));
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
     * Reads configured forms from DB-backed extension storage.
     *
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool
     *   }>
     * }>
     */
    $loadForms = static function () use ($signupFormsRepository): array {
        return $signupFormsRepository->listAll();
    };

    /**
     * Persists signup-sheet form definitions into DB-backed extension storage.
     *
     * @param array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool
     *   }>
     * }> $forms
     */
    $saveForms = static function (array $forms) use ($signupFormsRepository): void {
        $signupFormsRepository->replaceAll($forms);
    };

    /**
     * Finds one configured signup sheet form by slug.
     *
     * @return array{name: string, slug: string, enabled: bool, additional_fields: array<int, array{label: string, name: string, type: string, required: bool}>}|null
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
    $renderView = static function (array $viewData) use ($app, $currentUserTheme): void {
        $viewFile = (string) ($viewData['_view'] ?? '');
        unset($viewData['_view']);
        if ($viewFile === '' || !is_file($viewFile)) {
            http_response_code(500);
            echo 'Signup Sheets view template is missing.';
            return;
        }

        // Extension partials render forms directly and require a CSRF hidden field token.
        $csrfField = $app['csrf']->field();
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $app['view']->render('layouts/panel', [
            'site' => [
                'name' => (string) $app['config']->get('site.name', 'Raven CMS'),
                'panel_path' => (string) $app['config']->get('panel.path', 'panel'),
            ],
            'csrfField' => $app['csrf']->field(),
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
        $extensionMeta,
        $extensionDirectory,
        $permissionOptionList,
        $extensionRequiredPermissionBit,
        $setExtensionPermissionPath
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
            'extensionDirectory' => $extensionDirectory,
            'extensionPermissionOptions' => $permissionOptionList,
            'extensionRequiredPermissionBit' => $extensionRequiredPermissionBit,
            'extensionPermissionAction' => $setExtensionPermissionPath,
            'extensionPermissionRedirect' => '/signups',
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
        $app,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $app['input']->slug((string) ($params['slug'] ?? ''));
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
        $app,
        $extensionMeta
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $app['input']->slug((string) ($params['slug'] ?? ''));
        if ($slug === null) {
            redirect($indexPath);
        }

        $formData = $findFormBySlug($slug);
        if ($formData === null) {
            redirect($indexPath);
        }

        $searchQuery = $app['input']->text((string) ($_GET['q'] ?? ''), 160);
        $page = $app['input']->int($_GET['page'] ?? 1, 1, 100000) ?? 1;
        $perPage = 50;

        try {
            $totalSignups = $signupsRepository->countByFormSlug($slug, $searchQuery);
            $totalPages = max(1, (int) ceil($totalSignups / $perPage));
            if ($totalSignups > 0 && $page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $signups = $signupsRepository->listByFormSlug($slug, $perPage, $offset, $searchQuery);
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
        $app
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        $slug = $app['input']->slug((string) ($params['slug'] ?? ''));
        if ($slug === null) {
            redirect($indexPath);
        }

        if ($findFormBySlug($slug) === null) {
            redirect($indexPath);
        }

        $searchQuery = $app['input']->text((string) ($_GET['q'] ?? ''), 160);
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
                (string) ($row['created_at'] ?? ''),
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
        $app
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $app['input']->slug((string) ($_POST['slug'] ?? ''));
        $searchQuery = $app['input']->text((string) ($_POST['return_q'] ?? ''), 160);
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
                        'created_at' => $findHeaderIndex($normalizedHeaders, ['created_at', 'created', 'submitted_at']),
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
                $rawCreatedAt = $fieldValue($row, $headerMap['created_at'] ?? null);
            } else {
                $emailIndex = 0;
                if (isset($row[1]) && $app['input']->email((string) $row[1]) !== null) {
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

            $email = $app['input']->email($rawEmail);
            $displayName = $app['input']->text($rawDisplayName, 160);
            $country = strtolower($app['input']->text($rawCountry, 16));
            if ($email === null || $displayName === '' || $country === '') {
                $invalidCount++;
                if (count($rowErrors) < 5) {
                    $rowErrors[] = 'Row ' . $fileRowNumber . ': required values are missing (email, display name, country).';
                }
                continue;
            }

            $sourceUrl = $app['input']->text($rawSourceUrl, 2048);
            $ipAddress = $app['input']->text($rawIpAddress, 45);
            if ($ipAddress !== '' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                $ipAddress = '';
            }

            $hostname = strtolower($app['input']->text($rawHostname, 255));
            $userAgent = $app['input']->text($rawUserAgent, 500);
            $createdAt = $app['input']->text($rawCreatedAt, 32);

            $additionalFieldsJson = '[]';
            $rawAdditionalFieldsJson = $app['input']->text($rawAdditionalFieldsJson, 20000);
            if ($rawAdditionalFieldsJson !== '') {
                /** @var mixed $decodedAdditionalFields */
                $decodedAdditionalFields = json_decode($rawAdditionalFieldsJson, true);
                if (is_array($decodedAdditionalFields)) {
                    $encodedAdditionalFields = json_encode(
                        $decodedAdditionalFields,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                    if (is_string($encodedAdditionalFields) && $encodedAdditionalFields !== '') {
                        $additionalFieldsJson = $app['input']->text($encodedAdditionalFields, 20000);
                    }
                }
            }

            try {
                $signupsRepository->create([
                    'form_slug' => $slug,
                    'form_target' => $slug,
                    'email' => (string) $email,
                    'display_name' => $displayName,
                    'country' => $country,
                    'additional_fields_json' => $additionalFieldsJson,
                    'source_url' => $sourceUrl,
                    'ip_address' => $ipAddress !== '' ? $ipAddress : null,
                    'hostname' => $hostname !== '' ? $hostname : null,
                    'user_agent' => $userAgent !== '' ? $userAgent : null,
                    'created_at' => $createdAt,
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
        $app
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $app['input']->slug((string) ($_POST['slug'] ?? ''));
        $signupId = $app['input']->int($_POST['signup_id'] ?? null, 1);
        $searchQuery = $app['input']->text((string) ($_POST['return_q'] ?? ''), 160);
        $page = $app['input']->int($_POST['return_page'] ?? 1, 1, 100000) ?? 1;

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
        $app
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $app['input']->slug((string) ($_POST['slug'] ?? ''));
        $searchQuery = $app['input']->text((string) ($_POST['return_q'] ?? ''), 160);
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
        $app,
        $loadForms,
        $saveForms,
        $signupsRepository,
        $flash,
        $indexPath,
        $editBasePath
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $name = $app['input']->text((string) ($_POST['name'] ?? ''), 160);
        $slug = $app['input']->slug((string) ($_POST['slug'] ?? ''));
        $originalSlug = $app['input']->slug((string) ($_POST['original_slug'] ?? ''));
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $redirectPath = $originalSlug !== null ? ($editBasePath . '/' . rawurlencode($originalSlug)) : $editBasePath;

        /** @var mixed $rawAdditionalFields */
        $rawAdditionalFields = $_POST['additional_fields'] ?? [];
        $additionalFields = [];
        $seenAdditionalFieldNames = [];
        if (is_array($rawAdditionalFields)) {
            foreach ($rawAdditionalFields as $rawField) {
                if (!is_array($rawField)) {
                    continue;
                }

                $fieldLabel = $app['input']->text((string) ($rawField['label'] ?? ''), 120);
                $fieldNameInput = strtolower($app['input']->text((string) ($rawField['name'] ?? ''), 120));
                $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldNameInput) ?? '';
                $fieldName = trim($fieldName, '_');
                if ($fieldName === '' && $fieldLabel !== '') {
                    $fieldName = strtolower($fieldLabel);
                    $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldName) ?? '';
                    $fieldName = trim($fieldName, '_');
                }

                $fieldType = strtolower($app['input']->text((string) ($rawField['type'] ?? 'text'), 20));
                if (!in_array($fieldType, ['text', 'email', 'textarea'], true)) {
                    $fieldType = 'text';
                }

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

                $seenAdditionalFieldNames[$fieldName] = true;
                $additionalFields[] = [
                    'label' => $fieldLabel,
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'required' => $required,
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
        $app,
        $loadForms,
        $saveForms,
        $signupsRepository,
        $flash,
        $indexPath
    ): void {
        $requirePanelLogin();

        if (!isset($app['input'])) {
            http_response_code(500);
            echo 'Input sanitizer is unavailable.';
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
        }

        $slug = $app['input']->slug((string) ($_POST['slug'] ?? ''));
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
