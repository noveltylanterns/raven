<?php

declare(strict_types=1);

namespace Raven\Lib\Theme;

/**
 * Shared public-theme scaffold generator for panel create workflows.
 */
final class ThemeScaffoldService
{
    /**
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta
     */
    public function createSkeleton(
        string $themePath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false,
        bool $generatePackageFile = false
    ): void {
        if (!is_dir($themePath) && !mkdir($themePath, 0775, true) && !is_dir($themePath)) {
            throw new \RuntimeException('Failed to create theme directory.');
        }

        $safeNameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $wrapper = "<?php\n\n"
            . "/**\n"
            . " * RAVEN CMS\n"
            . " * ~/public/theme/" . $meta['slug'] . "/vis/wrapper.php\n"
            . " * " . $safeNameForDoc . " theme wrapper template.\n"
            . " * Docs: https://raven.lanterns.io\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
            . "    http_response_code(404);\n"
            . "    exit;\n"
            . "}\n"
            . "?>\n"
            . "<!doctype html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "    <meta charset=\"utf-8\">\n"
            . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "    <title>{view_meta:document_title}</title>\n"
            . "    <meta name=\"description\" content=\"{view_meta:description}\">\n"
            . "    <link rel=\"stylesheet\" href=\"/theme/{site:public_theme_css}/css/style.css\">\n"
            . "</head>\n"
            . "<body>\n"
            . "{raw:content}\n"
            . "</body>\n"
            . "</html>\n";

        $home = "<?php\n\n"
            . "/**\n"
            . " * RAVEN CMS\n"
            . " * ~/public/theme/" . $meta['slug'] . "/vis/home.php\n"
            . " * " . $safeNameForDoc . " homepage template scaffold.\n"
            . " * Docs: https://raven.lanterns.io\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
            . "    http_response_code(404);\n"
            . "    exit;\n"
            . "}\n"
            . "?>\n"
            . "<section class=\"container py-4\">\n"
            . "    <h1>{site:name}</h1>\n"
            . "    {if page:display_title_resolved}<h2>{page:title}</h2>{/if}\n"
            . "    <div>{raw:page:content}</div>\n"
            . "</section>\n";

        $css = "/* RAVEN CMS */\n"
            . "/* ~/public/theme/" . $meta['slug'] . "/css/style.css */\n"
            . "/* " . $safeNameForDoc . " public-theme stylesheet scaffold. */\n\n"
            . ":root {\n"
            . "  --rvn-theme-bg: #f6f7fb;\n"
            . "  --rvn-theme-fg: #1d2433;\n"
            . "  --rvn-theme-accent: #2f5ee5;\n"
            . "}\n\n"
            . "body {\n"
            . "  background: var(--rvn-theme-bg);\n"
            . "  color: var(--rvn-theme-fg);\n"
            . "  font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif;\n"
            . "}\n\n"
            . "a {\n"
            . "  color: var(--rvn-theme-accent);\n"
            . "}\n";

        $this->writeThemeManifest(
            $themePath . '/theme.json',
            [
                'name' => $meta['name'],
                'is_child_theme' => $meta['is_child_theme'],
                'parent_theme' => $meta['is_child_theme'] ? $meta['parent_theme'] : '',
            ]
        );
        $this->writeScaffoldFile($themePath . '/css/style.css', $css);
        $this->writeScaffoldFile($themePath . '/vis/wrapper.php', $wrapper);
        $this->writeScaffoldFile($themePath . '/vis/home.php', $home);
        if ($generateAgentsFile) {
            $this->writeScaffoldFile($themePath . '/AGENTS.md', $this->agentsFileContent($meta));
        }
        if ($generateComposerFile) {
            $this->writeScaffoldFile($themePath . '/composer.json', $this->composerFileContent($meta));
        }
        if ($generatePackageFile) {
            $this->writeScaffoldFile($themePath . '/package.json', $this->packageFileContent($meta));
        }
    }

    /**
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta
     */
    public function agentsFileContent(array $meta): string
    {
        $name = trim((string) ($meta['name'] ?? 'Theme'));
        $slug = trim((string) ($meta['slug'] ?? 'theme'));
        $isChildTheme = !empty($meta['is_child_theme']);
        $parentTheme = trim((string) ($meta['parent_theme'] ?? ''));

        $content = "# {$name} Theme Agent Guide\n\n";
        $content .= "Last updated: " . gmdate('Y-m-d') . "\n\n";
        $content .= "## Scope\n";
        $content .= "- This file applies only to `public/theme/{$slug}/`.\n";
        $content .= "- Follow project-wide theme contracts in `public/theme/AGENTS.md`.\n";
        if ($isChildTheme && $parentTheme !== '') {
            $content .= "- This theme is a child theme of `{$parentTheme}`.\n";
        }
        $content .= "\n## Required Files\n";
        $content .= "- `theme.json`\n";
        $content .= "- `vis/wrapper.php`\n";
        $content .= "- `css/style.css`\n";
        $content .= "\n## Safety Rules\n";
        $content .= "- Keep customizations inside this theme directory.\n";
        $content .= "- Do not edit core templates under `private/vis/` for theme-only changes.\n";
        $content .= "- Use escaped brace tags by default; reserve `{raw:...}` for trusted HTML only.\n";

        return $content;
    }

    /**
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    public function composerFileContent(array $meta): string
    {
        $slug = strtolower(trim((string) ($meta['slug'] ?? 'theme')));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? 'theme';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            $slug = 'theme';
        }

        $name = trim((string) ($meta['name'] ?? 'Raven Theme'));
        if ($name === '') {
            $name = 'Raven Theme';
        }

        $payload = [
            'name' => 'noveltylanterns/raven-theme-' . $slug,
            'description' => $name . ' public theme package for Raven CMS.',
            'type' => 'project',
            'version' => '0.1.0',
            'license' => 'GPL-3.0-or-later',
            'require' => [
                'php' => '^8.5',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to generate theme composer.json content.');
        }

        return $encoded . "\n";
    }

    /**
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    public function packageFileContent(array $meta): string
    {
        $slug = strtolower(trim((string) ($meta['slug'] ?? 'theme')));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? 'theme';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            $slug = 'theme';
        }

        $name = trim((string) ($meta['name'] ?? 'Raven Theme'));
        if ($name === '') {
            $name = 'Raven Theme';
        }

        $payload = [
            'name' => '@raven/theme-' . $slug,
            'version' => '0.1.0',
            'private' => true,
            'description' => $name . ' frontend theme package for Raven CMS.',
            'scripts' => [
                'build' => 'echo "Add your theme build pipeline here"',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to generate theme package.json content.');
        }

        return $encoded . "\n";
    }

    /**
     * @param array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $manifest
     */
    private function writeThemeManifest(string $manifestPath, array $manifest): void
    {
        $payload = [
            'name' => (string) $manifest['name'],
            'is_child_theme' => (bool) $manifest['is_child_theme'],
            'parent_theme' => (bool) $manifest['is_child_theme'] ? (string) $manifest['parent_theme'] : '',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to build theme manifest JSON.');
        }

        $this->writeScaffoldFile($manifestPath, $encoded . "\n");
    }

    private function writeScaffoldFile(string $targetPath, string $content): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create directory: ' . $directory);
        }

        $written = file_put_contents($targetPath, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to write file: ' . $targetPath);
        }

        @chmod($targetPath, 0644);
    }
}
