<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/routes_public.php
 * Repositories extension public route registration.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

use Raven\Lib\Routing\Router;
use Raven\Repo\RepoService;

/**
 * Registers Repositories module public routes.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   notFound?: callable(): void,
 *   input?: mixed,
 *   extensionDirectory?: string,
 *   renderPublicExtension?: callable(string, array<string, mixed>, string|null): void
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);
    $renderPublicExtension = $context['renderPublicExtension'] ?? null;
    $notFoundHandler = $context['notFound'] ?? null;

    if (!isset($rvn['config']) || !is_callable($renderPublicExtension)) {
        return;
    }

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
    /** @var mixed $rawRepoServices */
    $rawRepoServices = is_array($rawExtensionServices) ? ($rawExtensionServices['repo'] ?? []) : [];
    /** @var mixed $repoServiceRaw */
    $repoServiceRaw = is_array($rawRepoServices) ? ($rawRepoServices['service'] ?? null) : null;
    if (!$repoServiceRaw instanceof RepoService) {
        return;
    }

    $svc = $repoServiceRaw;
    $indexUrl = rtrim($svc->baseUrl(), '/') . '/repo';

    $normalizeRepoSlug = static function (mixed $value): string {
        $candidate = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $candidate) === 1 ? $candidate : '';
    };

    $notFound = static function () use ($notFoundHandler): void {
        if (is_callable($notFoundHandler)) {
            $notFoundHandler();
            return;
        }

        http_response_code(404);
        echo 'Not Found';
    };

    $siteData = static function (string $currentUrl) use ($rvn, $svc): array {
        $domain = trim((string) $rvn['config']->get('site.domain', 'localhost'));
        $protocol = trim((string) $rvn['config']->get('site.protocol', 'https'));
        if (!in_array($protocol, ['http', 'https'], true)) {
            $protocol = 'https';
        }

        return [
            'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
            'url' => $svc->baseUrl(),
            'domain' => $domain !== '' ? $domain : 'localhost',
            'protocol' => $protocol,
            'feed_rss_url' => '',
            'feed_atom_url' => '',
            'current_url' => $currentUrl,
        ];
    };

    $renderPage = static function (
        string $template,
        array $viewData,
        string $title,
        string $description,
        string $currentUrl
    ) use ($renderPublicExtension, $siteData): void {
        $renderPublicExtension(
            $template,
            [
                'site' => $siteData($currentUrl),
                'meta' => [
                    'title' => $title,
                    'desc' => $description !== '' ? $description : 'Read-only Git repository browser.',
                    'robots' => 'index,follow',
                ],
            ] + $viewData,
            'wrapper'
        );
    };

    $streamFile = static function (string $path, string $mimeType, string $filename, bool $attachment): void {
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header(
            'Content-Disposition: ' . ($attachment ? 'attachment' : 'inline')
            . '; filename="' . addcslashes($filename, "\"\\") . '"'
        );
        readfile($path);
        @unlink($path);
    };

    $router->add('GET', '/repo', static function () use ($svc, $renderPage, $indexUrl): void {
        $renderPage(
            'public_index',
            [
                'repos' => $svc->publicRepoList(),
                'indexUrl' => $indexUrl,
            ],
            'Repositories',
            'Browse public repository mirrors published through Raven.',
            $indexUrl
        );
    });

    $router->add('GET', '/repo/{slug}', static function (array $params) use (
        $normalizeRepoSlug,
        $svc,
        $notFound,
        $renderPage,
        $indexUrl
    ): void {
        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        $repo = $slug !== '' ? $svc->getRepo($slug) : null;
        if ($repo === null || empty($repo['is_public_listed'])) {
            $notFound();
            return;
        }

        $requestedRef = is_scalar($_GET['ref'] ?? null) ? trim((string) $_GET['ref']) : null;
        $requestedPath = is_scalar($_GET['path'] ?? null) ? (string) $_GET['path'] : '';
        $readmeFlag = strtolower(trim((string) ($_GET['readme'] ?? 'on')));
        $includeReadme = !in_array($readmeFlag, ['0', 'off', 'false', 'no'], true);
        $repoPathBase = $indexUrl . '/' . rawurlencode($slug);

        try {
            $payload = $svc->buildBrowsePayload($slug, $requestedRef, $requestedPath, $includeReadme);
        } catch (\Throwable) {
            $notFound();
            return;
        }

        $currentQuery = [];
        $resolvedRef = trim((string) ($payload['ref'] ?? ''));
        $resolvedPath = trim((string) ($payload['path'] ?? ''));
        if ($resolvedRef !== '') {
            $currentQuery['ref'] = $resolvedRef;
        }
        if ($resolvedPath !== '') {
            $currentQuery['path'] = $resolvedPath;
        }
        if (!$includeReadme) {
            $currentQuery['readme'] = 'off';
        }
        $currentUrl = $currentQuery === [] ? $repoPathBase : ($repoPathBase . '?' . http_build_query($currentQuery));

        $renderPage(
            'public_repo',
            [
                'payload' => $payload,
                'repo' => $repo,
                'indexUrl' => $indexUrl,
                'repoPathBase' => $repoPathBase,
                'cloneUrl' => $svc->cloneUrl($repo),
                'readmeEnabled' => $includeReadme,
            ],
            (string) ($repo['label'] ?? $repo['slug'] ?? 'Repository'),
            (string) ($repo['description'] ?? 'Read-only Git repository mirror.'),
            $currentUrl
        );
    });

    $router->add('GET', '/repo/{slug}/raw', static function (array $params) use ($normalizeRepoSlug, $svc, $notFound, $streamFile): void {
        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        if ($slug === '') {
            $notFound();
            return;
        }

        try {
            $file = $svc->readPublicFile(
                $slug,
                is_scalar($_GET['ref'] ?? null) ? trim((string) $_GET['ref']) : null,
                is_scalar($_GET['path'] ?? null) ? (string) $_GET['path'] : ''
            );
        } catch (\Throwable) {
            $notFound();
            return;
        }

        $streamFile(
            (string) ($file['temp_path'] ?? ''),
            (string) ($file['mime_type'] ?? 'application/octet-stream'),
            (string) ($file['filename'] ?? 'file'),
            false
        );
    });

    $router->add('GET', '/repo/{slug}/download', static function (array $params) use ($normalizeRepoSlug, $svc, $notFound, $streamFile): void {
        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        if ($slug === '') {
            $notFound();
            return;
        }

        try {
            $file = $svc->readPublicFile(
                $slug,
                is_scalar($_GET['ref'] ?? null) ? trim((string) $_GET['ref']) : null,
                is_scalar($_GET['path'] ?? null) ? (string) $_GET['path'] : ''
            );
        } catch (\Throwable) {
            $notFound();
            return;
        }

        $streamFile(
            (string) ($file['temp_path'] ?? ''),
            (string) ($file['mime_type'] ?? 'application/octet-stream'),
            (string) ($file['filename'] ?? 'file'),
            true
        );
    });

    $router->add('GET', '/repo/{slug}/archive', static function (array $params) use ($normalizeRepoSlug, $svc, $notFound, $streamFile): void {
        $slug = $normalizeRepoSlug($params['slug'] ?? null);
        if ($slug === '') {
            $notFound();
            return;
        }

        try {
            $archive = $svc->buildArchive(
                $slug,
                is_scalar($_GET['ref'] ?? null) ? trim((string) $_GET['ref']) : null,
                is_scalar($_GET['format'] ?? null) ? (string) $_GET['format'] : 'zip'
            );
        } catch (\Throwable) {
            $notFound();
            return;
        }

        $streamFile(
            (string) ($archive['temp_path'] ?? ''),
            (string) ($archive['mime_type'] ?? 'application/octet-stream'),
            (string) ($archive['filename'] ?? 'archive.zip'),
            true
        );
    });
};
