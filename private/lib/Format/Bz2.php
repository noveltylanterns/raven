<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Bz2.php
 * Bzip2 single-file compression and decompression handler.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Bzip2 single-file handler for Raven core and extensions.
 *
 * Bzip2 is a file-level compression format, not a container archive. Use the
 * Tar handler for .tar.bz2 (decompress with this class first, then extract
 * the .tar with the Tar handler). Requires the PHP bz2 extension.
 */
final class Bz2
{
    /** Default read/write chunk size in bytes. */
    private const CHUNK_SIZE = 65536;

    /**
     * Returns true when the PHP bz2 extension is loaded and available.
     *
     * @return bool True when bzip2 support is present.
     */
    public function isAvailable(): bool
    {
        return extension_loaded('bz2');
    }

    /**
     * Compresses a file to a .bz2 file using bzip2.
     *
     * Reads in chunks to avoid loading the source into memory. Creates
     * intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the uncompressed source file.
     * @param string $targetPath Absolute path where the .bz2 output should land.
     * @param int    $blockSize  Block size 1–9 (1 = fastest/least compressed, 9 = most); default 9.
     * @return void
     * @throws RuntimeException When the bz2 extension is missing, the source cannot
     *                          be read, or the output cannot be written.
     */
    public function compress(string $sourcePath, string $targetPath, int $blockSize = 9): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP bz2 extension is not available.');
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException('BZ2 source file not found: ' . $sourcePath);
        }

        $blockSize = max(1, min(9, $blockSize));

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for BZ2 output: ' . $dir);
        }

        $input = fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open source file for BZ2 compression: ' . $sourcePath);
        }

        $output = bzopen($targetPath, 'w');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('Failed to open BZ2 output file for writing: ' . $targetPath);
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read from source file during BZ2 compression.');
                }

                if (bzwrite($output, $chunk) === false) {
                    throw new RuntimeException('Failed to write compressed data to BZ2 output file.');
                }
            }
        } catch (\Throwable $e) {
            bzclose($output);
            fclose($input);
            @unlink($targetPath);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        bzclose($output);
        fclose($input);
    }

    /**
     * Decompresses a .bz2 file to a plain file.
     *
     * Reads the compressed stream in chunks. Creates intermediate directories
     * for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the .bz2 compressed file.
     * @param string $targetPath Absolute path where the decompressed output should land.
     * @return void
     * @throws RuntimeException When the bz2 extension is missing, the source cannot
     *                          be read, or the output cannot be written.
     */
    public function decompress(string $sourcePath, string $targetPath): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP bz2 extension is not available.');
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException('BZ2 source file not found: ' . $sourcePath);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for BZ2 decompression output: ' . $dir);
        }

        $input = bzopen($sourcePath, 'r');
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open BZ2 file for reading: ' . $sourcePath);
        }

        $output = fopen($targetPath, 'wb');
        if (!is_resource($output)) {
            bzclose($input);
            throw new RuntimeException('Failed to open output file for BZ2 decompression: ' . $targetPath);
        }

        try {
            while (!feof($input)) {
                $chunk = bzread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read compressed data during BZ2 decompression.');
                }

                if ($chunk === '') {
                    break;
                }

                if (fwrite($output, $chunk) === false) {
                    throw new RuntimeException('Failed to write decompressed data during BZ2 decompression.');
                }
            }
        } catch (\Throwable $e) {
            bzclose($input);
            fclose($output);
            @unlink($targetPath);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        bzclose($input);
        fclose($output);
    }
}
