<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/panel_routes.php
 * Contact Forms extension panel route and CRUD registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Auth\PanelAccess;
use Raven\Core\Routing\Router;
use Raven\Repository\ContactFormRepository;
use Raven\Repository\ContactSubmissionRepository;

use function Raven\Core\Support\redirect;

/**
 * Registers Contact Forms extension routes into the panel router.
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

    if (!isset($app['root'], $app['view'], $app['config'], $app['csrf'], $app['contact_forms'], $app['contact_submissions'])) {
        return;
    }

    if (!$app['contact_forms'] instanceof ContactFormRepository || !$app['contact_submissions'] instanceof ContactSubmissionRepository) {
        return;
    }
    $contactFormsRepository = $app['contact_forms'];
    $contactSubmissionsRepository = $app['contact_submissions'];

    $extensionRoot = rtrim((string) $app['root'], '/') . '/private/ext/contact';
    $extensionManifestFile = $extensionRoot . '/extension.json';
    $listViewFile = $extensionRoot . '/views/panel_index.php';
    $editViewFile = $extensionRoot . '/views/panel_edit.php';
    $submissionsViewFile = $extensionRoot . '/views/panel_submissions.php';

    $indexPath = $panelUrl('/contact');
    $editBasePath = $panelUrl('/contact/edit');
    $submissionsBasePath = $panelUrl('/contact/submissions');
    $submissionsDeletePath = $panelUrl('/contact/submissions/delete');
    $submissionsClearPath = $panelUrl('/contact/submissions/clear');
    $savePath = $panelUrl('/contact/save');
    $deletePath = $panelUrl('/contact/delete');
    $setExtensionPermissionPath = trim((string) ($context['setExtensionPermissionPath'] ?? $panelUrl('/extensions/permission')));
    if ($setExtensionPermissionPath === '') {
        $setExtensionPermissionPath = $panelUrl('/extensions/permission');
    }
    $extensionDirectory = trim((string) ($context['extensionDirectory'] ?? 'contact'));
    if ($extensionDirectory === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $extensionDirectory) !== 1) {
        $extensionDirectory = 'contact';
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
        'name' => 'Contact Forms',
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
     * Reads configured forms from DB-backed extension storage.
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
     *     required: bool
     *   }>
     * }>
     */
    $loadForms = static function () use ($contactFormsRepository): array {
        return $contactFormsRepository->listAll();
    };

    /**
     * Persists contact forms into DB-backed extension storage.
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
     *     required: bool
     *   }>
     * }> $forms
     */
    $saveForms = static function (array $forms) use ($contactFormsRepository): void {
        $contactFormsRepository->replaceAll($forms);
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
     *   additional_fields: array<int, array{label: string, name: string, type: string, required: bool}>
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
    $parseEmailList = static function (string $rawValue) use ($app): array {
        if (!isset($app['input'])) {
            return [
                'emails' => [],
                'invalid' => [],
            ];
        }

        $normalized = $app['input']->text($rawValue, 2000);
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

            $email = $app['input']->email($candidate);
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
    $renderView = static function (array $viewData) use ($app, $currentUserTheme): void {
        $viewFile = (string) ($viewData['_view'] ?? '');
        unset($viewData['_view']);
        if ($viewFile === '' || !is_file($viewFile)) {
            http_response_code(500);
            echo 'Contact Forms view template is missing.';
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
            'contactSubmissionsBasePath' => $submissionsBasePath,
            'savePath' => $savePath,
            'deletePath' => $deletePath,
            'extensionMeta' => $extensionMeta,
            'extensionDirectory' => $extensionDirectory,
            'extensionPermissionOptions' => $permissionOptionList,
            'extensionRequiredPermissionBit' => $extensionRequiredPermissionBit,
            'extensionPermissionAction' => $setExtensionPermissionPath,
            'extensionPermissionRedirect' => '/contact',
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
            $totalSubmissions = $contactSubmissionsRepository->countByFormSlug($slug, $searchQuery);
            $totalPages = max(1, (int) ceil($totalSubmissions / $perPage));
            if ($totalSubmissions > 0 && $page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $submissions = $contactSubmissionsRepository->listByFormSlug($slug, $perPage, $offset, $searchQuery);
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

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="contact-' . $safeFileSlug . '-submissions.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['ID', 'Sender Name', 'Sender Email', 'Message', 'Additional Fields JSON', 'Source URL', 'IP Address', 'Hostname', 'User Agent', 'Created At']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['id'] ?? ''),
                (string) ($row['sender_name'] ?? ''),
                (string) ($row['sender_email'] ?? ''),
                (string) ($row['message_text'] ?? ''),
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

    $router->add('POST', '/contact/submissions/delete', static function () use (
        $requirePanelLogin,
        $findFormBySlug,
        $contactSubmissionsRepository,
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
        $submissionId = $app['input']->int($_POST['submission_id'] ?? null, 1);
        $searchQuery = $app['input']->text((string) ($_POST['return_q'] ?? ''), 160);
        $page = $app['input']->int($_POST['return_page'] ?? 1, 1, 100000) ?? 1;

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
        $app,
        $loadForms,
        $saveForms,
        $contactSubmissionsRepository,
        $parseEmailList,
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
        $destinationRaw = $app['input']->text((string) ($_POST['destination'] ?? ''), 2000);
        $ccRaw = $app['input']->text((string) ($_POST['cc'] ?? ''), 2000);
        $bccRaw = $app['input']->text((string) ($_POST['bcc'] ?? ''), 2000);
        $parsedDestination = $parseEmailList($destinationRaw);
        $parsedCc = $parseEmailList($ccRaw);
        $parsedBcc = $parseEmailList($bccRaw);
        $destination = implode(', ', $parsedDestination['emails']);
        $cc = implode(', ', $parsedCc['emails']);
        $bcc = implode(', ', $parsedBcc['emails']);
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $saveMailLocally = isset($_POST['save_mail_locally']) && (string) $_POST['save_mail_locally'] === '1';
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
        $app,
        $loadForms,
        $saveForms,
        $contactSubmissionsRepository,
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
            $contactSubmissionsRepository->deleteAllByFormSlug($slug);
        } catch (RuntimeException $exception) {
            $flash('error', $exception->getMessage());
            redirect($indexPath);
        }

        $flash('success', 'Contact form deleted.');
        redirect($indexPath);
    });
};
