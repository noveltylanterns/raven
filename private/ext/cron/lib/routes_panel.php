<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/lib/routes_panel.php
 * Scheduled Tasks extension panel route registration.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Ext\Cron\CronTaskService;
use Raven\Core\Routing\Router;
use Raven\Lib\Scheduler\Registry as SchedulerRegistry;

use function Raven\Lib\Support\redirect;

/**
 * Registers Scheduled Tasks routes into the panel router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   extensionServices?: callable(?string=): array<string, mixed>
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
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'light';

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

    if (!isset($rvn['root'], $rvn['view'], $rvn['config'], $rvn['csrf'], $rvn['input'])) {
        return;
    }

    /** @var callable(?string=): array<string, mixed> $extensionServices */
    $extensionServices = is_callable($context['extensionServices'] ?? null)
        ? $context['extensionServices']
        : static function (?string $extensionDirectory = null) use ($rvn): array {
            $directory = is_string($extensionDirectory) && trim($extensionDirectory) !== '' ? trim($extensionDirectory) : 'cron';
            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            /** @var mixed $rawServices */
            $rawServices = is_array($rawExtensionServices) ? ($rawExtensionServices[$directory] ?? []) : [];
            return is_array($rawServices) ? $rawServices : [];
        };

    /**
     * Resolves Scheduled Tasks services only when one cron route is actually used.
     */
    $requireCronService = static function () use ($extensionServices): CronTaskService {
        $services = $extensionServices('cron');
        $service = $services['service'] ?? null;
        if (!$service instanceof CronTaskService) {
            http_response_code(404);
            echo 'Not Found';
            exit;
        }

        return $service;
    };

    $service = new class($requireCronService) {
        /** @var \Closure(): CronTaskService */
        private \Closure $resolver;

        /**
         * @param callable(): CronTaskService $resolver
         */
        public function __construct(callable $resolver)
        {
            $this->resolver = \Closure::fromCallable($resolver);
        }

        /**
         * Proxies cron-service calls through the lazy extension resolver.
         *
         * @param string $name Repository method name.
         * @param array<int, mixed> $arguments Repository call arguments.
         * @return mixed
         */
        public function __call(string $name, array $arguments): mixed
        {
            $resolvedService = ($this->resolver)();
            return $resolvedService->$name(...$arguments);
        }
    };

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/cron';
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $viewFile = $extensionRoot . '/tpl/panel_index.php';
    $indexPath = $panelUrl('/cron');
    $savePath = $panelUrl('/cron/save');
    $configurationPath = $panelUrl('/configuration');
    $schedulerMode = strtolower(trim((string) $rvn['config']->get('site.scheduler', 'always')));
    if (!in_array($schedulerMode, ['off', 'panel', 'always'], true)) {
        $schedulerMode = 'always';
    }
    $section = 'cron';

    $extensionMeta = [
        'directory' => 'cron',
        'name' => 'Scheduled Tasks',
        'type' => 'system',
        'panel_path' => 'cron',
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
     * Stores one flash message scoped to Scheduled Tasks pages.
     *
     * @param string $type    Message bucket.
     * @param string $message Message text.
     */
    $flash = static function (string $type, string $message): void {
        $_SESSION['_raven_cron_flash_' . $type] = $message;
    };

    /**
     * Returns and clears one flash message scoped to Scheduled Tasks pages.
     *
     * @param string $type Message bucket.
     * @return string|null One flashed message when present.
     */
    $pullFlash = static function (string $type): ?string {
        $key = '_raven_cron_flash_' . $type;
        if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            return null;
        }

        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    };

    /**
     * Stores sanitized rows after a validation failure so the form can repopulate on redirect.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    $storeOldRows = static function (array $rows): void {
        $_SESSION['_raven_cron_old_rows'] = $rows;
    };

    /**
     * Returns and clears the last failed form submission rows.
     *
     * @return array<int, array<string, mixed>>|null
     */
    $pullOldRows = static function (): ?array {
        $key = '_raven_cron_old_rows';
        $rows = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_array($rows) ? $rows : null;
    };

    /**
     * Renders the Scheduled Tasks page inside the shared panel layout.
     *
     * @param array<int, array<string, mixed>> $tasks
     * @param string|null $flashSuccess Success message.
     * @param string|null $flashError   Error message.
     */
    $renderView = static function (array $tasks, ?string $flashSuccess, ?string $flashError) use (
        $rvn,
        $viewFile,
        $currentUserTheme,
        $section,
        $extensionMeta,
        $panelSiteData,
        $savePath,
        $service,
        $configurationPath,
        $schedulerMode
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Extension view template is missing.';
            return;
        }

        $site = $panelSiteData();
        $csrfField = $rvn['csrf']->field();
        $schedulerAvailable = ($rvn['scheduler'] ?? null) instanceof SchedulerRegistry;
        $storagePath = $service->storagePath();

        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $site,
            'csrfField' => $rvn['csrf']->field(),
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', '/cron', static function () use (
        $requirePanelLogin,
        $pullOldRows,
        $pullFlash,
        $service,
        $rvn,
        $renderView
    ): void {
        $requirePanelLogin();

        $tasks = $pullOldRows();
        if ($tasks === null) {
            /** @var mixed $schedulerRaw */
            $schedulerRaw = $rvn['scheduler'] ?? null;
            $scheduler = $schedulerRaw instanceof SchedulerRegistry ? $schedulerRaw : null;
            $tasks = $service->tasksForPanel($scheduler);
        }

        $renderView(
            $tasks,
            $pullFlash('success'),
            $pullFlash('error')
        );
    });

    $router->add('POST', '/cron/save', static function () use (
        $requirePanelLogin,
        $rvn,
        $service,
        $flash,
        $storeOldRows,
        $indexPath
    ): void {
        $requirePanelLogin();

        if (!$rvn['csrf']->validate((string) ($_POST['_csrf'] ?? ''))) {
            $flash('error', 'The scheduled tasks form token was invalid.');
            redirect($indexPath, 303);
            return;
        }

        $rawRows = is_array($_POST['tasks'] ?? null) ? $_POST['tasks'] : [];
        $validation = $service->validateSubmittedTasks(
            $rawRows,
            static fn (string $value, int $maxLength): string => $rvn['input']->text($value, $maxLength),
            static fn (string $value): string => $rvn['input']->slug($value),
            static fn (mixed $value, int $min, int $max): ?int => $rvn['input']->int($value, $min, $max)
        );

        if ($validation['errors'] !== []) {
            $storeOldRows($validation['rows']);
            $flash('error', $validation['errors'][0]);
            redirect($indexPath, 303);
            return;
        }

        try {
            $service->saveTasks($validation['tasks']);
            $count = count($validation['tasks']);
            $flash(
                'success',
                $count === 0
                    ? 'Scheduled tasks were cleared.'
                    : 'Scheduled tasks were saved.'
            );
        } catch (\Throwable $exception) {
            $storeOldRows($validation['rows']);
            $flash('error', $exception->getMessage());
        }

        redirect($indexPath, 303);
    });
};
