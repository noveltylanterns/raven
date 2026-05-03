<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Panel/Content.php
 * Panel-side extension body-block and insertable shortcode catalog for the page editor.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

use Raven\Core\Config;
use Raven\Lib\Extension\Registry;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Catalogs extension-provided body-block types and insertable shortcodes for the panel page editor.
 */
final class Content
{
    private string $projectRoot;
    private InputSanitizer $input;
    private PageBlockParser $pageBlockParser;

    /**
     * Initializes the panel extension content catalog.
     *
     * @param string $projectRoot Absolute project root used to inspect extension manifests and field providers.
     * @param InputSanitizer $input Shared sanitizer for human-facing shortcode labels.
     * @param PageBlockParser $pageBlockParser Shared page-block parser for extension-provided body-block definitions.
     */
    public function __construct(string $projectRoot, InputSanitizer $input, PageBlockParser $pageBlockParser)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->input = $input;
        $this->pageBlockParser = $pageBlockParser;
    }

    /**
     * Returns panel-eligible extension body-block definitions for the page editor block picker.
     *
     * Only enabled content- and module-type extensions with a fields.php provider are included.
     * Results are sorted alphabetically by label so the block picker order stays stable.
     *
     * @param array<string, bool> $enabledMap Enabled-extension map keyed by slug.
     * @param string $extensionsBasePath Absolute extension root path.
     * @param callable(string): array<string, mixed> $manifestReader Callback that returns one extension manifest payload.
     * @return array<string, array{label: string, editor: string}> Extension body-block definitions keyed by type slug.
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

            $fields = Registry::fields(
                $this->projectRoot,
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                ]
            );
            if ($fields === null) {
                continue;
            }

            $definitions = $this->pageBlockParser->normalizeExtensionDefinitions(
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
     * Returns insertable extension shortcode options for the panel editor shortcode picker.
     *
     * Only non-system content- and module-type extensions with a shortcodes.php provider are included.
     * Duplicate shortcode tokens are deduplicated to prevent the picker from listing the same
     * shortcode twice if multiple extensions declare the same token.
     *
     * @param array<string, bool> $enabledMap Enabled-extension map keyed by slug.
     * @param string $extensionsBasePath Absolute extension root path.
     * @param callable(string): array<string, mixed> $manifestReader Callback that returns one extension manifest payload.
     * @param callable(string): array<int, array<string, mixed>> $formsProvider Callback that returns insertable forms for one extension slug.
     * @param Config $config Runtime config used when extensions compute shortcode menus.
     * @return array<int, array{extension: string, label: string, shortcode: string}> Sorted shortcode menu rows.
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

            $shortcodes = Registry::shortcodes(
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
