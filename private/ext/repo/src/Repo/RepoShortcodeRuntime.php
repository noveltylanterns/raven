<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/src/Repo/RepoShortcodeRuntime.php
 * Shortcode runtime for read-only repository embeds.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Repo;

use Raven\Core\Extension\EmbeddedShortcodeRuntimeInterface;

/**
 * Renders `[repo ...]` embeds through Raven's generic shortcode runtime contract.
 */
final class RepoShortcodeRuntime implements EmbeddedShortcodeRuntimeInterface
{
    private RepoService $service;
    private string $templatePath;

    public function __construct(RepoService $service)
    {
        $this->service = $service;
        $this->templatePath = dirname(__DIR__, 2) . '/tpl/public_embed.php';
    }

    /**
     * Returns the shortcode type token.
     *
     * @return string Runtime token used by Raven's shortcode parser.
     */
    public function type(): string
    {
        return 'repo';
    }

    /**
     * Returns the owning extension key used by Raven's enabled-state checks.
     *
     * @return string Enabled extension directory slug.
     */
    public function extensionKey(): string
    {
        return 'repo';
    }

    /**
     * Renders one repository embed for a matched `[repo ...]` shortcode.
     *
     * Supported arguments:
     * - `slug`   Required repo slug.
     * - `path`   Optional subdirectory path for tree embeds.
     * - `file`   Optional file path for inline file embeds.
     * - `branch` Optional branch/ref override.
     * - `readme` Optional on/off flag for tree embeds.
     *
     * @param array{slug: string, raw_args: string} $context Shortcode render context.
     * @return string Rendered HTML fragment.
     */
    public function render(array $context): string
    {
        $slug = strtolower(trim((string) ($context['slug'] ?? '')));
        if ($slug === '') {
            return '';
        }

        $repo = $this->service->getRepo($slug);
        if ($repo === null || empty($repo['is_public_listed'])) {
            return '';
        }

        $args = $this->parseArguments((string) ($context['raw_args'] ?? ''));
        $requestedRef = trim((string) ($args['branch'] ?? $args['ref'] ?? ''));
        $requestedPath = trim((string) ($args['path'] ?? $args['dir'] ?? ''));
        $requestedFile = trim((string) ($args['file'] ?? ''));
        $includeReadme = $this->booleanArgument($args, 'readme', true);

        try {
            $payload = $this->service->buildBrowsePayload(
                $slug,
                $requestedRef !== '' ? $requestedRef : null,
                $requestedFile !== '' ? $requestedFile : $requestedPath,
                $requestedFile === '' && $includeReadme
            );
        } catch (\Throwable) {
            return '';
        }

        $embedMode = 'notice';
        $notice = trim((string) ($payload['notice'] ?? ''));
        $payloadMode = (string) ($payload['mode'] ?? 'metadata');

        if ($requestedFile !== '') {
            if ($payloadMode === 'file') {
                $embedMode = 'file';
            } elseif ($notice === '') {
                $notice = 'The requested repository file could not be rendered from the public browser.';
            }
        } elseif ($payloadMode === 'tree') {
            $embedMode = 'tree';
        } elseif ($payloadMode === 'downloads' && $notice === '') {
            $notice = 'This repository allows public downloads, but the web browser is disabled for embeds.';
        } elseif ($payloadMode === 'metadata' && $notice === '') {
            $notice = 'This repository only publishes metadata publicly, so tree embeds are unavailable.';
        }

        if ($notice === '' && $embedMode === 'notice') {
            $notice = 'This repository is not currently available for embed output.';
        }

        $canonicalBaseUrl = rtrim($this->service->baseUrl(), '/') . '/repo/' . rawurlencode($slug);

        return $this->renderTemplate([
            'repo' => $repo,
            'payload' => $payload,
            'embedMode' => $embedMode,
            'notice' => $notice,
            'canonicalBaseUrl' => $canonicalBaseUrl,
            'readmeEnabled' => $requestedFile === '' && $includeReadme,
        ]);
    }

    /**
     * Parses simple shortcode arguments into a lowercase key map.
     *
     * Raven only passes `slug` and `raw_args` into content runtimes, so repo
     * embeds parse their own optional `path`, `file`, `branch`, and `readme`
     * arguments locally.
     *
     * @param string $rawArgs Raw argument chunk from inside the shortcode brackets.
     * @return array<string, string> Parsed argument map.
     */
    private function parseArguments(string $rawArgs): array
    {
        $parsed = [];
        if ($rawArgs === '') {
            return $parsed;
        }

        preg_match_all(
            '/([A-Za-z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/',
            $rawArgs,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            if ($key === '') {
                continue;
            }

            $value = '';
            foreach ([2, 3, 4] as $index) {
                if (array_key_exists($index, $match) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }

            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /**
     * Normalizes a boolean-like shortcode flag.
     *
     * @param array<string, string> $args Parsed shortcode arguments.
     * @param string $key Argument key to inspect.
     * @param bool $default Default value when the argument is absent.
     * @return bool Normalized boolean flag.
     */
    private function booleanArgument(array $args, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $args)) {
            return $default;
        }

        return !in_array(strtolower(trim((string) $args[$key])), ['0', 'off', 'false', 'no'], true);
    }

    /**
     * Renders the shortcode HTML through the extension-owned embed template.
     *
     * @param array<string, mixed> $data Template variables.
     * @return string Rendered embed HTML.
     */
    private function renderTemplate(array $data): string
    {
        if (!is_file($this->templatePath)) {
            return '';
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $this->templatePath;
        return (string) ob_get_clean();
    }
}