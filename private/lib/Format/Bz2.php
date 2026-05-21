<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Bz2.php
 * Bzip2 single-file compression and decompression handler.
 * Docs: https://lanterns.io/raven
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
        // BZ2 compression requires the PHP bz2 extension.
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP bz2 extension is not available.');
        }

        // Source path must point to an existing regular file.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('BZ2 source file not found: ' . $sourcePath);
        }

        $blockSize = max(1, min(9, $blockSize));

        $dir = dirname($targetPath);
        // Ensure destination directory exists before opening output stream.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for BZ2 output: ' . $dir);
        }

        $input = fopen($sourcePath, 'rb');
        // Source stream must open successfully for chunked reads.
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open source file for BZ2 compression: ' . $sourcePath);
        }

        $output = bzopen($targetPath, 'w');
        // Destination bz2 stream must open successfully for chunked writes.
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('Failed to open BZ2 output file for writing: ' . $targetPath);
        }

        // Stream source bytes into compressed output in fixed-size chunks.
        try {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                // Abort when source read operation fails.
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read from source file during BZ2 compression.');
                }

                // Abort when compressed write operation fails.
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
        // BZ2 decompression requires the PHP bz2 extension.
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP bz2 extension is not available.');
        }

        // Source path must point to an existing regular file.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('BZ2 source file not found: ' . $sourcePath);
        }

        $dir = dirname($targetPath);
        // Ensure destination directory exists before opening output stream.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for BZ2 decompression output: ' . $dir);
        }

        $input = bzopen($sourcePath, 'r');
        // Source bz2 stream must open successfully for chunked reads.
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open BZ2 file for reading: ' . $sourcePath);
        }

        $output = fopen($targetPath, 'wb');
        // Destination stream must open successfully for chunked writes.
        if (!is_resource($output)) {
            bzclose($input);
            throw new RuntimeException('Failed to open output file for BZ2 decompression: ' . $targetPath);
        }

        // Stream decompressed bytes from source into output in fixed-size chunks.
        try {
            while (!feof($input)) {
                $chunk = bzread($input, self::CHUNK_SIZE);
                // Abort when compressed read operation fails.
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read compressed data during BZ2 decompression.');
                }

                // Empty chunk indicates EOF/stream drain for bzread loop.
                if ($chunk === '') {
                    break;
                }

                // Abort when output write operation fails.
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
