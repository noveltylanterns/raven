<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Toolbar.php
 * Shared panel action-row renderer for mirrored button toolbars.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use function Raven\Lib\Security\e;

/**
 * Renders the standard panel toolbar shell from declarative view data.
 */
final class Toolbar
{
    /**
     * Renders one toolbar wrapper around trusted action fragments.
     *
     * Templates keep route-specific button policy and form wiring local, then
     * pass the finished HTML fragments here so the shared wrapper markup stays
     * centralized and consistent across core and bundled extensions.
     *
     * @param array<string, mixed> $config Toolbar configuration including `items`, optional `tag`, and optional `class`.
     * @return string Rendered toolbar wrapper markup ready to echo from a panel template.
     */
    public static function render(array $config): string
    {
        $items = self::normalizeHtmlList($config['items'] ?? []);
        // Skip wrapper markup entirely when no action fragments were supplied.
        if ($items === []) {
            return '';
        }

        $tag = self::normalizeTag((string) ($config['tag'] ?? 'nav'));
        $class = trim((string) ($config['class'] ?? ''));

        ob_start();
        ?>
<<?= $tag ?><?= $class !== '' ? ' class="' . e($class) . '"' : '' ?>>
    <?php
    /* Render each trusted action fragment in caller-provided order. */
    foreach ($items as $itemHtml):
    ?>
        <?= $itemHtml ?>
    <?php endforeach; ?>
</<?= $tag ?>>
<?php

        return (string) ob_get_clean();
    }

    /**
     * Filters the toolbar item list down to non-empty trusted HTML fragments.
     *
     * @param mixed $value Raw `items` config value.
     * @return array<int, string> Toolbar markup fragments safe to echo directly.
     */
    private static function normalizeHtmlList(mixed $value): array
    {
        // Non-array values cannot represent a toolbar action list.
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        // Filter to non-empty trusted HTML fragments while preserving order.
        foreach ($value as $item) {
            $html = trim((string) $item);
            // Ignore empty fragment entries from conditional template assembly.
            if ($html === '') {
                continue;
            }

            $items[] = $html;
        }

        return $items;
    }

    /**
     * Restricts the wrapper element name to safe HTML tag characters.
     *
     * @param string $tag Requested wrapper element.
     * @return string Sanitized wrapper element name, defaulting to `nav`.
     */
    private static function normalizeTag(string $tag): string
    {
        $normalizedTag = strtolower(trim($tag));
        // Fall back to <nav> when tag is blank or fails safe-tag validation.
        if ($normalizedTag === '' || !preg_match('/^[a-z][a-z0-9:-]*$/', $normalizedTag)) {
            return 'nav';
        }

        return $normalizedTag;
    }
}
