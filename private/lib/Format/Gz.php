<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Gz.php
 * Gzip single-file compression and decompression handler.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Gzip single-file handler for Raven core and extensions.
 *
 * Gzip is a file-level compression format, not a container archive format.
 * Use the Tar handler for .tar.gz archives (multiple files). This handler
 * compresses or decompresses exactly one file at a time via PHP's zlib
 * extension (gzopen/gzwrite/gzread).
 */
final class Gz
{
    /** Default read/write chunk size in bytes. */
    private const CHUNK_SIZE = 65536;

    /**
     * Compresses a file to a .gz file using gzip.
     *
     * Reads the source file in chunks to avoid loading it entirely into memory.
     * Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the uncompressed source file.
     * @param string $targetPath Absolute path where the .gz output file should land.
     * @param int    $level      Compression level 1 (fast) to 9 (best); default 6.
     * @return void
     * @throws RuntimeException When the source cannot be read or the output cannot be written.
     */
    public function compress(string $sourcePath, string $targetPath, int $level = 6): void
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('GZ source file not found: ' . $sourcePath);
        }

        $level = max(1, min(9, $level));

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for GZ output: ' . $dir);
        }

        $input = fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open source file for GZ compression: ' . $sourcePath);
        }

        $output = gzopen($targetPath, 'wb' . $level);
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('Failed to open GZ output file for writing: ' . $targetPath);
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read from source file during GZ compression.');
                }

                if (gzwrite($output, $chunk) === false) {
                    throw new RuntimeException('Failed to write compressed data to GZ output file.');
                }
            }
        } catch (\Throwable $e) {
            gzclose($output);
            fclose($input);
            @unlink($targetPath);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        gzclose($output);
        fclose($input);
    }

    /**
     * Decompresses a .gz file to a plain file.
     *
     * Reads the compressed file in chunks to avoid loading it entirely into
     * memory. Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the .gz compressed file.
     * @param string $targetPath Absolute path where the decompressed output should land.
     * @return void
     * @throws RuntimeException When the source cannot be read or the output cannot be written.
     */
    public function decompress(string $sourcePath, string $targetPath): void
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('GZ source file not found: ' . $sourcePath);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for GZ decompression output: ' . $dir);
        }

        $input = gzopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open GZ file for reading: ' . $sourcePath);
        }

        $output = fopen($targetPath, 'wb');
        if (!is_resource($output)) {
            gzclose($input);
            throw new RuntimeException('Failed to open output file for GZ decompression: ' . $targetPath);
        }

        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read compressed data during GZ decompression.');
                }

                if (fwrite($output, $chunk) === false) {
                    throw new RuntimeException('Failed to write decompressed data during GZ decompression.');
                }
            }
        } catch (\Throwable $e) {
            gzclose($input);
            fclose($output);
            @unlink($targetPath);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        gzclose($input);
        fclose($output);
    }
}
