<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelThemeAssetResponder.php
 * Panel-theme asset fast-path responder for front-controller rewrites.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

/**
 * Serves panel theme assets before route dispatch when the web server rewrites
 * requests through the panel front controller.
 *
 * This helper only owns the panel-global theme-asset fast path. Route-family
 * handlers still belong in panel controllers and routing registrars.
 */
final class PanelThemeAssetResponder
{
    /**
     * Streams one static file from `~/panel/theme/` when the request targets the
     * public panel theme asset prefix.
     *
     * @param array<string, mixed> $rvn Shared Raven runtime container.
     * @param string $path Normalized panel-internal request path.
     * @param string $method Current HTTP method.
     * @return bool True when the responder already handled the request.
     */
    public static function serveIfMatched(array $rvn, string $path, string $method): bool
    {
        // Only serve static assets for read-only methods.
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // Theme assets are publicly accessible under `/{panel_path}/theme/...`.
        if (!str_starts_with($path, '/theme/')) {
            return false;
        }

        $relativePath = ltrim(substr($path, strlen('/theme/')), '/');
        if ($relativePath === '') {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        // Reject traversal and malformed paths before touching filesystem.
        if (
            str_contains($relativePath, '..')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || preg_match('/^[a-z0-9_\/\.-]+$/i', $relativePath) !== 1
        ) {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        $themeRoot = rtrim((string) ($rvn['root'] ?? ''), '/') . '/panel/theme';
        $themeRootReal = realpath($themeRoot);
        $assetReal = realpath($themeRoot . '/' . $relativePath);

        // Realpath checks guarantee requested file stays under theme root.
        if (
            $themeRootReal === false
            || $assetReal === false
            || !is_file($assetReal)
            || !is_readable($assetReal)
            || ($assetReal !== $themeRootReal && !str_starts_with($assetReal, $themeRootReal . DIRECTORY_SEPARATOR))
        ) {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        $extension = strtolower((string) pathinfo($assetReal, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        $isFontAsset = in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true);
        $lastModifiedTs = (int) @filemtime($assetReal);
        if ($lastModifiedTs <= 0) {
            $lastModifiedTs = time();
        }
        $fileSize = (int) @filesize($assetReal);
        if ($fileSize < 0) {
            $fileSize = 0;
        }
        $etag = '"' . sha1($assetReal . '|' . $fileSize . '|' . $lastModifiedTs) . '"';

        // Prevent partially buffered output from corrupting static asset responses.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Avoid forcing Content-Length since upstream compression may alter body size.
        header_remove('Content-Length');
        // Session cache limiter may emit anti-cache headers; clear them for static files.
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
        header('ETag: ' . $etag);
        // Fonts are fingerprinted by filename and safe to cache for longer.
        if ($isFontAsset) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: public, max-age=3600');
        }

        $ifNoneMatchRaw = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatchRaw !== '') {
            $etagMatches = false;
            foreach (explode(',', $ifNoneMatchRaw) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $candidate === $etag || $candidate === ('W/' . $etag)) {
                    $etagMatches = true;
                    break;
                }
            }

            if ($etagMatches) {
                http_response_code(304);
                return true;
            }
        }

        $ifModifiedSinceRaw = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSinceRaw !== '') {
            $ifModifiedSinceTs = strtotime($ifModifiedSinceRaw);
            if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $lastModifiedTs) {
                http_response_code(304);
                return true;
            }
        }

        if ($method === 'HEAD') {
            return true;
        }

        $stream = @fopen($assetReal, 'rb');
        if (!is_resource($stream)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to open panel theme asset file.';
            return true;
        }

        if (@fpassthru($stream) === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to stream panel theme asset file.';
        }
        fclose($stream);
        return true;
    }
}
