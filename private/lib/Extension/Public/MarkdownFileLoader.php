<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/MarkdownFileLoader.php
 * Defines the extension contract for loading external Markdown-file sources.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

/**
 * Loads Markdown content from an extension-owned external source reference.
 */
interface MarkdownFileLoader
{
    /**
     * Loads one Markdown source referenced by a public page body block.
     *
     * @param string $reference External source reference supplied by the page author.
     * @return string|null Markdown contents, or null when the reference is not available.
     */
    public function load(string $reference): ?string;
}
