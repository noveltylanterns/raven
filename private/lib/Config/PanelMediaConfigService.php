<?php

declare(strict_types=1);

namespace Raven\Lib\Config;


/**
 * Shared panel media configuration and helper-text formatting policy.
 */
final class PanelMediaConfigService
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();

        if ($target === 'avatars') {
            // New path: user.avatar.max_filesize_kb
            $kb = (int) ($config['user']['avatar']['max_filesize_kb'] ?? -1);
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        } elseif ($target === 'images') {
            // New flat path: media.max_filesize_kb
            $kb = (int) ($config['media']['max_filesize_kb'] ?? -1);
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        } else {
            // Legacy sub-section lookup for any unknown target
            $section = $config['media'][$target] ?? null;
            if (is_array($section) && array_key_exists('max_filesize_kb', $section)) {
                $kb = (int) $section['max_filesize_kb'];
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        }

        return max(1, $defaultBytes);
    }

    public function resolveAvatarAllowedExtensionsCsv(): string
    {
        $avatarAllowList = trim((string) $this->config->get('user.avatar.allowed_extensions', ''));
        if ($avatarAllowList !== '') {
            return $avatarAllowList;
        }

        return trim((string) $this->config->get('media.allowed_extensions', ''));
    }

    public function avatarAllowedExtensionsLabel(): string
    {
        $raw = strtolower(trim($this->resolveAvatarAllowedExtensionsCsv()));
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

    public function avatarUploadLimitsNote(): string
    {
        $maxBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
        $maxKilobytes = $maxBytes <= 0 ? 0 : (int) max(1, ceil($maxBytes / 1024));
        $maxWidth = max(1, (int) $this->config->get('user.avatar.max_width', 500));
        $maxHeight = max(1, (int) $this->config->get('user.avatar.max_height', 500));
        $extensions = $this->avatarAllowedExtensionsLabel();

        return 'Max: ' . $maxKilobytes . 'KB, ' . $maxWidth . 'x' . $maxHeight . 'px, ' . $extensions;
    }
}
