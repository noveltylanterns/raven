<?php

declare(strict_types=1);

namespace Raven\Lib\Update;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes and validates update-source settings.
 */
final class UpdateSourceResolver
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @return array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * }
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
     * @param array<string, mixed> $config
     * @return array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * }
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
     * @param array<string, mixed> $post
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url?: string,
     *   source_label?: string
     * } $fallback
     * @return array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * }
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
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url?: string,
     *   source_label?: string
     * } $source
     * @return array<int, string>
     */
    public function validationErrors(array $source): array
    {
        $errors = [];
        $mode = $source['mode'];

        if (!in_array($mode, ['github_mirror', 'github_custom', 'repo_custom'], true)) {
            $errors[] = 'Update source mode is invalid.';
            return $errors;
        }

        if (($mode === 'github_mirror' || $mode === 'github_custom') && $source['github_repo'] === '') {
            $errors[] = 'GitHub source must use the form owner/repo.';
        }

        if ($mode === 'repo_custom' && !$this->isValidCustomRepoUrl($source['repo_url'])) {
            $errors[] = 'Custom repo must be a valid git URL.';
        }

        if ($source['source_url'] === '') {
            $errors[] = 'Resolved update source URL is empty.';
        }

        return $errors;
    }

    /**
     * @param array{
     *   mode?: string,
     *   github_repo?: string,
     *   repo_url?: string
     * } $source
     * @return array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * }
     */
    private function resolve(array $source): array
    {
        $mode = $this->normalizeMode((string) ($source['mode'] ?? 'github_mirror'));
        $githubRepo = $this->normalizeGitHubRepo((string) ($source['github_repo'] ?? ''));
        $repoUrl = $this->normalizeRepoUrl((string) ($source['repo_url'] ?? ''));

        if ($mode === 'github_mirror') {
            $githubRepo = 'noveltylanterns/raven';
        }

        $sourceUrl = '';
        $sourceLabel = '';
        if ($mode === 'repo_custom') {
            $sourceUrl = $repoUrl;
            $sourceLabel = $repoUrl;
        } else {
            $sourceUrl = $githubRepo !== '' ? 'https://github.com/' . $githubRepo . '.git' : '';
            $sourceLabel = $githubRepo;
        }

        return [
            'mode' => $mode,
            'github_repo' => $githubRepo,
            'repo_url' => $repoUrl,
            'source_url' => $sourceUrl,
            'source_label' => $sourceLabel,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, ['github_mirror', 'github_custom', 'repo_custom'], true)) {
            return 'github_mirror';
        }

        return $normalized;
    }

    private function normalizeGitHubRepo(string $repo): string
    {
        $value = trim($this->input->text($repo, 255));
        if ($value === '') {
            return '';
        }

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

    private function normalizeRepoUrl(string $url): string
    {
        return trim($this->input->text($url, 500));
    }

    private function isValidCustomRepoUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (preg_match('/^(https?|ssh|git):\/\//i', $url) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:.+$/', $url) === 1;
    }
}
