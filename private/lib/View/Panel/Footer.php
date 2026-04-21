<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Footer.php
 * Shared panel footer renderer and route-asset collector.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Renders the standard panel footer and collects route-owned assets for it.
 */
final class Footer
{
    /**
     * @var array<int, string>
     */
    private static array $styleBlocks = [];

    /**
     * @var array<int, string>
     */
    private static array $scriptBlocks = [];

    /**
     * @var array<int, string>
     */
    private static array $htmlBlocks = [];

    /**
     * Clears any route-owned assets collected for the current panel render.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$styleBlocks = [];
        self::$scriptBlocks = [];
        self::$htmlBlocks = [];
    }

    /**
     * Registers one inline stylesheet block for footer output.
     *
     * @param string $css Trusted CSS block without surrounding `<style>` tags.
     * @return void
     */
    public static function pushStyle(string $css): void
    {
        $css = trim($css);
        if ($css === '') {
            return;
        }

        self::$styleBlocks[] = $css;
    }

    /**
     * Registers one inline script block for deferred footer output.
     *
     * @param string $javascript Trusted JavaScript block without surrounding `<script>` tags.
     * @return void
     */
    public static function pushScript(string $javascript): void
    {
        $javascript = trim($javascript);
        if ($javascript === '') {
            return;
        }

        self::$scriptBlocks[] = $javascript;
    }

    /**
     * Registers one trusted HTML fragment for deferred footer output.
     *
     * This supports route-owned script tags or other body-end fragments that
     * are easier to preserve verbatim than to model through a narrower API.
     *
     * @param string $html Trusted HTML fragment safe to echo directly.
     * @return void
     */
    public static function pushHtml(string $html): void
    {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        self::$htmlBlocks[] = $html;
    }

    /**
     * Renders the standard visible panel footer plus any collected style blocks.
     *
     * @return string Footer markup ready for the panel wrapper.
     */
    public static function render(): string
    {
        $styleBlocks = self::$styleBlocks;
        self::$styleBlocks = [];

        ob_start();
        ?>
<footer class="rvnp-footer">
    <p class="small text-muted mb-0">
        Powered by <a href="https://raven.lanterns.io" target="_blank" rel="noopener noreferrer">Raven CMS</a>
        <span aria-hidden="true">&middot;</span>
        <a href="https://raven.lanterns.io" target="_blank" rel="noopener noreferrer">Documentation</a>
    </p>
</footer>
<?php foreach ($styleBlocks as $styleBlock): ?>
<style>
<?= $styleBlock ?>
</style>
<?php endforeach; ?>
<?php

        return (string) ob_get_clean();
    }

    /**
     * Renders deferred footer assets after the wrapper's shared runtime scripts.
     *
     * @return string Trusted route-owned asset markup for the end of `<body>`.
     */
    public static function renderDeferredAssets(): string
    {
        $htmlBlocks = self::$htmlBlocks;
        $scriptBlocks = self::$scriptBlocks;
        self::$htmlBlocks = [];
        self::$scriptBlocks = [];

        if ($htmlBlocks === [] && $scriptBlocks === []) {
            return '';
        }

        ob_start();
        foreach ($htmlBlocks as $htmlBlock) {
            ?>
<?= $htmlBlock ?>
<?php
        }

        foreach ($scriptBlocks as $scriptBlock) {
            ?>
<script>
<?= $scriptBlock ?>
</script>
<?php
        }

        return (string) ob_get_clean();
    }
}
