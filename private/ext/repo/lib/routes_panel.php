<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/routes_panel.php
 * Repositories extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Lib\Routing\Router;
use Raven\Ext\Repo\RepoService;

use function Raven\Support\redirect;

/**
 * Registers Repo extension panel routes.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   renderPublicNotFound?: callable(): void,
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

    /** @var callable(): void $renderNotFound */
    $renderNotFound = $context['renderPublicNotFound'] ?? static function (): void {
        http_response_code(404);
        echo 'Not Found';
    };

    if (!isset($rvn['root'], $rvn['view'], $rvn['config'], $rvn['csrf'], $rvn['input'])) {
        return;
    }

    $input = $rvn['input'];
    /** @var callable(?string=): array<string, mixed> $extensionServices */
    $extensionServices = is_callable($context['extensionServices'] ?? null)
        ? $context['extensionServices']
        : static function (?string $extensionDirectory = null) use ($rvn): array {
            $directory = is_string($extensionDirectory) && trim($extensionDirectory) !== '' ? trim($extensionDirectory) : 'repo';
            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            /** @var mixed $rawServices */
            $rawServices = is_array($rawExtensionServices) ? ($rawExtensionServices[$directory] ?? []) : [];
            return is_array($rawServices) ? $rawServices : [];
        };

    /**
     * Resolves Repo extension services only when one repo route is actually used.
     */
    $requireRepoService = static function () use ($extensionServices, $renderNotFound): RepoService {
        $services = $extensionServices('repo');
        $repoService = $services['service'] ?? null;
        if (!$repoService instanceof RepoService) {
            $renderNotFound();
            exit;
        }

        return $repoService;
    };

    $svc = new class($requireRepoService) {
        /** @var \Closure(): RepoService */
        private \Closure $resolver;

        /**
         * @param callable(): RepoService $resolver
         */
        public function __construct(callable $resolver)
        {
            $this->resolver = \Closure::fromCallable($resolver);
        }

        /**
         * Proxies repo-service calls through the lazy extension resolver.
         *
         * @param string $name Repository method name.
         * @param array<int, mixed> $arguments Repository call arguments.
         * @return mixed
         */
        public function __call(string $name, array $arguments): mixed
        {
            $service = ($this->resolver)();
            return $service->$name(...$arguments);
        }
    };

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/repo';
    $indexViewFile = $extensionRoot . '/tpl/panel_index.php';
    $settingsViewFile = $extensionRoot . '/tpl/panel_settings.php';
    $editViewFile = $extensionRoot . '/tpl/panel_edit.php';
    $logsViewFile = $extensionRoot . '/tpl/panel_logs.php';
    $manifestPath = $extensionRoot . '/ext.json';

    $indexPath = $panelUrl('/repo');
    $settingsPath = $panelUrl('/repo/settings');
    $savePath = $panelUrl('/repo/save');
    $logsPath = $panelUrl('/repo/logs');
    $editBasePath = $panelUrl('/repo/edit');
    $syncBasePath = $panelUrl('/repo/sync');
    $deleteBasePath = $panelUrl('/repo/delete');
    $configurationPath = $panelUrl('/configuration');
    $schedulerMode = static function () use ($svc): string {
        return $svc->schedulerMode();
    };

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

    $extensionMeta = [
        'name' => 'Repositories',
        'version' => '',
        'author' => '',
        'description' => '',
        'docs' => 'https://raven.lanterns.io',
    ];
    if (is_file($manifestPath)) {
        $manifestRaw = file_get_contents($manifestPath);
        if ($manifestRaw !== false && trim($manifestRaw) !== '') {
            /** @var mixed $manifestDecoded */
            $manifestDecoded = json_decode($manifestRaw, true);
            if (is_array($manifestDecoded)) {
                $extensionMeta['name'] = trim((string) ($manifestDecoded['name'] ?? $extensionMeta['name']));
                $extensionMeta['version'] = trim((string) ($manifestDecoded['version'] ?? ''));
                $extensionMeta['author'] = trim((string) ($manifestDecoded['author'] ?? ''));
                $extensionMeta['description'] = trim((string) ($manifestDecoded['description'] ?? ''));
                // "docs" is the canonical ext.json key; fall back to legacy "docs_url" for old manifests.
                $docsUrl = trim((string) ($manifestDecoded['docs'] ?? ($manifestDecoded['docs_url'] ?? '')));
                if ($docsUrl !== '') {
                    $extensionMeta['docs'] = $docsUrl;
                }
            }
        }
    }

    $flash = static function (string $type, string $message): void {
        $_SESSION['_raven_repo_flash_' . $type] = $message;
    };

    $pullFlash = static function (string $type): ?string {
        $key = '_raven_repo_flash_' . $type;
        if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            return null;
        }

        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    };

    $normalizeRepoSlug = static function (mixed $value) use ($input): string {
        $candidate = strtolower($input->text(is_scalar($value) ? (string) $value : '', 120));
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $candidate) === 1 ? $candidate : '';
    };

    $normalizeSourceRows = static function (mixed $value) use ($input): array {
        if (!is_array($value)) {
            return [];
        }

        $sources = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $sources[] = [
                'label' => $input->text((string) ($row['label'] ?? ''), 120),
                'url' => $input->text($url, 2048),
                'branch' => $input->text((string) ($row['branch'] ?? ''), 255),
            ];
        }

        return $sources;
    };

    $sourceRowsForForm = static function (array $repo) use ($svc): array {
        $rows = is_array($repo['sources'] ?? null) ? $repo['sources'] : [];
        // Keep one editable row available by default, but stop padding the form with multiple blanks.
        if ($rows === []) {
            $rows[] = [
                'label' => 'Origin',
                'url' => '',
                'branch' => '',
            ];
        }

        return array_slice($rows, 0, 8);
    };

    // Import URLs are stable enough to suggest a repo label/slug when the operator leaves those fields blank.
    $deriveRepoIdentityFromUrl = static function (string $url) use ($input, $normalizeRepoSlug): array {
        $candidate = trim($url);
        if ($candidate === '') {
            return ['slug' => '', 'label' => ''];
        }

        $candidate = preg_replace('/[?#].*$/', '', $candidate) ?? $candidate;
        $candidate = str_replace('\\', '/', $candidate);
        $candidate = rtrim($candidate, '/');
        $tail = basename($candidate);
        $tail = preg_replace('/\.git$/i', '', $tail) ?? $tail;
        $tail = trim($tail);
        if ($tail === '') {
            return ['slug' => '', 'label' => ''];
        }

        $slugSeed = strtolower($tail);
        $slugSeed = preg_replace('/[^a-z0-9_-]+/', '-', $slugSeed) ?? $slugSeed;
        $slugSeed = trim($slugSeed, '-_');
        $slug = $normalizeRepoSlug($slugSeed);

        $labelSeed = preg_replace('/[_-]+/', ' ', $tail) ?? $tail;
        $labelSeed = trim($labelSeed);
        $label = $input->text($labelSeed !== '' ? ucwords($labelSeed) : '', 160);
        if ($label === '' && $slug !== '') {
            $label = $input->text(ucwords(str_replace(['-', '_'], ' ', $slug)), 160);
        }

        return [
            'slug' => $slug,
            'label' => $label,
        ];
    };

    $renderView = static function (string $viewFile, array $viewData) use ($rvn, $currentUserTheme, $panelSiteData, $svc, $schedulerMode, $configurationPath): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Repo extension view template is missing.';
            return;
        }

        $viewData += [
            'schedulerAvailable' => $svc->schedulerAvailable(),
            'schedulerMode' => $schedulerMode(),
            'configurationPath' => $configurationPath,
        ];
        $csrfField = $rvn['csrf']->field();
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $panelSiteData(),
            'csrfField' => $rvn['csrf']->field(),
            'section' => 'repo',
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', '/repo', static function () use (
        $requirePanelLogin,
        $renderView,
        $indexViewFile,
        $svc,
        $pullFlash,
        $normalizeRepoSlug,
        $extensionMeta,
        $indexPath,
        $settingsPath,
        $savePath,
        $logsPath,
        $editBasePath,
        $syncBasePath,
        $deleteBasePath,
        $sourceRowsForForm
    ): void {
        $requirePanelLogin();

        $settings = $svc->settings();
        $renderView($indexViewFile, [
            'extensionMeta' => $extensionMeta,
            'repos' => $svc->repoList(),
            'settings' => $settings,
            'recentErrors' => $svc->recentErrors(null, 5),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'focusSlug' => $normalizeRepoSlug($pullFlash('focus') ?? ''),
            'indexPath' => $indexPath,
            'settingsPath' => $settingsPath,
            'savePath' => $savePath,
            'logsPath' => $logsPath,
            'editBasePath' => $editBasePath,
            'syncBasePath' => $syncBasePath,
            'deleteBasePath' => $deleteBasePath,
            'visibilityOptions' => $svc->visibilityOptions(false),
            'importDefaults' => $svc->newRepoDefaults(),
            'importSourceRows' => $sourceRowsForForm($svc->newRepoDefaults()),
            'schedulerAvailable' => $svc->schedulerAvailable(),
        ]);
    });

    $router->add('GET', '/repo/settings', static function () use (
        $requirePanelLogin,
        $renderView,
        $settingsViewFile,
        $svc,
        $pullFlash,
        $extensionMeta,
        $indexPath,
        $settingsPath,
        $logsPath
    ): void {
        $requirePanelLogin();

        $renderView($settingsViewFile, [
            'extensionMeta' => $extensionMeta,
            'settings' => $svc->settings(),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'indexPath' => $indexPath,
            'settingsPath' => $settingsPath,
            'logsPath' => $logsPath,
            'visibilityOptions' => $svc->visibilityOptions(false),
            'frequencyOptions' => $svc->frequencyOptions(false),
            'logEventOptions' => $svc->logEventOptions(),
            'schedulerAvailable' => $svc->schedulerAvailable(),
        ]);
    });

    $router->add('POST', '/repo/settings', static function () use (
        $requirePanelLogin,
        $rvn,
        $svc,
        $flash,
        $settingsPath
    ): void {
        $requirePanelLogin();
        if (!$rvn['csrf']->validate((string) ($_POST['_csrf'] ?? ''))) {
            $flash('error', 'The settings form token was invalid.');
            redirect($settingsPath, 303);
            return;
        }

        $rawLogEvents = is_array($_POST['log_events'] ?? null) ? $_POST['log_events'] : [];
        $logEvents = [];
        foreach (array_keys($svc->logEventOptions()) as $event) {
            $logEvents[$event] = !empty($rawLogEvents[$event]);
        }

        try {
            $svc->saveSettings([
                'auto_update_enabled' => !empty($_POST['auto_update_enabled']),
                'update_frequency' => $rvn['input']->text((string) ($_POST['update_frequency'] ?? ''), 32),
                'default_visibility' => $rvn['input']->text((string) ($_POST['default_visibility'] ?? ''), 64),
                'log_prune_days' => $rvn['input']->int($_POST['log_prune_days'] ?? null, 1, 3650) ?? 30,
                'log_events' => $logEvents,
            ]);
            $flash('success', 'Repo extension settings were saved.');
        } catch (\Throwable $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($settingsPath, 303);
    });

    $router->add('GET', '/repo/edit/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $normalizeRepoSlug,
        $svc,
        $renderNotFound,
        $renderView,
        $editViewFile,
        $pullFlash,
        $extensionMeta,
        $indexPath,
        $savePath,
        $logsPath,
        $syncBasePath,
        $deleteBasePath,
        $sourceRowsForForm
    ): void {
        $requirePanelLogin();
        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        $repo = $slug !== '' ? $svc->getRepo($slug) : null;
        if ($repo === null) {
            $renderNotFound();
            return;
        }

        $renderView($editViewFile, [
            'extensionMeta' => $extensionMeta,
            'repo' => $repo,
            'sourceRows' => $sourceRowsForForm($repo),
            'recentErrors' => $svc->recentErrors($slug, 5),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'indexPath' => $indexPath,
            'savePath' => $savePath,
            'logsPath' => $logsPath,
            'syncPath' => $syncBasePath . '/' . rawurlencode($slug),
            'deletePath' => $deleteBasePath . '/' . rawurlencode($slug),
            'visibilityOptions' => $svc->visibilityOptions(true),
            'frequencyOptions' => $svc->frequencyOptions(true),
            'autoUpdateOptions' => $svc->autoUpdateOptions(true),
            'schedulerAvailable' => $svc->schedulerAvailable(),
        ]);
    });

    $router->add('POST', '/repo/save', static function () use (
        $requirePanelLogin,
        $rvn,
        $svc,
        $flash,
        $indexPath,
        $editBasePath,
        $normalizeRepoSlug,
        $normalizeSourceRows,
        $deriveRepoIdentityFromUrl
    ): void {
        $requirePanelLogin();
        if (!$rvn['csrf']->validate((string) ($_POST['_csrf'] ?? ''))) {
            $flash('error', 'The repository form token was invalid.');
            redirect($indexPath, 303);
            return;
        }

        $sources = $normalizeSourceRows($_POST['sources'] ?? []);
        $primarySourceUrl = is_string($sources[0]['url'] ?? null) ? (string) $sources[0]['url'] : '';
        $derivedIdentity = $deriveRepoIdentityFromUrl($primarySourceUrl);

        $slug = $normalizeRepoSlug($_POST['slug'] ?? null);
        if ($slug === '') {
            $slug = (string) ($derivedIdentity['slug'] ?? '');
        }

        $label = $rvn['input']->text((string) ($_POST['label'] ?? ''), 160);
        if ($label === '') {
            $label = $rvn['input']->text((string) ($derivedIdentity['label'] ?? ''), 160);
        }

        $returnToIndex = $rvn['input']->text((string) ($_POST['return_to'] ?? ''), 32) === 'index';
        $redirectPath = $returnToIndex || $slug === ''
            ? $indexPath
            : ($editBasePath . '/' . rawurlencode($slug));

        try {
            $repo = $svc->saveRepo([
                'slug' => $slug,
                'label' => $label,
                'description' => $rvn['input']->text((string) ($_POST['description'] ?? ''), 500),
                'notes' => $rvn['input']->text((string) ($_POST['notes'] ?? ''), 4000),
                'visibility' => $rvn['input']->text((string) ($_POST['visibility'] ?? ''), 64),
                'storage' => $rvn['input']->text((string) ($_POST['storage'] ?? ''), 32),
                'auto_update' => $rvn['input']->text((string) ($_POST['auto_update'] ?? ''), 32),
                'update_frequency' => $rvn['input']->text((string) ($_POST['update_frequency'] ?? ''), 32),
                'public_branch' => $rvn['input']->text((string) ($_POST['public_branch'] ?? ''), 255),
                'sources' => $sources,
            ]);

            $savedSlug = (string) ($repo['slug'] ?? $slug);
            $savedLabel = trim((string) ($repo['label'] ?? $label));
            if ($savedLabel === '') {
                $savedLabel = $savedSlug;
            }

            if ($returnToIndex) {
                $redirectPath = $indexPath;
                if ($savedSlug !== '') {
                    $flash('focus', $savedSlug);
                }
            } else {
                $redirectPath = $editBasePath . '/' . rawurlencode($savedSlug);
            }

            if (!empty($_POST['sync_now'])) {
                try {
                    $svc->syncRepo($savedSlug);
                    if ($returnToIndex) {
                        $flash('success', 'Repo ' . $savedLabel . ' has been successfully imported and synced.');
                    } else {
                        $flash('success', 'Repository settings were saved and the mirror synced successfully.');
                    }
                } catch (\Throwable $exception) {
                    if ($returnToIndex) {
                        $flash('error', 'Repo ' . $savedLabel . ' was imported, but sync failed: ' . $exception->getMessage());
                    } else {
                        $flash('error', 'Repository settings were saved, but sync failed: ' . $exception->getMessage());
                    }
                }
            } else {
                if ($returnToIndex) {
                    $flash('success', 'Repo ' . $savedLabel . ' has been successfully imported.');
                } else {
                    $flash('success', 'Repository settings were saved.');
                }
            }
        } catch (\Throwable $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($redirectPath, 303);
    });

    $router->add('POST', '/repo/sync/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $rvn,
        $svc,
        $flash,
        $indexPath,
        $editBasePath,
        $normalizeRepoSlug
    ): void {
        $requirePanelLogin();
        if (!$rvn['csrf']->validate((string) ($_POST['_csrf'] ?? ''))) {
            $flash('error', 'The sync request token was invalid.');
            redirect($indexPath, 303);
            return;
        }

        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        if ($slug === '') {
            $flash('error', 'Repository slug is invalid.');
            redirect($indexPath, 303);
            return;
        }

        try {
            $svc->syncRepo($slug);
            $flash('success', 'Repository sync completed successfully.');
        } catch (\Throwable $exception) {
            $flash('error', $exception->getMessage());
        }

        $returnTo = !empty($_POST['return_to']) && (string) $_POST['return_to'] === 'index'
            ? $indexPath
            : ($editBasePath . '/' . rawurlencode($slug));
        redirect($returnTo, 303);
    });

    $router->add('POST', '/repo/delete/{slug}', static function (array $params) use (
        $requirePanelLogin,
        $rvn,
        $svc,
        $flash,
        $indexPath,
        $normalizeRepoSlug
    ): void {
        $requirePanelLogin();
        if (!$rvn['csrf']->validate((string) ($_POST['_csrf'] ?? ''))) {
            $flash('error', 'The delete request token was invalid.');
            redirect($indexPath, 303);
            return;
        }

        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        if ($slug === '') {
            $flash('error', 'Repository slug is invalid.');
            redirect($indexPath, 303);
            return;
        }

        try {
            $removed = $svc->deleteRepo($slug);
            if ($removed === null) {
                $flash('error', 'Repository was not found.');
            } else {
                $flash('success', 'Repository was deleted.');
            }
        } catch (\Throwable $exception) {
            $flash('error', $exception->getMessage());
        }

        redirect($indexPath, 303);
    });
    $router->add('GET', '/repo/logs', static function () use (
        $requirePanelLogin,
        $normalizeRepoSlug,
        $renderView,
        $logsViewFile,
        $svc,
        $pullFlash,
        $extensionMeta,
        $indexPath,
        $settingsPath,
        $logsPath
    ): void {
        $requirePanelLogin();

        $initialRepoFilter = $normalizeRepoSlug($_GET['repo'] ?? null);
        $repo = $initialRepoFilter !== '' ? $svc->getRepo($initialRepoFilter) : null;
        if ($repo === null) {
            $initialRepoFilter = '';
        }

        $renderView($logsViewFile, [
            'extensionMeta' => $extensionMeta,
            'repo' => $repo,
            'initialRepoFilter' => $initialRepoFilter,
            'logs' => $svc->logs(null, 250),
            'flashSuccess' => $pullFlash('success'),
            'flashError' => $pullFlash('error'),
            'indexPath' => $indexPath,
            'settingsPath' => $settingsPath,
            'logsPath' => $logsPath,
        ]);
    });
};
