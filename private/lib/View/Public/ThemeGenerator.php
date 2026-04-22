<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeGenerator.php
 * Public-theme generator for scaffold and duplicate workflows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Shared public-theme generator for create and duplicate workflows.
 */
final class ThemeGenerator
{
    /**
     * Creates the canonical starter file set for one public theme.
     *
     * @param string $themePath Absolute destination theme directory.
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta Theme manifest metadata for the scaffold.
     * @param bool $generateAgentsFile Whether to generate local agent-guidance files.
     * @param bool $generateComposerFile Whether to generate a starter `composer.json`.
     * @param bool $generatePackageFile Whether to generate a starter `package.json`.
     * @return void
     *
     * @throws \RuntimeException When a scaffold file or directory cannot be created.
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
            . " * ~/public/theme/" . $meta['slug'] . "/tpl/wrapper.php\n"
            . " * " . $safeNameForDoc . " theme wrapper template.\n"
            . " * Docs: https://raven.lanterns.io\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
            . "    http_response_code(404);\n"
            . "    exit;\n"
            . "}\n"
            . "\$siteName = trim((string) (\$site['name'] ?? 'Raven CMS'));\n"
            . "if (\$siteName === '') {\n"
            . "    \$siteName = 'Raven CMS';\n"
            . "}\n"
            . "\$metaTitle = trim((string) (\$meta['title'] ?? ''));\n"
            . "\$documentTitle = \$metaTitle === '' ? \$siteName : (\$metaTitle . ' [' . \$siteName . ']');\n"
            . "?>\n"
            . "<!doctype html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "    <meta charset=\"utf-8\">\n"
            . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "    <title><?= htmlspecialchars(\$documentTitle, ENT_QUOTES, 'UTF-8') ?></title>\n"
            . "    <meta name=\"description\" content=\"{meta:desc}\">\n"
            . "    {if site:feed_rss_url}<link rel=\"alternate\" type=\"application/rss+xml\" title=\"RSS Feed\" href=\"{site:feed_rss_url}\">{/if}\n"
            . "    {if site:feed_atom_url}<link rel=\"alternate\" type=\"application/atom+xml\" title=\"Atom Feed\" href=\"{site:feed_atom_url}\">{/if}\n"
            . "    <link rel=\"stylesheet\" href=\"{theme:url}/css/style.css\">\n"
            . "</head>\n"
            . "<body>\n"
            . "{raw:content}\n"
            . "</body>\n"
            . "</html>\n";

        $home = "<?php\n\n"
            . "/**\n"
            . " * RAVEN CMS\n"
            . " * ~/public/theme/" . $meta['slug'] . "/tpl/home.php\n"
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
            . "    {if page:title_show}<h2>{page:title}</h2>{/if}\n"
            . "    {if page:content}\n"
            . "    {each page:content}\n"
            . "    <div{if item:css_id} id=\"{item:css_id}\"{/if} class=\"{item:class}\">{raw:item:html}</div>\n"
            . "    {/each}\n"
            . "    {/if}\n"
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
        $this->writeScaffoldFile($themePath . '/tpl/wrapper.php', $wrapper);
        $this->writeScaffoldFile($themePath . '/tpl/home.php', $home);
        if ($generateAgentsFile) {
            $this->writeAgentGuidanceBundle($themePath, $meta);
        }
        if ($generateComposerFile) {
            $this->writeScaffoldFile($themePath . '/composer.json', $this->composerFileContent($meta));
        }
        if ($generatePackageFile) {
            $this->writeScaffoldFile($themePath . '/package.json', $this->packageFileContent($meta));
        }
    }

    /**
     * Refreshes metadata-owned files after cloning an existing theme tree.
     *
     * Clone workflows preserve copied templates/assets, then rewrite the files
     * that declare the new theme identity so the clone no longer advertises the
     * source theme's manifest/name metadata.
     *
     * @param string $themePath Absolute destination theme directory.
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta Theme manifest metadata for the cloned theme.
     * @param bool $generateAgentsFile Whether to generate local agent-guidance files.
     * @param bool $generateComposerFile Whether to generate a starter `composer.json`.
     * @param bool $generatePackageFile Whether to generate a starter `package.json`.
     * @return void
     *
     * @throws \RuntimeException When one metadata-owned file cannot be created.
     */
    public function finalizeClone(
        string $themePath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false,
        bool $generatePackageFile = false
    ): void {
        $this->writeThemeManifest(
            $themePath . '/theme.json',
            [
                'name' => $meta['name'],
                'is_child_theme' => $meta['is_child_theme'],
                'parent_theme' => $meta['is_child_theme'] ? $meta['parent_theme'] : '',
            ]
        );

        if ($generateAgentsFile) {
            $this->writeAgentGuidanceBundle($themePath, $meta);
        }
        if ($generateComposerFile) {
            $this->writeScaffoldFile($themePath . '/composer.json', $this->composerFileContent($meta));
        }
        if ($generatePackageFile) {
            $this->writeScaffoldFile($themePath . '/package.json', $this->packageFileContent($meta));
        }
    }

    /**
     * Recursively copies one public-theme directory into a new target directory.
     *
     * @param string $sourceDirectory Existing source theme directory.
     * @param string $targetDirectory Destination directory for the cloned theme.
     * @return void
     *
     * @throws \RuntimeException When the source cannot be resolved or a file operation fails.
     */
    public function copyDirectoryRecursively(string $sourceDirectory, string $targetDirectory): void
    {
        if (!is_dir($sourceDirectory)) {
            throw new \RuntimeException('Clone source directory not found: ' . $sourceDirectory);
        }

        $sourceRoot = realpath($sourceDirectory);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Failed to resolve clone source directory.');
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Failed to create clone target directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            if ($item->isLink()) {
                throw new \RuntimeException('Theme clone source contains symlinks, which are not supported.');
            }

            $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $targetPath = rtrim($targetDirectory, '/\\') . '/' . str_replace('\\', '/', $relativePath);
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException('Failed to create clone directory: ' . $targetPath);
                }
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Failed to create clone directory: ' . $targetDir);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Failed to copy clone file: ' . $relativePath);
            }

            @chmod($targetPath, 0644);
        }
    }

    /**
     * Builds the canonical local agent-guidance file content for one theme.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta Theme metadata used to describe the scaffold.
     * @return string Rendered agent-guidance file body.
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
        $content .= "- `tpl/wrapper.php`\n";
        $content .= "- `css/style.css`\n";
        $content .= "\n## Safety Rules\n";
        $content .= "- Keep customizations inside this theme directory.\n";
        $content .= "- Do not edit core templates under `private/tpl/` for theme-only changes.\n";
        $content .= "- Use escaped brace tags by default; reserve `{raw:...}` for trusted HTML only.\n";

        return $content;
    }

    /**
     * Writes the canonical theme guidance file plus compatibility symlinks.
     *
     * @param string $themePath Absolute theme directory path.
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta Theme metadata used for the guidance body.
     * @return void
     */
    public function writeAgentGuidanceBundle(string $themePath, array $meta): void
    {
        $this->writeScaffoldFile($themePath . '/agents', $this->agentsFileContent($meta));
        $this->writeRelativeSymlink($themePath . '/AGENTS.md', 'agents');
        $this->writeRelativeSymlink($themePath . '/CLAUDE.md', 'agents');
    }

    /**
     * Builds the starter `composer.json` payload for one public theme.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta Theme metadata used in the package manifest.
     * @return string JSON document content ending with a trailing newline.
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
     * Builds the starter `package.json` payload for one public theme.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta Theme metadata used in the package manifest.
     * @return string JSON document content ending with a trailing newline.
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

    /**
     * Writes one scaffold-owned file to disk, creating parent directories as needed.
     *
     * @param string $targetPath Target file path.
     * @param string $content File contents to write.
     * @return void
     *
     * @throws \RuntimeException When the parent directory or file cannot be created.
     */
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

    /**
     * Writes or replaces one relative symlink.
     *
     * @param string $linkPath Absolute link path to create.
     * @param string $target Relative symlink target.
     * @return void
     */
    private function writeRelativeSymlink(string $linkPath, string $target): void
    {
        if (is_link($linkPath) || is_file($linkPath)) {
            @unlink($linkPath);
        }

        if (!@symlink($target, $linkPath)) {
            throw new \RuntimeException('Failed to write symlink: ' . $linkPath);
        }
    }
}
