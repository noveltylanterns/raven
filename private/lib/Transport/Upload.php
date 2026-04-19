<?php

/**
 * RAVEN CMS
 * ~/private/lib/Transport/Upload.php
 * Shared upload normalization and validation helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Shared upload normalizer and baseline validation policy.
 *
 * Keeps low-level `$_FILES` flattening, PHP upload error text, HTTP-upload
 * validation, size checks, and client-filename extension checks in one place
 * so panel, public, and extension upload forms do not drift apart.
 */
final class Upload
{
    /**
     * Flattens one raw `$_FILES` payload tree into upload rows.
     *
     * Supports both single-file payloads and nested multi-file structures.
     * Empty `UPLOAD_ERR_NO_FILE` leaves are skipped.
     *
     * @param mixed $raw Raw `$_FILES[...]` payload node.
     * @return array<int, array<string, mixed>> Flat upload rows ready for validation.
     */
    public function normalize(mixed $raw): array
    {
        if (!is_array($raw) || !isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error'], $raw['size'])) {
            return [];
        }

        $uploads = [];
        $this->flattenNodes(
            $raw['name'],
            $raw['type'],
            $raw['tmp_name'],
            $raw['error'],
            $raw['size'],
            $uploads
        );

        return array_values($uploads);
    }

    /**
     * Returns the first upload row from one raw `$_FILES` payload.
     *
     * This keeps single-upload workflows from repeating `normalize()[0] ?? null`
     * throughout panel, public, and extension request handlers.
     *
     * @param mixed $raw Raw `$_FILES[...]` payload node.
     * @return array<string, mixed>|null First upload row, or null when no file was supplied.
     */
    public function first(mixed $raw): ?array
    {
        $uploads = $this->normalize($raw);
        return $uploads[0] ?? null;
    }

    /**
     * Validates one upload row as a completed HTTP file upload.
     *
     * This covers the shared baseline checks used by all upload forms before
     * domain-specific MIME, archive, or image validation is applied.
     *
     * @param mixed $raw Raw `$_FILES[...]` payload node or one normalized upload row.
     * @param string $fileLabel Human-facing file label such as `CSV`, `image`, or `theme archive`.
     * @param array{
     *   require_http_upload?: bool,
     *   min_bytes?: int,
     *   max_bytes?: int|null,
     *   empty_error?: string,
     *   too_large_error?: string
     * } $options Optional validation overrides.
     * @return array{ok: bool, error?: string, upload?: array<string, mixed>, size?: int}
     */
    public function validateSingleUpload(mixed $raw, string $fileLabel = 'file', array $options = []): array
    {
        $upload = $this->first($raw);
        if ($upload === null) {
            return ['ok' => false, 'error' => $this->uploadErrorMessage(UPLOAD_ERR_NO_FILE, $fileLabel)];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->uploadErrorMessage($uploadError, $fileLabel)];
        }

        $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
        $requireHttpUpload = (bool) ($options['require_http_upload'] ?? true);
        if (
            $tmpPath === ''
            || !is_file($tmpPath)
            || ($requireHttpUpload && !is_uploaded_file($tmpPath))
        ) {
            return [
                'ok' => false,
                'error' => 'Uploaded ' . $this->fileLabelSubject($fileLabel) . ' could not be validated as an HTTP upload.',
            ];
        }

        $size = max(0, (int) ($upload['size'] ?? 0));
        $minBytes = max(0, (int) ($options['min_bytes'] ?? 1));
        if ($size < $minBytes) {
            return [
                'ok' => false,
                'error' => (string) ($options['empty_error'] ?? (ucfirst($this->fileLabelSubject($fileLabel)) . ' appears empty.')),
            ];
        }

        /** @var int|null $maxBytes */
        $maxBytes = isset($options['max_bytes']) ? (int) $options['max_bytes'] : null;
        if ($maxBytes !== null && $maxBytes > 0 && $size > $maxBytes) {
            return [
                'ok' => false,
                'error' => (string) ($options['too_large_error'] ?? (ucfirst($this->fileLabelSubject($fileLabel)) . ' exceeds upload size limits.')),
            ];
        }

        return [
            'ok' => true,
            'upload' => $upload,
            'size' => $size,
        ];
    }

    /**
     * Returns true when one filename uses any allowed extension suffix.
     *
     * Supports both simple suffixes such as `csv` and multipart extensions such
     * as `tar.gz` when callers pass them explicitly in `$allowedExtensions`.
     *
     * @param string $filename Client-facing filename to inspect.
     * @param array<int, string> $allowedExtensions Allowed suffixes without dots required.
     * @return bool True when the filename ends in one allowed extension.
     */
    public function filenameUsesAllowedExtension(string $filename, array $allowedExtensions): bool
    {
        $candidate = strtolower(trim($filename));
        if ($candidate === '') {
            return false;
        }

        foreach ($allowedExtensions as $allowedExtension) {
            $normalizedExtension = ltrim(strtolower(trim((string) $allowedExtension)), '.');
            if ($normalizedExtension === '') {
                continue;
            }

            if (str_ends_with($candidate, '.' . $normalizedExtension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns one display-safe PHP upload error message.
     *
     * @param int $code One of the `UPLOAD_ERR_*` constants.
     * @param string $fileLabel Human-facing file label such as `image` or `CSV`.
     * @return string Error text safe for panel/public display.
     */
    public function uploadErrorMessage(int $code, string $fileLabel = 'file'): string
    {
        $subject = $this->fileLabelSubject($fileLabel);
        $article = $this->indefiniteArticle($subject);

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ucfirst($subject) . ' exceeds server upload size limits.',
            UPLOAD_ERR_PARTIAL => ucfirst($subject) . ' upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose ' . $article . ' ' . $subject . ' to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded ' . $subject . '.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the ' . $subject . ' upload.',
            default => ucfirst($subject) . ' upload failed with an unknown error.',
        };
    }

    /**
     * Flattens one nested `$_FILES` subtree into upload rows.
     *
     * @param mixed $nameNode Current `name` subtree node.
     * @param mixed $typeNode Current `type` subtree node.
     * @param mixed $tmpNameNode Current `tmp_name` subtree node.
     * @param mixed $errorNode Current `error` subtree node.
     * @param mixed $sizeNode Current `size` subtree node.
     * @param array<int, array<string, mixed>> $uploads Output accumulator.
     * @return void
     */
    private function flattenNodes(
        mixed $nameNode,
        mixed $typeNode,
        mixed $tmpNameNode,
        mixed $errorNode,
        mixed $sizeNode,
        array &$uploads
    ): void {
        if (is_array($nameNode)) {
            foreach ($nameNode as $index => $childNameNode) {
                $this->flattenNodes(
                    $childNameNode,
                    is_array($typeNode) && array_key_exists($index, $typeNode) ? $typeNode[$index] : null,
                    is_array($tmpNameNode) && array_key_exists($index, $tmpNameNode) ? $tmpNameNode[$index] : null,
                    is_array($errorNode) && array_key_exists($index, $errorNode) ? $errorNode[$index] : UPLOAD_ERR_NO_FILE,
                    is_array($sizeNode) && array_key_exists($index, $sizeNode) ? $sizeNode[$index] : null,
                    $uploads
                );
            }

            return;
        }

        $error = is_array($errorNode) ? UPLOAD_ERR_NO_FILE : (int) $errorNode;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $name = is_array($nameNode) ? '' : trim((string) $nameNode);
        $tmpName = is_array($tmpNameNode) ? '' : trim((string) $tmpNameNode);
        if ($name === '' && $tmpName === '') {
            return;
        }

        $uploads[] = [
            'name' => $name,
            'type' => is_array($typeNode) ? '' : (string) $typeNode,
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => is_array($sizeNode) ? 0 : (int) $sizeNode,
        ];
    }

    /**
     * Normalizes one file label into a noun phrase suitable for messages.
     *
     * @param string $fileLabel Human-facing file label.
     * @return string Message-ready noun phrase such as `CSV file` or `image file`.
     */
    private function fileLabelSubject(string $fileLabel): string
    {
        $label = trim($fileLabel);
        if ($label === '') {
            return 'file';
        }

        return str_ends_with(strtolower($label), 'file') ? $label : $label . ' file';
    }

    /**
     * Resolves a simple indefinite article for one noun phrase.
     *
     * @param string $subject Message-ready noun phrase.
     * @return string `a` or `an` for display text.
     */
    private function indefiniteArticle(string $subject): string
    {
        $firstCharacter = strtolower($subject[0] ?? 'f');
        return in_array($firstCharacter, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }
}
