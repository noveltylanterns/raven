<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/shortcodes.php
 * Repositories extension shortcode provider.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

/**
 * Returns editor-insertable shortcode entries for the repo content runtime.
 *
 * @param array{
 *   extension?: string,
 *   forms?: callable(string): array<int, array{name: string, slug: string}>,
 *   config?: \Raven\Core\Config
 * } $context
 * @return array<int, array{label: string, shortcode: string}>
 */
return static function (array $context = []): array {
    return [
        [
            'label' => 'Repo Browser',
            'shortcode' => '[repo slug="example-repo"]',
        ],
        [
            'label' => 'Repo Subdirectory',
            'shortcode' => '[repo slug="example-repo" path="src" branch="main"]',
        ],
        [
            'label' => 'Repo File',
            'shortcode' => '[repo slug="example-repo" file="README.md"]',
        ],
        [
            'label' => 'Repo Tree Without README',
            'shortcode' => '[repo slug="example-repo" path="docs" readme="off"]',
        ],
    ];
};