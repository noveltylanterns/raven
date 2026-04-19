<?php

/**
 * RAVEN CMS
 * ~/private/ext/phpinfo/routes_panel.php
 * PHP Info extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold route registrar.

declare(strict_types=1);

use Raven\Core\Routing\Router;

/**
 * Registers PHP Info routes into the panel router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);

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

    if (!isset($rvn['view'], $rvn['config'], $rvn['csrf'])) {
        return;
    }

    $extensionRoot = dirname(__DIR__);
    $viewFile = $extensionRoot . '/tpl/panel_index.php';
    $routePath = '/phpinfo';
    $section = 'phpinfo';
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $extensionMeta = [
        'directory' => 'phpinfo',
        'name' => 'PHP Info',
        'type' => 'system',
        'panel_path' => 'phpinfo',
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
     * Renders extension body inside the shared panel layout.
     */
    $renderExtensionView = static function () use (
        $rvn,
        $viewFile,
        $currentUserTheme,
        $section,
        $extensionMeta,
        $panelSiteData
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Extension view template is missing.';
            return;
        }

        $site = $panelSiteData();
        $csrfField = $rvn['csrf']->field();
        $phpInfoHtml = '';
        $phpInfoCss = '';

        // Capture phpinfo output so it can render inside the shared panel layout body.
        ob_start();
        phpinfo();
        $phpInfoRaw = (string) ob_get_clean();

        if ($phpInfoRaw !== '') {
            if (preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $phpInfoRaw, $styleMatch) === 1) {
                $rawCss = trim((string) ($styleMatch[1] ?? ''));
                if ($rawCss !== '') {
                    // Prefix phpinfo selectors so they only affect the extension output wrapper.
                    $phpInfoCss = (string) preg_replace_callback(
                        '/(^|})\s*([^@{}][^{}]*)\s*\{/m',
                        static function (array $matches): string {
                            $boundary = (string) ($matches[1] ?? '');
                            $selectorBlock = trim((string) ($matches[2] ?? ''));
                            if ($selectorBlock === '') {
                                return $matches[0];
                            }

                            $selectors = array_filter(array_map(
                                static fn (string $selector): string => trim($selector),
                                explode(',', $selectorBlock)
                            ));
                            if ($selectors === []) {
                                return $matches[0];
                            }

                            $prefixedSelectors = [];
                            foreach ($selectors as $selector) {
                                $selector = preg_replace('/^(html|body)\b/i', '.raven-phpinfo-output', $selector) ?? $selector;
                                if (!str_starts_with($selector, '.raven-phpinfo-output')) {
                                    $selector = '.raven-phpinfo-output ' . $selector;
                                }
                                $prefixedSelectors[] = $selector;
                            }

                            return $boundary . "\n" . implode(', ', $prefixedSelectors) . ' {';
                        },
                        $rawCss
                    );
                }
            }

            $withoutStyle = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $phpInfoRaw);
            if (is_string($withoutStyle)) {
                $phpInfoRaw = $withoutStyle;
            }

            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $phpInfoRaw, $matches) === 1) {
                $phpInfoHtml = (string) ($matches[1] ?? '');
            } else {
                $phpInfoHtml = $phpInfoRaw;
            }
        }
        if (trim(strip_tags($phpInfoHtml)) === '') {
            $phpInfoHtml = '<p class="text-muted mb-0">phpinfo() did not return any output.</p>';
        }
        if ($phpInfoCss === '') {
            $phpInfoCss = '';
        }

        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
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
