<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/AvatarConfig.php
 * Avatar config readers and template-facing avatar path helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Encapsulates avatar limits, extension policy, and view payload helpers.
 */
final class AvatarConfig
{
    private Config $config;

    /**
     * @param Config $config Runtime configuration reader.
     * @return void
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Resolves the effective avatar max upload size.
     *
     * @param int $defaultBytes Fallback byte limit when config is unset.
     * @return int Maximum allowed bytes, or `0` when unlimited.
     */
    public function resolveMaxFilesizeBytes(int $defaultBytes = 1048576): int
    {
        $kb = (int) $this->config->get('user.avatar.max_filesize_kb', -1);
        if ($kb >= 0) {
            return $kb === 0 ? 0 : max(1, $kb * 1024);
        }

        return max(1, $defaultBytes);
    }

    /**
     * Resolves the avatar upload allowlist CSV.
     *
     * @return string Raw comma-separated extension allowlist.
     */
    public function allowedExtensionsCsv(): string
    {
        $avatarAllowList = trim((string) $this->config->get('user.avatar.allowed_extensions', ''));
        if ($avatarAllowList !== '') {
            return $avatarAllowList;
        }

        return trim((string) $this->config->get('media.allowed_extensions', ''));
    }

    /**
     * Formats the avatar extension allowlist into a concise label.
     *
     * @return string Slash-delimited extension label or `none`.
     */
    public function allowedExtensionsLabel(): string
    {
        $raw = strtolower(trim($this->allowedExtensionsCsv()));
        if ($raw === '') {
            return 'none';
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if (!in_array($token, ['gif', 'jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $allowed[$token] = $token;
        }

        if ($allowed === []) {
            return 'none';
        }

        return implode('/', array_values($allowed));
    }

    /**
     * Builds the avatar upload helper text shown in panel forms.
     *
     * @return string Human-readable limits note.
     */
    public function uploadLimitsNote(): string
    {
        $maxBytes = $this->resolveMaxFilesizeBytes();
        $maxKilobytes = $maxBytes <= 0 ? 0 : (int) max(1, ceil($maxBytes / 1024));
        $maxWidth = max(1, (int) $this->config->get('user.avatar.max_width', 500));
        $maxHeight = max(1, (int) $this->config->get('user.avatar.max_height', 500));

        return 'Max: ' . $maxKilobytes . 'KB, ' . $maxWidth . 'x' . $maxHeight . 'px, ' . $this->allowedExtensionsLabel();
    }

    /**
     * Builds template display fields from one stored avatar value.
     *
     * @param string $avatarPath Stored avatar path from user row.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    public function templateData(string $avatarPath): array
    {
        $normalized = trim($avatarPath);
        if ($normalized === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $filename = basename($normalized);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $thumbFilename = $this->thumbnailFilename($filename);
        if (str_contains($normalized, '/')) {
            $dir = ltrim(dirname($normalized), '/');
            return [
                'filename' => $filename,
                'url' => '/' . $dir . '/' . rawurlencode($filename),
                'thumb_url' => '/' . $dir . '/' . rawurlencode($thumbFilename),
            ];
        }

        return [
            'filename' => $filename,
            'url' => '/uploads/avatars/' . rawurlencode($filename),
            'thumb_url' => '/uploads/avatars/' . rawurlencode($thumbFilename),
        ];
    }

    /**
     * Builds the deterministic thumbnail filename for one avatar.
     *
     * @param string $filename Avatar source filename.
     * @return string Thumbnail filename.
     */
    public function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }
}
