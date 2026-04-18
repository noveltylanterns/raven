<?php

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Shared normalizer for flattening nested `$_FILES` payload trees.
 */
final class Upload
{
    /**
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
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
     * @param mixed $nameNode
     * @param mixed $typeNode
     * @param mixed $tmpNameNode
     * @param mixed $errorNode
     * @param mixed $sizeNode
     * @param array<int, array<string, mixed>> $uploads
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
}
