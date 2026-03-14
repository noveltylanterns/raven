<?php

declare(strict_types=1);

namespace Raven\Lib\Theme;

/**
 * Handles recursive theme-directory cloning for local scaffold workflows.
 */
final class ThemeCloneService
{
    public function copyDirectoryRecursively(string $sourceDirectory, string $targetDirectory): void
    {
        if (!is_dir($sourceDirectory)) {
            throw new \RuntimeException('Clone source directory not found: ' . $sourceDirectory);
        }

        $sourceRoot = realpath($sourceDirectory);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Failed to resolve clone source directory.');
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Failed to create clone target directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            if ($item->isLink()) {
                throw new \RuntimeException('Theme clone source contains symlinks, which are not supported.');
            }

            $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $targetPath = rtrim($targetDirectory, '/\\') . '/' . str_replace('\\', '/', $relativePath);
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException('Failed to create clone directory: ' . $targetPath);
                }
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Failed to create clone directory: ' . $targetDir);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Failed to copy clone file: ' . $relativePath);
            }

            @chmod($targetPath, 0644);
        }
    }
}
