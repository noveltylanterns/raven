<?php

/**
 * RAVEN CMS
 * ~/private/ext/backup/routes_panel.php
 * Backup & Restore extension panel routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Core\Router\RouteHandler;
use Raven\Ext\Backup\Archive;

/**
 * Registers Backup & Restore routes into the panel router.
 *
 * @param RouteHandler $router Shared panel router.
 * @param array<string, mixed> $context Extension route context.
 * @return void
 */
return static function (RouteHandler $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);
    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};
    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'light';
    /** @var callable(string): string $panelUrl */
    $panelUrl = $context['panelUrl'] ?? static fn (string $suffix = ''): string => '/' . ltrim($suffix, '/');

    if (
        !isset($rvn['root'], $rvn['view'], $rvn['config'], $rvn['csrf'], $rvn['db'])
        || !$rvn['db'] instanceof PDO
    ) {
        return;
    }

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/backup';
    $viewFile = $extensionRoot . '/tpl/panel_index.php';
    $panelSiteData = is_callable($rvn['panel_site_data'] ?? null)
        ? $rvn['panel_site_data']
        : static fn (): array => [
            'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $rvn['config']->get('panel.path', 'panel'),
            'panel_brand_name' => (string) $rvn['config']->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $rvn['config']->get('panel.brand_logo', ''),
        ];

    $archive = static fn (): Archive => new Archive(
        $rvn['db'],
        (string) ($rvn['driver'] ?? 'sqlite'),
        (string) ($rvn['prefix'] ?? ''),
        (string) $rvn['root']
    );

    /**
     * Renders the extension page inside Raven's shared panel wrapper.
     *
     * @param array<string, mixed> $viewData Extension view variables.
     * @return void
     */
    $render = static function (array $viewData = []) use (
        $rvn,
        $viewFile,
        $panelSiteData,
        $currentUserTheme,
        $panelUrl
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Backup & Restore view template is missing.';
            return;
        }

        $site = $panelSiteData();
        $csrfField = $rvn['csrf']->field();
        $viewData['site'] = $site;
        $viewData['csrfField'] = $csrfField;
        $viewData['backupBasePath'] = $panelUrl('/backup');
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $site,
            'csrfField' => $csrfField,
            'section' => 'backup',
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', '/backup', static function () use ($requirePanelLogin, $render): void {
        $requirePanelLogin();
        $render();
    });

    $router->add('GET', '/backup/export', static function () use ($requirePanelLogin, $archive): void {
        $requirePanelLogin();
        $payload = json_encode(
            $archive()->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="raven-backup-' . gmdate('Y-m-d-His') . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo $payload;
    });

    $router->add('POST', '/backup/import', static function () use (
        $requirePanelLogin,
        $rvn,
        $archive,
        $render
    ): void {
        $requirePanelLogin();
        if (!$rvn['csrf']->validate($_POST['_csrf'] ?? null)) {
            $render(['error' => 'Invalid CSRF token.']);
            return;
        }

        $upload = $_FILES['backup_file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $render(['error' => 'Choose a valid Raven backup JSON file to restore.']);
            return;
        }

        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_file($temporaryPath) || filesize($temporaryPath) > 52428800) {
            $render(['error' => 'The backup file is missing, unreadable, or larger than 50 MB.']);
            return;
        }

        $raw = file_get_contents($temporaryPath);
        if ($raw === false || trim($raw) === '') {
            $render(['error' => 'The backup file is empty or unreadable.']);
            return;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $render(['error' => 'The backup file is not valid JSON.']);
            return;
        }

        try {
            $result = $archive()->restore($decoded);
            $render([
                'success' => sprintf(
                    'Restore complete: %d database rows, %d channels, and %d taxonomy sets restored.',
                    $result['tables'],
                    $result['channels'],
                    $result['sets']
                ),
            ]);
        } catch (\Throwable $exception) {
            $render(['error' => 'Restore failed: ' . $exception->getMessage()]);
        }
    });
};
