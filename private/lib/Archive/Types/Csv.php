<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Types/Csv.php
 * Generic CSV handler — read, write, and stream CSV data.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive\Types;

use Generator;
use RuntimeException;

/**
 * Canonical CSV handler for Raven core and extensions.
 *
 * Provides streaming reads (via Generator), file writes, and output-stream
 * streaming for export responses. All operations work row-by-row so that
 * large datasets never require the entire file to be loaded into memory.
 *
 * Consumers that previously wrote CSV inline with fputcsv/fgetcsv should
 * migrate to this class to benefit from consistent BOM handling, separator
 * normalization, and upload error message helpers.
 */
final class Csv
{
    /**
     * Yields rows from a CSV file as indexed or associative arrays.
     *
     * When `$hasHeader` is true the first row is used as array keys; subsequent
     * rows are returned as associative arrays keyed by the header values. When
     * false each row is returned as a zero-indexed array of string values.
     *
     * Skips blank lines and silently strips a UTF-8 BOM from the first field of
     * the first row if present.
     *
     * @param string $filePath  Absolute path to the .csv file.
     * @param bool   $hasHeader Whether the first row contains column names.
     * @param string $separator Field separator character; defaults to `,`.
     * @return Generator<int, array<int|string, string>> Rows yielded one at a time.
     * @throws RuntimeException When the file cannot be opened.
     */
    public function read(string $filePath, bool $hasHeader = true, string $separator = ','): Generator
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('CSV file not found: ' . $filePath);
        }

        $stream = fopen($filePath, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to open CSV file for reading: ' . $filePath);
        }

        try {
            yield from $this->readStream($stream, $hasHeader, $separator);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Yields rows from an already-open stream as indexed or associative arrays.
     *
     * The caller retains ownership of the stream handle; this method does not
     * close it. Useful when the stream was opened elsewhere (e.g. a tmp upload).
     *
     * @param resource $stream    Open file-stream resource positioned at the start.
     * @param bool     $hasHeader Whether the first row contains column names.
     * @param string   $separator Field separator character; defaults to `,`.
     * @return Generator<int, array<int|string, string>> Rows yielded one at a time.
     */
    public function readStream($stream, bool $hasHeader = true, string $separator = ','): Generator
    {
        $sep = $separator !== '' ? $separator[0] : ',';
        $headers = null;
        $rowIndex = 0;

        while (($raw = fgetcsv($stream, 0, $sep)) !== false) {
            // fgetcsv returns [null] for blank lines; skip them.
            if ($raw === [null]) {
                continue;
            }

            // Cast all fields to string — fgetcsv can return int/float on numeric fields.
            $row = array_map('strval', $raw);

            if ($rowIndex === 0) {
                // Strip UTF-8 BOM from the very first field if present.
                if (isset($row[0]) && str_starts_with($row[0], "\xEF\xBB\xBF")) {
                    $row[0] = substr($row[0], 3);
                }

                if ($hasHeader) {
                    $headers = $row;
                    $rowIndex++;
                    continue;
                }
            }

            if ($headers !== null) {
                // Combine header keys with the current row, padding or trimming to
                // match header count so associative access is always safe.
                $count = count($headers);
                $padded = array_pad($row, $count, '');
                yield array_combine($headers, array_slice($padded, 0, $count));
            } else {
                yield $row;
            }

            $rowIndex++;
        }
    }

    /**
     * Writes rows to a CSV file, optionally prepending a header row.
     *
     * Opens the output file for writing (creates or truncates). Writes a UTF-8
     * BOM prefix when `$writeBom` is true to ensure Excel compatibility.
     *
     * @param string              $filePath  Absolute path to the output .csv file.
     * @param iterable<mixed>     $rows      Rows to write; each row must be an array of scalars.
     * @param array<int,string>|null $header Optional header row written before data rows.
     * @param string              $separator Field separator character; defaults to `,`.
     * @param bool                $writeBom  Prepend a UTF-8 BOM; useful for Excel downloads.
     * @return void
     * @throws RuntimeException When the output file cannot be opened.
     */
    public function write(
        string $filePath,
        iterable $rows,
        ?array $header = null,
        string $separator = ',',
        bool $writeBom = false
    ): void {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for CSV output: ' . $dir);
        }

        $stream = fopen($filePath, 'wb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to open CSV output file for writing: ' . $filePath);
        }

        try {
            $this->writeStream($stream, $rows, $header, $separator, $writeBom);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Writes rows to an already-open stream, optionally prepending a header row.
     *
     * The caller retains ownership of the stream handle.
     *
     * @param resource               $stream    Open writable stream resource.
     * @param iterable<mixed>        $rows      Rows to write; each row must be an array of scalars.
     * @param array<int,string>|null $header    Optional header row.
     * @param string                 $separator Field separator character; defaults to `,`.
     * @param bool                   $writeBom  Prepend a UTF-8 BOM.
     * @return void
     */
    public function writeStream(
        $stream,
        iterable $rows,
        ?array $header = null,
        string $separator = ',',
        bool $writeBom = false
    ): void {
        $sep = $separator !== '' ? $separator[0] : ',';

        if ($writeBom) {
            fwrite($stream, "\xEF\xBB\xBF");
        }

        if ($header !== null) {
            fputcsv($stream, $header, $sep);
        }

        foreach ($rows as $row) {
            fputcsv($stream, is_array($row) ? $row : [$row], $sep);
        }
    }

    /**
     * Streams rows directly to the current PHP output buffer as a CSV download.
     *
     * Flushes all output buffers before writing, then sends appropriate HTTP
     * headers and streams row-by-row via `php://output`. The caller should not
     * produce any other output after calling this method.
     *
     * @param string                 $filename  Suggested download filename (e.g. `export.csv`).
     * @param iterable<mixed>        $rows      Rows to stream.
     * @param array<int,string>|null $header    Optional header row.
     * @param string                 $separator Field separator character; defaults to `,`.
     * @return void
     */
    public function streamToOutput(
        string $filename,
        iterable $rows,
        ?array $header = null,
        string $separator = ','
    ): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $safe = preg_replace('/[^\w\s._-]/', '-', $filename) ?? 'export.csv';
        $safe = trim($safe, '-_.');
        if ($safe === '') {
            $safe = 'export.csv';
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open CSV output stream.';
            return;
        }

        $this->writeStream($stream, $rows, $header, $separator);
        fclose($stream);
    }

    /**
     * Returns a human-readable error message for a PHP upload error code.
     *
     * Intended for CSV upload error reporting in extension and core upload handlers.
     *
     * @param int    $code      One of the `UPLOAD_ERR_*` constants.
     * @param string $fileLabel Human-readable label for the file being uploaded (e.g. `CSV`).
     * @return string Error message safe for display.
     */
    public function uploadErrorMessage(int $code, string $fileLabel = 'CSV'): string
    {
        $label = trim($fileLabel) ?: 'CSV';
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' upload exceeds server upload size limits.',
            UPLOAD_ERR_PARTIAL                        => $label . ' upload was only partially received.',
            UPLOAD_ERR_NO_FILE                        => 'Please choose a ' . $label . ' file to upload.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server failed to write uploaded ' . $label . ' file.',
            UPLOAD_ERR_EXTENSION                      => 'A server extension blocked the ' . $label . ' upload.',
            default                                   => $label . ' upload failed with an unknown error.',
        };
    }
}
