<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/lib/routes_panel.php
 * Smallweb extension panel route registrar.
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Lib\Routing\Router;
use Raven\Smallweb\SmallwebService;

use function Raven\Core\Support\redirect;

/**
 * Registers Smallweb extension routes into the panel router.
 *
 * @param array{
 *   app: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   extensionDirectory?: string
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

    /** @var callable(bool=): array<string, mixed> $panelSiteData */
    $panelSiteData = is_callable($app['panel_site_data'] ?? null)
        ? $app['panel_site_data']
        : static function (bool $includeDomain = true) use ($app): array {
            $site = [
                'name' => (string) $app['config']->get('site.name', 'Raven CMS'),
                'panel_path' => (string) $app['config']->get('panel.path', 'panel'),
                'panel_brand_name' => (string) $app['config']->get('panel.brand_name', ''),
                'panel_brand_logo' => (string) $app['config']->get('panel.brand_logo', ''),
            ];
            if ($includeDomain) {
                $site['domain'] = (string) $app['config']->get('site.domain', 'localhost');
            }
            return $site;
        };

    // ── Resolve extension services ──

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $app['extension_services'] ?? [];
    /** @var mixed $rawSmallwebServices */
    $rawSmallwebServices = is_array($rawExtensionServices) ? ($rawExtensionServices['smallweb'] ?? []) : [];
    /** @var mixed $smallwebServiceRaw */
    $smallwebServiceRaw = is_array($rawSmallwebServices) ? ($rawSmallwebServices['service'] ?? null) : null;

    if (!isset($app['root'], $app['view'], $app['config'], $app['csrf'], $app['input'])) {
        return;
    }

    if (!$smallwebServiceRaw instanceof SmallwebService) {
        return;
    }

    $svc = $smallwebServiceRaw;
    $input = $app['input'];

    // ── Paths ──

    $extensionRoot = rtrim((string) $app['root'], '/') . '/private/ext/smallweb';
    $extensionManifestFile = $extensionRoot . '/ext.json';

    $indexPath        = $panelUrl('/smallweb');
    $settingsSavePath = $panelUrl('/smallweb/settings');

    $settingsViewFile  = $extensionRoot . '/tpl/panel_index.php';
    $fileEditViewFile  = $extensionRoot . '/tpl/panel_file_edit.php';

    // ── Extension metadata ──

    $extensionMeta = [
        'name' => 'Smallweb',
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

    // ── Flash helpers ──

    $flash = static function (string $type, string $message): void {
        $_SESSION['_raven_smallweb_flash_' . $type] = $message;
    };

    $pullFlash = static function (string $type): ?string {
        $key = '_raven_smallweb_flash_' . $type;
        if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            return null;
        }
        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    };

    // ── Render helper ──

    $renderView = static function (array $viewData) use ($app, $currentUserTheme, $panelSiteData): void {
        $viewFile = (string) ($viewData['_view'] ?? '');
        unset($viewData['_view']);
        if ($viewFile === '' || !is_file($viewFile)) {
            http_response_code(500);
            echo 'Smallweb view template is missing.';
            return;
        }

        $csrfField = $app['csrf']->field();
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $app['view']->render('panel/wrapper', [
            'site' => $panelSiteData(),
            'csrfField'   => $app['csrf']->field(),
            'section'     => 'smallweb',
            'showSidebar' => true,
            'userTheme'   => $currentUserTheme(),
            'content'     => $body,
        ]);
    };

    // ── Protocol validation helper ──

    $requireEnabledProtocol = static function (string $protocol) use ($svc, $indexPath): bool {
        if (!$svc->isValidProtocol($protocol)) {
            redirect($indexPath);
            return false;
        }
        $settings = $svc->getProtocolSettings($protocol);
        if (!($settings['enabled'] ?? false)) {
            redirect($indexPath);
            return false;
        }
        return true;
    };

    // ══════════════════════════════════════════════════════════════
    //  GET /smallweb — Settings page
    // ══════════════════════════════════════════════════════════════

    $router->add('GET', '/smallweb', static function () use (
        $requirePanelLogin,
        $renderView,
        $pullFlash,
        $svc,
        $indexPath,
        $settingsSavePath,
        $panelUrl,
        $settingsViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();
        $settings = $svc->loadSettings();

        $renderView([
            '_view'            => $settingsViewFile,
            'settings'         => $settings,
            'currentTab'       => 'settings',
            'protocolFiles'    => [],
            'domain'           => '',
            'settingsSavePath' => $settingsSavePath,
            'indexPath'        => $indexPath,
            'panelUrl'         => $panelUrl,
            'flashSuccess'     => $pullFlash('success'),
            'flashError'       => $pullFlash('error'),
            'extensionMeta'    => $extensionMeta,
            'svc'              => $svc,
        ]);
    });

    // ══════════════════════════════════════════════════════════════
    //  GET /smallweb/{protocol} — Protocol file list
    // ══════════════════════════════════════════════════════════════

    $router->add('GET', '/smallweb/{protocol}', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $renderView,
        $pullFlash,
        $svc,
        $indexPath,
        $settingsSavePath,
        $panelUrl,
        $settingsViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $settings = $svc->loadSettings();
        $domain = $svc->getSiteDomain();
        $svc->ensureProtocolDirectory($protocol);

        $viewData = [
            '_view'            => $settingsViewFile,
            'settings'         => $settings,
            'currentTab'       => $protocol,
            'protocolFiles'    => [$protocol => $svc->listProtocolFiles($protocol)],
            'domain'           => $domain,
            'settingsSavePath' => $settingsSavePath,
            'indexPath'        => $indexPath,
            'panelUrl'         => $panelUrl,
            'flashSuccess'     => $pullFlash('success'),
            'flashError'       => $pullFlash('error'),
            'extensionMeta'    => $extensionMeta,
            'svc'              => $svc,
        ];

        if ($svc->protocolSupportsDirectories($protocol)) {
            $viewData['protocolTree'] = $svc->getProtocolTree($protocol);
            $viewData['parentDirs'] = $svc->getAvailableParentDirs($protocol);
        }

        $renderView($viewData);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/settings — Save protocol settings
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/settings', static function () use (
        $requirePanelLogin,
        $app,
        $svc,
        $flash,
        $indexPath,
        $input
    ): void {
        $requirePanelLogin();

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($indexPath);
            return;
        }

        $settings = $svc->loadSettings();

        foreach (SmallwebService::SUPPORTED_PROTOCOLS as $proto) {
            $settings['protocols'][$proto]['enabled'] = isset($_POST['protocol_' . $proto . '_enabled']);

            foreach (['chmod_dir', 'chmod_txt', 'chmod_cgi'] as $chmodKey) {
                $postKey = 'protocol_' . $proto . '_' . $chmodKey;
                $val = trim((string) $input->text((string) ($_POST[$postKey] ?? ''), 4));
                if (preg_match('/^0[0-7]{3}$/', $val) === 1) {
                    $settings['protocols'][$proto][$chmodKey] = $val;
                }
            }
        }

        $uploadExts = trim((string) $input->text((string) ($_POST['allowed_upload_extensions'] ?? SmallwebService::DEFAULT_UPLOAD_EXTENSIONS), 500));
        if ($uploadExts !== '') {
            $settings['allowed_upload_extensions'] = $uploadExts;
        }

        if ($svc->saveSettings($settings)) {
            $svc->syncProtocolDirectories();
            $flash('success', 'Settings saved.');
        } else {
            $flash('error', 'Failed to save settings.');
        }

        redirect($indexPath);
    });

    // ══════════════════════════════════════════════════════════════
    //  GET /smallweb/{protocol}/new — New file form
    // ══════════════════════════════════════════════════════════════

    $router->add('GET', '/smallweb/{protocol}/new', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $renderView,
        $pullFlash,
        $svc,
        $indexPath,
        $panelUrl,
        $fileEditViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $subdir = trim((string) ($_GET['dir'] ?? ''));
        if (!$svc->isValidSubdirPath($subdir)) {
            $subdir = '';
        }

        $viewData = [
            '_view'            => $fileEditViewFile,
            'protocol'         => $protocol,
            'fileData'         => null,
            'subdir'           => $subdir,
            'parentDirs'       => null,
            'savePath'         => $panelUrl('/smallweb/' . $protocol . '/save'),
            'deletePath'       => $panelUrl('/smallweb/' . $protocol . '/delete'),
            'indexPath'        => $indexPath,
            'panelUrl'         => $panelUrl,
            'flashSuccess'     => $pullFlash('success'),
            'flashError'       => $pullFlash('error'),
            'extensionMeta'    => $extensionMeta,
            'svc'              => $svc,
        ];

        if ($svc->protocolSupportsDirectories($protocol)) {
            $viewData['parentDirs'] = $svc->getAvailableParentDirs($protocol);
        }

        $renderView($viewData);
    });

    // ══════════════════════════════════════════════════════════════
    //  GET /smallweb/{protocol}/edit/{filename} — Edit file form
    // ══════════════════════════════════════════════════════════════

    $router->add('GET', '/smallweb/{protocol}/edit/{filename}', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $renderView,
        $pullFlash,
        $svc,
        $indexPath,
        $panelUrl,
        $fileEditViewFile,
        $extensionMeta
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $filename = (string) ($params['filename'] ?? '');
        if (!$svc->isValidFilename($filename)) {
            redirect($panelUrl('/smallweb/' . $protocol));
            return;
        }

        $subdir = trim((string) ($_GET['dir'] ?? ''));
        if (!$svc->isValidSubdirPath($subdir)) {
            $subdir = '';
        }

        $content = $svc->readProtocolFile($protocol, $filename, $subdir);
        if ($content === null) {
            redirect($panelUrl('/smallweb/' . $protocol));
            return;
        }

        $slug = $svc->filenameToSlug($filename);
        $type = $svc->normalizeType(
            strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            $protocol
        );
        $hidden = $svc->isHiddenFile($filename);
        $dirPrefix = $subdir !== '' ? $subdir . '/' : '';
        $filePath = $svc->getProtocolDir($protocol) . '/' . $dirPrefix . $filename;
        $executable = is_executable($filePath);

        $viewData = [
            '_view'            => $fileEditViewFile,
            'protocol'         => $protocol,
            'subdir'           => $subdir,
            'parentDirs'       => null,
            'fileData'         => [
                'slug'       => $slug,
                'type'       => $type,
                'hidden'     => $hidden,
                'executable' => $executable,
                'content'    => $content,
                'filename'   => $filename,
            ],
            'savePath'         => $panelUrl('/smallweb/' . $protocol . '/save'),
            'deletePath'       => $panelUrl('/smallweb/' . $protocol . '/delete'),
            'indexPath'        => $indexPath,
            'panelUrl'         => $panelUrl,
            'flashSuccess'     => $pullFlash('success'),
            'flashError'       => $pullFlash('error'),
            'extensionMeta'    => $extensionMeta,
            'svc'              => $svc,
        ];

        if ($svc->protocolSupportsDirectories($protocol)) {
            $viewData['parentDirs'] = $svc->getAvailableParentDirs($protocol);
        }

        $renderView($viewData);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/{protocol}/save — Create or update file
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/{protocol}/save', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $app,
        $svc,
        $input,
        $flash,
        $indexPath,
        $panelUrl
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $tabUrl = $panelUrl('/smallweb/' . $protocol);

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($tabUrl);
            return;
        }

        $slug = strtolower(trim((string) $input->text((string) ($_POST['slug'] ?? ''), 120)));
        $type = $svc->normalizeType((string) ($_POST['type'] ?? 'txt'), $protocol);
        $hidden = $svc->protocolSupportsHidden($protocol)
            && strtolower(trim((string) ($_POST['published'] ?? 'public'))) === 'hidden';
        $executable = $svc->protocolSupportsExecutable($protocol)
            && strtolower(trim((string) ($_POST['executable'] ?? 'no'))) === 'yes';
        $content = (string) ($_POST['content'] ?? '');
        $originalFilename = trim((string) ($_POST['original_filename'] ?? ''));
        $subdir = trim((string) ($_POST['dir'] ?? ''));
        if (!$svc->isValidSubdirPath($subdir)) {
            $subdir = '';
        }

        if (!$svc->isValidSlug($slug)) {
            $flash('error', 'Invalid slug. Use lowercase letters, numbers, hyphens, and underscores. Must start with a letter or number.');
            redirect($tabUrl);
            return;
        }

        $newFilename = $svc->resolveFilename($slug, $type, $hidden);
        $isEdit = $originalFilename !== '' && $svc->isValidFilename($originalFilename);

        // Check for slug conflict with other variants.
        $conflict = $svc->findConflictingFile($protocol, $slug, $type, $hidden, $subdir);
        if ($conflict !== null && $conflict !== $originalFilename) {
            $flash('error', 'A file named "' . $conflict . '" already exists with that slug.');
            redirect($tabUrl);
            return;
        }

        $dirQuery = $subdir !== '' ? '?dir=' . rawurlencode($subdir) : '';
        $editPath = $panelUrl('/smallweb/' . $protocol . '/edit');

        // If renaming, check new filename doesn't collide with a different file.
        if ($isEdit && $newFilename !== $originalFilename && $svc->protocolFileExists($protocol, $newFilename, $subdir)) {
            $flash('error', 'A file named "' . $newFilename . '" already exists.');
            redirect($editPath . '/' . rawurlencode($originalFilename) . $dirQuery);
            return;
        }

        // If not editing, check file doesn't already exist.
        if (!$isEdit && $svc->protocolFileExists($protocol, $newFilename, $subdir)) {
            $flash('error', 'A file named "' . $newFilename . '" already exists.');
            redirect($tabUrl);
            return;
        }

        // Write the new file.
        if (!$svc->writeProtocolFile($protocol, $slug, $type, $content, $hidden, $executable, $subdir)) {
            $flash('error', 'Failed to write file.');
            redirect($tabUrl);
            return;
        }

        // If renaming, delete the old file.
        if ($isEdit && $originalFilename !== $newFilename) {
            $svc->deleteProtocolFile($protocol, $originalFilename, $subdir);
        }

        $flash('success', 'Page saved: ' . $newFilename);
        redirect($editPath . '/' . rawurlencode($newFilename) . $dirQuery);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/{protocol}/delete — Delete file
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/{protocol}/delete', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $app,
        $svc,
        $flash,
        $panelUrl
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $tabUrl = $panelUrl('/smallweb/' . $protocol);

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($tabUrl);
            return;
        }

        $filename = trim((string) ($_POST['filename'] ?? ''));
        if (!$svc->isValidFilename($filename)) {
            $flash('error', 'Invalid filename.');
            redirect($tabUrl);
            return;
        }

        $subdir = trim((string) ($_POST['dir'] ?? ''));
        if (!$svc->isValidSubdirPath($subdir)) {
            $subdir = '';
        }

        if ($svc->deleteProtocolFile($protocol, $filename, $subdir)) {
            $flash('success', 'Deleted: ' . $filename);
        } else {
            $flash('error', 'Failed to delete file.');
        }

        redirect($tabUrl);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/{protocol}/mkdir — Create subdirectory
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/{protocol}/mkdir', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $app,
        $svc,
        $input,
        $flash,
        $panelUrl
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $tabUrl = $panelUrl('/smallweb/' . $protocol);

        if (!$svc->protocolSupportsDirectories($protocol)) {
            $flash('error', 'This protocol does not support directories.');
            redirect($tabUrl);
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($tabUrl);
            return;
        }

        $slug = strtolower(trim((string) $input->text((string) ($_POST['folder_slug'] ?? ''), 120)));
        $parent = trim((string) ($_POST['folder_parent'] ?? ''));

        if (!$svc->isValidSlug($slug)) {
            $flash('error', 'Invalid folder name. Use lowercase letters, numbers, hyphens, and underscores.');
            redirect($tabUrl);
            return;
        }

        if (!$svc->isValidSubdirPath($parent)) {
            $flash('error', 'Invalid parent directory.');
            redirect($tabUrl);
            return;
        }

        if ($svc->createProtocolSubdir($protocol, $slug, $parent)) {
            $displayPath = '/' . ($parent !== '' ? $parent . '/' : '') . $slug;
            $flash('success', 'Created folder: ' . $displayPath);
        } else {
            $flash('error', 'Failed to create folder. It may already exist.');
        }

        redirect($tabUrl);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/{protocol}/rmdir — Delete empty subdirectory
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/{protocol}/rmdir', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $app,
        $svc,
        $flash,
        $panelUrl
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $tabUrl = $panelUrl('/smallweb/' . $protocol);

        if (!$svc->protocolSupportsDirectories($protocol)) {
            $flash('error', 'This protocol does not support directories.');
            redirect($tabUrl);
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($tabUrl);
            return;
        }

        $subdir = trim((string) ($_POST['subdir'] ?? ''));
        if ($subdir === '' || !$svc->isValidSubdirPath($subdir)) {
            $flash('error', 'Invalid directory path.');
            redirect($tabUrl);
            return;
        }

        if ($svc->deleteProtocolSubdir($protocol, $subdir)) {
            $flash('success', 'Deleted folder: /' . $subdir);
        } else {
            $flash('error', 'Failed to delete folder. It must be empty first.');
        }

        redirect($tabUrl);
    });

    // ══════════════════════════════════════════════════════════════
    //  POST /smallweb/{protocol}/upload — Upload file
    // ══════════════════════════════════════════════════════════════

    $router->add('POST', '/smallweb/{protocol}/upload', static function (array $params) use (
        $requirePanelLogin,
        $requireEnabledProtocol,
        $app,
        $svc,
        $flash,
        $panelUrl
    ): void {
        $requirePanelLogin();
        $protocol = strtolower(trim((string) ($params['protocol'] ?? '')));
        if (!$requireEnabledProtocol($protocol)) {
            return;
        }

        $tabUrl = $panelUrl('/smallweb/' . $protocol);

        if (!$svc->protocolSupportsUpload($protocol)) {
            $flash('error', 'This protocol does not support file uploads.');
            redirect($tabUrl);
            return;
        }

        if (!$app['csrf']->validate($_POST['_csrf'] ?? null)) {
            $flash('error', 'Invalid CSRF token.');
            redirect($tabUrl);
            return;
        }

        $subdir = trim((string) ($_POST['upload_parent'] ?? ''));
        if (!$svc->isValidSubdirPath($subdir)) {
            $subdir = '';
        }

        if (!isset($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
            $flash('error', 'No file uploaded or upload failed.');
            redirect($tabUrl);
            return;
        }

        $tmpPath = (string) $_FILES['upload_file']['tmp_name'];
        $originalName = (string) $_FILES['upload_file']['name'];

        $result = $svc->uploadProtocolFile($protocol, $tmpPath, $originalName, $subdir);
        if ($result !== null) {
            $displayDir = $subdir !== '' ? '/' . $subdir . '/' : '/';
            $flash('success', 'Uploaded: ' . $displayDir . $result);
        } else {
            $allowed = implode(', ', $svc->getAllowedUploadExtensions());
            $flash('error', 'Upload failed. File may already exist or extension not allowed. Allowed: ' . $allowed);
        }

        redirect($tabUrl);
    });
};
