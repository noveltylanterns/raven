<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/MediaConfig.php
 * Shared config readers for non-avatar media uploads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Encapsulates generic media config policies used by gallery and meta-image flows.
 */
final class MediaConfig
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
     * Resolves the effective max upload size for a media target.
     *
     * @param string $target Logical target such as `images`.
     * @param int $defaultBytes Fallback byte limit when config is unset.
     * @return int Maximum allowed bytes, or `0` when unlimited.
     */
    public function resolveMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();

        // The base `images` target uses the legacy top-level media filesize key.
        if ($target === 'images') {
            $kb = (int) ($config['media']['max_filesize_kb'] ?? -1);
            // Non-negative values are explicit policy values, including 0 for unlimited.
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        } else {
            $section = $config['media'][$target] ?? null;
            // Target-specific media sections can override the shared default bytes.
            if (is_array($section) && array_key_exists('max_filesize_kb', $section)) {
                $kb = (int) $section['max_filesize_kb'];
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        }

        return max(1, $defaultBytes);
    }
}
