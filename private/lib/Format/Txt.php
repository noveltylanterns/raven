<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Txt.php
 * Plain-text file read/write helper for Raven core and extensions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Canonical plain-text file handler for extensions and core services.
 */
final class Txt
{
    /**
     * Reads one text file from disk.
     *
     * @param string $path Absolute file path.
     * @return string File contents.
     * @throws RuntimeException When the file is missing or unreadable.
     */
    public function read(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Text file not found: ' . $path);
        }

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Failed to read text file: ' . $path);
        }

        return $content;
    }

    /**
     * Writes one text file atomically with an optional chmod.
     *
     * @param string   $path Absolute file path.
     * @param string   $content Text content to persist.
     * @param int|null $mode Optional chmod applied after a successful write.
     * @return void
     * @throws RuntimeException When the file cannot be written.
     */
    public function write(string $path, string $content, ?int $mode = null): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create directory for text output: ' . $directory);
        }

        $tmpPath = $path . '.tmp';
        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary text file: ' . $tmpPath);
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize text file write: ' . $path);
        }

        if ($mode !== null) {
            @chmod($path, $mode);
        }
    }
}
