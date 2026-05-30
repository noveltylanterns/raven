<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Header.php
 * Shared panel header-card renderer for core and extension panel views.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use function Raven\Lib\Security\e;

/**
 * Renders the standard Raven panel header card from declarative view data.
 */
final class Header
{
    /**
     * Renders the standard panel header card markup.
     *
     * Templates pass plain strings for common fields and trusted HTML fragments
     * only for route-specific markup such as buttons, permalinks, or embedded
     * forms. This keeps page-level policy in the template while centralizing the
     * repeated card structure in one canonical helper.
     *
     * @param array<string, mixed> $config Header fields such as title/title_html, intro_html, summary/summary_html, subheading/subheading_html, actions, body_html, and optional class overrides.
     * @return string Rendered `<header>` markup ready to echo from a panel template.
     */
    public static function render(array $config): string
    {
        $titleHtml = self::textOrHtml($config, 'title', 'title_html');
        // Without a title the shared header shell should not render.
        if ($titleHtml === '') {
            return '';
        }

        $introHtml = trim((string) ($config['intro_html'] ?? ''));
        $subheadingHtml = self::textOrHtml($config, 'subheading', 'subheading_html');
        $summaryHtml = self::textOrHtml($config, 'summary', 'summary_html');
        $bodyHtml = trim((string) ($config['body_html'] ?? ''));
        $actions = self::normalizeHtmlList($config['actions'] ?? []);

        // Default classes preserve the existing Bootstrap header-card footprint while
        // still allowing templates to opt into spacing variants such as `mb-3`.
        $cardClass = self::normalizeClass('card', $config['card_class'] ?? '');
        $bodyClass = self::normalizeClass('card-body', $config['body_class'] ?? '');
        $titleClass = trim((string) ($config['title_class'] ?? ''));
        $actionsClass = self::normalizeClass('d-flex flex-wrap gap-2', $config['actions_class'] ?? '');
        $subheadingClass = self::normalizeClass('h5', $config['subheading_class'] ?? '');
        $summaryClass = self::normalizeClass('text-muted mb-0', $config['summary_class'] ?? '');

        ob_start();
        ?>
<header class="<?= e($cardClass) ?>">
    <div class="<?= e($bodyClass) ?>">
        <?php
        /* Render action row only when at least one trusted action fragment exists. */
        if ($actions !== []):
        ?>
            <div class="d-flex align-items-start justify-content-between gap-2">
                <h1<?= $titleClass !== '' ? ' class="' . e($titleClass) . '"' : '' ?>><?= $titleHtml ?></h1>
                <div class="<?= e($actionsClass) ?>">
                    <?php
                    /* Emit action fragments as provided by the route template. */
                    foreach ($actions as $actionHtml):
                    ?>
                        <?= $actionHtml ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <h1<?= $titleClass !== '' ? ' class="' . e($titleClass) . '"' : '' ?>><?= $titleHtml ?></h1>
        <?php endif; ?>
        <?php
        /* Intro markup is optional and shown only when provided by the route. */
        if ($introHtml !== ''):
        ?>
            <?= $introHtml ?>
        <?php endif; ?>
        <?php
        /* Subheading block is optional secondary heading content. */
        if ($subheadingHtml !== ''):
        ?>
            <div class="<?= e($subheadingClass) ?>"><?= $subheadingHtml ?></div>
        <?php endif; ?>
        <?php
        /* Summary copy remains optional for compact headers. */
        if ($summaryHtml !== ''):
        ?>
            <p class="<?= e($summaryClass) ?>"><?= $summaryHtml ?></p>
        <?php endif; ?>
        <?php
        /* Body markup provides optional route-specific detail under the summary. */
        if ($bodyHtml !== ''):
        ?>
            <?= $bodyHtml ?>
        <?php endif; ?>
    </div>
</header>
<?php

        return (string) ob_get_clean();
    }

    /**
     * Resolves either a plain-text or trusted-HTML config field to markup.
     *
     * @param array<string, mixed> $config Header configuration array.
     * @param string $textKey Plain-text field name to HTML-escape when present.
     * @param string $htmlKey Trusted HTML field name to use verbatim when present.
     * @return string Normalized markup string, or an empty string when absent.
     */
    private static function textOrHtml(array $config, string $textKey, string $htmlKey): string
    {
        $html = trim((string) ($config[$htmlKey] ?? ''));
        // Trusted HTML field wins when present so templates can supply rich markup.
        if ($html !== '') {
            return $html;
        }

        $text = trim((string) ($config[$textKey] ?? ''));
        // Return empty when neither HTML nor text is available.
        if ($text === '') {
            return '';
        }

        return e($text);
    }

    /**
     * Normalizes a base CSS class plus an optional template-provided suffix.
     *
     * @param string $base Base class string supplied by the helper.
     * @param mixed $extra Optional extra classes from template config.
     * @return string Combined class string with duplicate whitespace collapsed.
     */
    private static function normalizeClass(string $base, mixed $extra): string
    {
        $class = trim($base . ' ' . trim((string) $extra));
        return preg_replace('/\s+/', ' ', $class) ?? trim($class);
    }

    /**
     * Filters the header action list down to non-empty trusted HTML fragments.
     *
     * @param mixed $value Raw `actions` config value.
     * @return array<int, string> Action markup fragments safe to echo directly.
     */
    private static function normalizeHtmlList(mixed $value): array
    {
        // Non-array values cannot represent a list of action fragments.
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        // Preserve order while filtering empty action entries.
        foreach ($value as $item) {
            $html = trim((string) $item);
            // Skip empty fragments so templates can build lists conditionally.
            if ($html === '') {
                continue;
            }

            $items[] = $html;
        }

        return $items;
    }
}
