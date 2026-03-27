<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/lib/shortcodes.php
 * Signup Sheets shortcode provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Returns editor-insertable shortcode entries sourced from enabled signup sheets.
 *
 * @param array{
 *   extension?: string,
 *   forms?: callable(string): array<int, array{name: string, slug: string}>,
 *   config?: \Raven\Core\Config
 * } $context
 * @return array<int, array{label: string, shortcode: string}>
 */
return static function (array $context = []): array {
    /** @var mixed $rawLoader */
    $rawLoader = $context['forms'] ?? null;
    if (!is_callable($rawLoader)) {
        return [];
    }

    try {
        /** @var array<int, array{name?: string, slug?: string}> $forms */
        $forms = $rawLoader('signups');
    } catch (\Throwable) {
        return [];
    }

    if (!is_array($forms)) {
        return [];
    }

    $items = [];
    foreach ($forms as $form) {
        if (!is_array($form)) {
            continue;
        }

        $slug = strtolower(trim((string) ($form['slug'] ?? '')));
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            continue;
        }

        $name = trim((string) ($form['name'] ?? ''));
        if ($name === '') {
            $name = $slug;
        }

        $items[] = [
            'label' => 'Signup Sheets: ' . $name,
            'shortcode' => '[signups slug="' . $slug . '"]',
        ];
    }

    return $items;
};
