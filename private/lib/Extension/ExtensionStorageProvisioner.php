<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

use RuntimeException;

/**
 * Creates extension-owned local storage directories under `private/dat/ext/`.
 */
final class ExtensionStorageProvisioner
{
    private string $projectRoot;
    private ManifestContractValidator $manifestValidator;

    public function __construct(string $projectRoot, ?ManifestContractValidator $manifestValidator = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->manifestValidator = $manifestValidator ?? new ManifestContractValidator();
    }

    public function ensureLocalStorageDirectory(string $directoryName): string
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for local storage.');
        }

        $basePath = $this->projectRoot . '/private/dat/ext';
        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new RuntimeException('Failed to create private/dat/ext directory.');
        }

        $targetPath = $basePath . '/' . $directoryName;
        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new RuntimeException('Failed to create private/dat/ext/' . $directoryName . ' directory.');
        }

        return $targetPath;
    }
}
