<?php

declare(strict_types=1);

namespace Raven\Lib\Config;

/**
 * Shared normalization helpers for panel config-editor scalar/media fields.
 */
final class ConfigEditorNormalizer
{
    public function normalizeMetaAbsoluteUrlPathValue(
        string $siteProtocol,
        string $siteDomain,
        string $rawPathOrUrl,
        bool $allowAbsoluteUrlPaste = true
    ): string {
        $rawPathOrUrl = trim($rawPathOrUrl);
        if ($rawPathOrUrl === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $rawPathOrUrl) === 1) {
            if (!$allowAbsoluteUrlPaste) {
                throw new \RuntimeException('OpenGraph Image must be a local file path relative to site.domain, not a full URL.');
            }

            if (filter_var($rawPathOrUrl, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('Meta URL fields must be valid absolute URLs or URL paths.');
            }

            return $rawPathOrUrl;
        }

        $normalizedDomain = $this->normalizeDomainHostForUrlPrefix($siteDomain);
        if ($normalizedDomain === '') {
            throw new \RuntimeException('site.domain must be set before saving URL-path meta fields.');
        }

        return $this->normalizeSiteProtocol($siteProtocol) . '://' . $normalizedDomain . '/' . ltrim($rawPathOrUrl, '/');
    }

    public function normalizeDomainHostForUrlPrefix(string $rawDomain): string
    {
        $rawDomain = trim($rawDomain);
        if ($rawDomain === '') {
            return '';
        }

        if (str_contains($rawDomain, '://')) {
            $parsedHost = trim((string) parse_url($rawDomain, PHP_URL_HOST));
            $parsedPort = parse_url($rawDomain, PHP_URL_PORT);
            if ($parsedHost !== '') {
                return $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
            }
        }

        $rawDomain = preg_replace('/[\/?#].*$/', '', $rawDomain) ?? $rawDomain;
        return trim($rawDomain);
    }

    public function normalizeSiteProtocol(string $rawProtocol): string
    {
        $protocol = strtolower(trim($rawProtocol));
        return in_array($protocol, ['http', 'https'], true) ? $protocol : 'https';
    }

    public function normalizeInt(string $path, string $value): int
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException($path . ' must be an integer.');
        }

        return (int) $value;
    }

    public function normalizeFloat(string $path, string $value): float
    {
        if ($value === '' || !is_numeric($value)) {
            throw new \RuntimeException($path . ' must be numeric.');
        }

        return (float) $value;
    }

    public function normalizeBool(string $path, string $value): bool
    {
        $normalized = strtolower($value);

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException($path . ' must be a boolean (true/false).');
    }

    public function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        if ($path === 'media.images.upload_target') {
            $target = strtolower($value);
            if ($target !== 'local') {
                throw new \RuntimeException('media.images.upload_target currently supports only local.');
            }

            return $target;
        }

        if ($path === 'media.images.strip_exif') {
            return $this->normalizeBool($path, $value);
        }

        if ($path === 'media.images.max_filesize_kb') {
            $size = $this->normalizeInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if ($path === 'media.images.max_files_per_upload') {
            $count = $this->normalizeInt($path, $value);
            if ($count < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $count;
        }

        if ($path === 'media.images.allowed_extensions') {
            $normalized = strtolower($value);
            $parts = array_map('trim', explode(',', $normalized));
            $parts = array_values(array_filter($parts, static fn (string $ext): bool => $ext !== ''));
            if ($parts === []) {
                return '';
            }

            foreach ($parts as $ext) {
                if (!preg_match('/^[a-z0-9]+$/', $ext)) {
                    throw new \RuntimeException($path . ' may only contain comma-separated alphanumeric extensions.');
                }
            }

            return implode(',', array_values(array_unique($parts)));
        }

        $dimensionPaths = [
            'media.images.small.width',
            'media.images.small.height',
            'media.images.med.width',
            'media.images.med.height',
            'media.images.large.width',
            'media.images.large.height',
        ];
        if (in_array($path, $dimensionPaths, true)) {
            $dimension = $this->normalizeInt($path, $value);
            if ($dimension < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $dimension;
        }

        return $value;
    }
}
