<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/Content.php
 * Public-side extension body-block catalog for the page renderer.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

use Raven\Lib\Extension\Registry;
use Raven\Lib\Parser\PageBlockParser;

/**
 * Catalogs extension-provided body-block types for the public page renderer.
 */
final class Content
{
    private string $projectRoot;
    private PageBlockParser $pageBlockParser;

    /**
     * Initializes the public extension content catalog.
     *
     * @param string $projectRoot Absolute project root used to discover enabled extensions and their field providers.
     * @param PageBlockParser $pageBlockParser Shared page-block parser for extension-provided body-block definitions.
     */
    public function __construct(string $projectRoot, PageBlockParser $pageBlockParser)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->pageBlockParser = $pageBlockParser;
    }

    /**
     * Returns extension body-block definitions for the public page renderer.
     *
     * Reads the live enabled-extension state from disk since public rendering
     * does not have access to a panel-assembled enabled map.
     * Only content- and module-type extensions with a fields.php provider are included.
     *
     * @return array<string, array{label: string, editor: string}> Extension body-block definitions keyed by type slug.
     */
    public function publicBodyBlockDefinitions(): array
    {
        $definitions = [];
        // Walk enabled extension directories and collect eligible block definitions.
        foreach (Registry::enabledDirectories($this->projectRoot, true) as $extensionName) {
            $manifest = Registry::readManifest($this->projectRoot, $extensionName);
            // Include only content/module manifests for public block rendering.
            if (
                !is_array($manifest)
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
            // Skip extensions without a valid fields.php provider payload.
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
}
