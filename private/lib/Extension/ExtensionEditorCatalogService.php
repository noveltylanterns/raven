<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

use Raven\Lib\Config\Config;
use Raven\Lib\Extension\ExtensionRegistry;
use Raven\Lib\Content\BodyBlockPolicy;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared extension-provided editor menu/catalog helpers.
 */
final class ExtensionEditorCatalogService
{
    private string $projectRoot;
    private InputSanitizer $input;
    private BodyBlockPolicy $bodyBlockPolicy;

    public function __construct(string $projectRoot, InputSanitizer $input, BodyBlockPolicy $bodyBlockPolicy)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->input = $input;
        $this->bodyBlockPolicy = $bodyBlockPolicy;
    }

    /**
     * @param array<string, bool> $enabledMap
     * @param callable(string): array<string, mixed> $manifestReader
     * @return array<string, array{label: string, editor: string}>
     */
    public function panelBodyBlockDefinitions(
        array $enabledMap,
        string $extensionsBasePath,
        callable $manifestReader
    ): array {
        $definitions = [];
        foreach ($enabledMap as $extensionName => $enabled) {
            if (!$enabled) {
                continue;
            }

            $extensionPath = rtrim($extensionsBasePath, '/\\') . '/' . $extensionName;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $manifestReader($extensionPath);
            if (
                !($manifest['valid'] ?? false)
                || !in_array((string) ($manifest['type'] ?? ''), ['content', 'module'], true)
            ) {
                continue;
            }

            $fields = ExtensionRegistry::fields(
                $this->projectRoot,
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                ]
            );
            if ($fields === null) {
                continue;
            }

            $definitions = $this->bodyBlockPolicy->normalizeExtensionDefinitions(
                (string) $extensionName,
                $fields,
                $definitions
            );
        }

        uasort($definitions, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $definitions;
    }

    /**
     * @return array<string, array{label: string, editor: string}>
     */
    public function publicBodyBlockDefinitions(): array
    {
        $definitions = [];
        foreach (ExtensionRegistry::enabledDirectories($this->projectRoot, true) as $extensionName) {
            $manifest = ExtensionRegistry::readManifest($this->projectRoot, $extensionName);
            if (
                !is_array($manifest)
                || !in_array((string) ($manifest['type'] ?? ''), ['content', 'module'], true)
            ) {
                continue;
            }

            $fields = ExtensionRegistry::fields(
                $this->projectRoot,
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                ]
            );
            if ($fields === null) {
                continue;
            }

            $definitions = $this->bodyBlockPolicy->normalizeExtensionDefinitions(
                (string) $extensionName,
                $fields,
                $definitions
            );
        }

        uasort($definitions, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $definitions;
    }

    /**
     * @param array<string, bool> $enabledMap
     * @param callable(string): array<string, mixed> $manifestReader
     * @param callable(string): array<int, array<string, mixed>> $formsProvider
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    public function panelInsertableShortcodes(
        array $enabledMap,
        string $extensionsBasePath,
        callable $manifestReader,
        callable $formsProvider,
        Config $config
    ): array {
        $items = [];
        foreach ($enabledMap as $extensionName => $enabled) {
            if (!$enabled) {
                continue;
            }

            $extensionPath = rtrim($extensionsBasePath, '/\\') . '/' . $extensionName;
            $manifest = $manifestReader($extensionPath);
            $type = strtolower(trim((string) ($manifest['type'] ?? 'content')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            if (
                !($manifest['valid'] ?? false)
                || $isSystemType
                || !in_array($type, ['content', 'module'], true)
            ) {
                continue;
            }

            $shortcodes = ExtensionRegistry::shortcodes(
                $this->projectRoot,
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                    'forms' => $formsProvider,
                    'config' => $config,
                ]
            );
            if ($shortcodes === null) {
                continue;
            }

            foreach ($shortcodes as $entry) {
                $label = $this->input->text((string) ($entry['label'] ?? ''), 180);
                $shortcode = trim((string) ($entry['shortcode'] ?? ''));
                if ($label === '' || $shortcode === '') {
                    continue;
                }

                $items[] = [
                    'extension' => (string) $extensionName,
                    'label' => $label,
                    'shortcode' => $shortcode,
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $deduped = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['shortcode'] ?? '')));
            if ($key === '' || isset($deduped[$key])) {
                continue;
            }

            $deduped[$key] = $item;
        }

        return array_values($deduped);
    }
}
