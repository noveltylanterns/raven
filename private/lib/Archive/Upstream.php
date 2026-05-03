<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Upstream.php
 * Normalizes and validates update-source settings for the Raven updater.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes and validates update-source configuration settings.
 *
 * Resolves the effective update source (GitHub mirror, custom GitHub repo, or
 * custom git URL) from config or POST data, applies normalization, and
 * validates the resulting source array before it reaches the update workflow.
 */
final class Upstream
{
    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Input sanitizer for repo/URL fields.
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Returns the factory-default update source settings.
     *
     * @return array{mode: string, github_repo: string, repo_url: string, source_url: string, source_label: string}
     */
    public function defaults(): array
    {
        return $this->resolve([
            'mode' => 'github_mirror',
            'github_repo' => 'noveltylanterns/raven',
            'repo_url' => '',
        ]);
    }

    /**
     * Resolves update source settings from the live runtime config array.
     *
     * @param array<string, mixed> $config Full runtime config array.
     * @return array{mode: string, github_repo: string, repo_url: string, source_url: string, source_label: string}
     */
    public function fromConfig(array $config): array
    {
        $defaults = $this->defaults();
        $source = is_array($config['update']['source'] ?? null) ? $config['update']['source'] : [];

        return $this->resolve([
            'mode' => (string) ($source['mode'] ?? $defaults['mode']),
            'github_repo' => (string) ($source['github_repo'] ?? $defaults['github_repo']),
            'repo_url' => (string) ($source['repo_url'] ?? $defaults['repo_url']),
        ]);
    }

    /**
     * Resolves update source settings from a POST payload, falling back to a prior resolved source.
     *
     * @param array<string, mixed> $post Raw POST payload from the update settings form.
     * @param array{mode: string, github_repo: string, repo_url: string, source_url?: string, source_label?: string} $fallback Previously resolved source to fall back to for missing fields.
     * @return array{mode: string, github_repo: string, repo_url: string, source_url: string, source_label: string}
     */
    public function fromPost(array $post, array $fallback): array
    {
        return $this->resolve([
            'mode' => (string) ($post['update_source_mode'] ?? $fallback['mode']),
            'github_repo' => (string) ($post['update_source_github_repo'] ?? $fallback['github_repo']),
            'repo_url' => (string) ($post['update_source_repo_url'] ?? $fallback['repo_url']),
        ]);
    }

    /**
     * Returns a list of human-readable validation errors for a resolved source array.
     *
     * @param array{mode: string, github_repo: string, repo_url: string, source_url?: string, source_label?: string} $source Resolved update source settings.
     * @return array<int, string> List of error strings; empty if valid.
     */
    public function validationErrors(array $source): array
    {
        $errors = [];
        $mode = $source['mode'];

        if (!in_array($mode, ['github_mirror', 'github_custom', 'repo_custom'], true)) {
            $errors[] = 'Update source mode is invalid.';
            return $errors;
        }

        if (($mode === 'github_mirror' || $mode === 'github_custom') && !$this->validateGithubRepo($source['github_repo'])) {
            $errors[] = 'GitHub source must use the form owner/repo.';
        }

        if ($mode === 'repo_custom' && !$this->validateCustomRepo($source['repo_url'])) {
            $errors[] = 'Custom repo must be a valid git URL.';
        }

        if ($source['source_url'] === '') {
            $errors[] = 'Resolved update source URL is empty.';
        }

        return $errors;
    }

    /**
     * Normalizes a raw source array into a fully resolved source record.
     *
     * @param array{mode?: string, github_repo?: string, repo_url?: string} $source Raw source fields.
     * @return array{mode: string, github_repo: string, repo_url: string, source_url: string, source_label: string}
     */
    private function resolve(array $source): array
    {
        $mode = $this->normalizeMode((string) ($source['mode'] ?? 'github_mirror'));
        $githubRepo = $this->normalizeGithubRepo((string) ($source['github_repo'] ?? ''));
        $customUrl = $this->normalizeCustomRepo((string) ($source['repo_url'] ?? ''));

        // The mirror mode always points to the canonical Raven repository.
        if ($mode === 'github_mirror') {
            $githubRepo = 'noveltylanterns/raven';
        }

        $sourceUrl = '';
        $sourceLabel = '';
        if ($mode === 'repo_custom') {
            $sourceUrl = $customUrl;
            $sourceLabel = $customUrl;
        } else {
            $sourceUrl = $githubRepo !== '' ? 'https://github.com/' . $githubRepo . '.git' : '';
            $sourceLabel = $githubRepo;
        }

        return [
            'mode' => $mode,
            'github_repo' => $githubRepo,
            'repo_url' => $customUrl,
            'source_url' => $sourceUrl,
            'source_label' => $sourceLabel,
        ];
    }

    /**
     * Coerces a raw mode string to one of the three accepted values, defaulting to github_mirror.
     *
     * @param string $mode Raw mode value from config or POST.
     * @return string One of 'github_mirror', 'github_custom', or 'repo_custom'.
     */
    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, ['github_mirror', 'github_custom', 'repo_custom'], true)) {
            return 'github_mirror';
        }

        return $normalized;
    }

    /**
     * Sanitizes a GitHub repo string to owner/repo form, stripping full URLs and .git suffixes.
     *
     * @param string $repo Raw repo value (may be owner/repo, full GitHub URL, or empty).
     * @return string Normalized owner/repo string, or empty string if invalid.
     */
    private function normalizeGithubRepo(string $repo): string
    {
        $value = trim($this->input->text($repo, 255));
        if ($value === '') {
            return '';
        }

        // Accept full GitHub URLs and extract the owner/repo segment.
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $matches) === 1) {
            $value = (string) ($matches[1] ?? '');
        }

        $value = trim($value, "/ \t\n\r\0\x0B");
        $value = preg_replace('/\.git$/i', '', $value) ?? $value;
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /**
     * Trims and sanitizes a custom git repository URL.
     *
     * @param string $url Raw URL from config or POST.
     * @return string Trimmed URL string.
     */
    private function normalizeCustomRepo(string $url): string
    {
        return trim($this->input->text($url, 500));
    }

    /**
     * Returns whether a custom repo URL is a valid git-compatible URL.
     *
     * Accepts http(s)://, ssh://, git://, and SCP-style (user@host:path) git URLs.
     *
     * @param string $url URL to validate.
     * @return bool True when the URL matches a supported git remote format.
     */
    private function validateCustomRepo(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (preg_match('/^(https?|ssh|git):\/\//i', $url) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:.+$/', $url) === 1;
    }

    /**
     * Returns whether a GitHub repo string is in valid owner/repo form.
     *
     * Applied after normalization, so the input is already stripped of full URLs
     * and .git suffixes. An empty string always returns false.
     *
     * @param string $repo Normalized repo string to validate.
     * @return bool True when the string matches owner/repo format.
     */
    private function validateGithubRepo(string $repo): bool
    {
        if ($repo === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo) === 1;
    }
}
