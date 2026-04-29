<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/UserDataParser.php
 * Read-only user-profile contact normalization and social metadata helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\UserRead;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Shared profile-contact normalization and repository-backed user read helper.
 */
final class UserDataParser
{
    private const REQUIRED_OPTION_KEYS = [
        'email' => true,
        'phone' => true,
        'homepage' => true,
        'x' => true,
    ];

    private InputSanitizer $input;
    private ?UserRead $userRepo;

    /**
     * Prepares the user data parser for contact normalization and optional user reads.
     *
     * @param InputSanitizer      $input    Shared input sanitizer for contact/profile value normalization.
     * @param UserRead|null $userRepo Optional user repository used for read-only user/profile lookups.
     */
    public function __construct(InputSanitizer $input, ?UserRead $userRepo = null)
    {
        $this->input = $input;
        $this->userRepo = $userRepo;
    }

    /**
     * Returns all users for read-only listing flows.
     *
     * @return array<string, array{label: string, prefix: string}> User rows.
     */
    public function listAll(): array
    {
        return $this->userRepo()->listAll();
    }

    /**
     * Returns user and group rows needed to build the public profile routing table.
     *
     * @param bool $includeGroups Whether to include group rows.
     * @param bool $includeUsers  Whether to include user rows.
     * @return array{group_rows: array<int, array<string, mixed>>, user_rows: array<int, array<string, mixed>>} Routing data.
     */
    public function listRoutingData(bool $includeGroups, bool $includeUsers): array
    {
        return $this->userRepo()->listRoutingData($includeGroups, $includeUsers);
    }

    /**
     * Returns one paginated user page plus total count and group options.
     *
     * @param int         $limit            Maximum number of rows to return.
     * @param int         $offset           Zero-based row offset for pagination.
     * @param string|null $groupNameFilter  Optional group name substring filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int, group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        $normalizedFilter = is_string($groupNameFilter) ? strtolower(trim($groupNameFilter)) : '';
        return $this->userRepo()->listPage(
            max(1, $limit),
            max(0, $offset),
            $normalizedFilter !== '' ? $normalizedFilter : null
        );
    }

    /**
     * Returns one user row by numeric id.
     *
     * @param int $id User id to resolve.
     * @return array<string, mixed>|null User row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->userRepo()->findById($id);
    }

    /**
     * Returns one public user profile row by username.
     *
     * @param string $username Username to resolve.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileByUsername(string $username): ?array
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return null;
        }

        return $this->userRepo()->findProfileSummaryByUsername($normalizedUsername);
    }

    /**
     * Returns one public user profile row by numeric user id.
     *
     * @param int $userId User id to resolve.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileById(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        return $this->userRepo()->findProfileSummaryById($userId);
    }

    /**
     * Returns one public user profile row by an alphanumeric string selector.
     *
     * @param string $userString Alphanumeric selector string.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileByString(string $userString): ?array
    {
        $normalizedString = trim($userString);
        if ($normalizedString === '' || preg_match('/^[a-zA-Z0-9]+$/', $normalizedString) !== 1) {
            return null;
        }

        return $this->userRepo()->findProfileSummaryByString($normalizedString);
    }

    /**
     * Returns all public profile rows belonging to one group.
     *
     * @param int $groupId Group id to query.
     * @return array<int, array<string, mixed>> Profile rows.
     */
    public function listPublicProfilesByGroupId(int $groupId): array
    {
        if ($groupId < 1) {
            return [];
        }

        return $this->userRepo()->listProfileSummariesByGroupId($groupId);
    }

    /**
     * Returns the built-in contact option definitions.
     *
     * @return array<string, array{label: string, prefix: string}> Keyed by option slug.
     */
    public function defaultOptions(): array
    {
        return [
            'email' => ['label' => 'Email', 'prefix' => 'mailto:'],
            'phone' => ['label' => 'Phone', 'prefix' => 'tel:'],
            'homepage' => ['label' => 'Homepage', 'prefix' => 'https://'],
            'x' => ['label' => 'X', 'prefix' => 'https://x.com/'],
        ];
    }

    /**
     * Normalizes a raw contact type slug, mapping legacy aliases to canonical slugs.
     *
     * @param string $type Raw contact type string.
     * @return string      Normalized slug, or '' when invalid.
     */
    public function normalizeTypeSlug(string $type): string
    {
        $normalized = $this->input->slug($type);
        if ($normalized === null || $normalized === '') {
            return '';
        }

        if ($normalized === 'website') {
            return 'homepage';
        }

        return $normalized;
    }

    /**
     * Returns only the contact options that are always required (email, phone, homepage, x).
     *
     * @return array<string, array{label: string, prefix: string}> Required option definitions.
     */
    public function requiredOptions(): array
    {
        return array_intersect_key($this->defaultOptions(), self::REQUIRED_OPTION_KEYS);
    }

    /**
     * Normalizes a raw contact options config array against the built-in defaults.
     *
     * @param mixed $raw Raw value from site config.
     * @return array<string, array{label: string, prefix: string}> Normalized option definitions.
     */
    public function normalizeOptionsConfig(mixed $raw): array
    {
        $defaults = $this->defaultOptions();
        $source = is_array($raw) ? $raw : $defaults;
        $requiredDefaults = array_intersect_key($defaults, self::REQUIRED_OPTION_KEYS);
        $normalized = [];
        $priorities = [];
        foreach ($source as $key => $definition) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $rawSlug = $this->input->slug((string) $key);
            if ($rawSlug === null || $rawSlug === '') {
                continue;
            }

            $slug = $this->normalizeTypeSlug($rawSlug);
            if ($slug === '') {
                continue;
            }

            $defaultLabel = (string) ($defaults[$slug]['label'] ?? ucwords(str_replace('-', ' ', $slug)));
            $defaultPrefix = (string) ($defaults[$slug]['prefix'] ?? '');

            $safeLabel = $defaultLabel;
            $safePrefix = $defaultPrefix;
            if (is_array($definition)) {
                $safeLabel = $this->input->text((string) ($definition['label'] ?? $defaultLabel), 80);
                $rawPrefix = $definition['prefix'] ?? $defaultPrefix;
                $safePrefix = $this->input->text((string) $rawPrefix, 255);
            } else {
                $safeLabel = $this->input->text((string) $definition, 80);
            }

            if ($safeLabel === '') {
                continue;
            }
            $safePrefix = trim($safePrefix);

            $priority = $rawSlug === $slug ? 1 : 0;
            $existingPriority = $priorities[$slug] ?? -1;
            if ($priority < $existingPriority) {
                continue;
            }

            $normalized[$slug] = [
                'label' => $safeLabel,
                'prefix' => $safePrefix,
            ];
            $priorities[$slug] = $priority;
        }

        foreach ($requiredDefaults as $requiredSlug => $requiredConfig) {
            if (isset($normalized[$requiredSlug])) {
                continue;
            }

            $normalized[$requiredSlug] = [
                'label' => (string) ($requiredConfig['label'] ?? ucwords(str_replace('-', ' ', $requiredSlug))),
                'prefix' => trim((string) ($requiredConfig['prefix'] ?? '')),
            ];
        }

        if ($normalized === []) {
            return $requiredDefaults;
        }

        return $normalized;
    }

    /**
     * Normalizes a submitted contact options array from a panel form.
     *
     * @param mixed $rawOptions Raw submitted value.
     * @return array<string, array{label: string, prefix: string}> Normalized option definitions.
     */
    public function normalizeSubmittedOptions(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }

        $normalized = [];
        foreach ($rawOptions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->normalizeTypeSlug((string) ($entry['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $label = $this->input->text((string) ($entry['label'] ?? ''), 80);
            if ($label === '') {
                continue;
            }

            $rawPrefix = $entry['prefix'] ?? '';
            $urlPrefix = trim($this->input->text((string) $rawPrefix, 255));
            if (isset($normalized[$type])) {
                continue;
            }

            $normalized[$type] = [
                'label' => $label,
                'prefix' => $urlPrefix,
            ];

            if (count($normalized) >= 100) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Normalizes a submitted profile contact rows array from a panel form.
     *
     * @param mixed                                                   $rawProfiles    Raw submitted value.
     * @param array<string, array{label: string, prefix: string}>     $allowedOptions Allowed option definitions used to validate contact types.
     * @return array<int, array{type: string, value: string}>                         Normalized contact rows.
     */
    public function normalizeSubmittedProfiles(mixed $rawProfiles, array $allowedOptions): array
    {
        if (!is_array($rawProfiles) || $allowedOptions === []) {
            return [];
        }

        $normalized = [];
        foreach ($rawProfiles as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = $this->normalizeTypeSlug((string) ($row['type'] ?? ''));
            if ($type === '' || !array_key_exists($type, $allowedOptions)) {
                continue;
            }

            $value = $this->input->text((string) ($row['value'] ?? ''), 255);
            if ($value === '') {
                continue;
            }

            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type' => $type,
                'value' => $value,
            ];

            if (count($normalized) >= 20) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * Decorates a profile row with normalized, href-resolved contact entries.
     *
     * @param array<string, mixed>                                $profile Profile row to decorate.
     * @param array<string, array{label: string, prefix: string}> $options Contact option definitions used to build hrefs.
     * @return array<string, mixed>                                         Profile row with a normalized 'contact' key.
     */
    public function decorateProfileContacts(array $profile, array $options): array
    {
        $rawEntries = is_array($profile['contact'] ?? null) ? $profile['contact'] : [];
        $entries = [];

        foreach ($rawEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->input->slug((string) ($entry['type'] ?? ''));
            if ($type === null || $type === '') {
                continue;
            }

            $value = $this->input->text((string) ($entry['value'] ?? ''), 255);
            if ($value === '') {
                continue;
            }

            $option = $options[$type] ?? [
                'label' => ucwords(str_replace('-', ' ', $type)),
                'prefix' => '',
            ];
            $label = (string) ($option['label'] ?? $type);
            $urlPrefix = trim((string) ($option['prefix'] ?? ''));
            $href = $this->resolveProfileContactHref($value, $urlPrefix);

            $entries[] = [
                'type' => $type,
                'label' => $label,
                'value' => $value,
                'href' => $href,
            ];

            if (count($entries) >= 20) {
                break;
            }
        }

        $profile['contact'] = $entries;
        return $profile;
    }

    /**
     * Resolves a contact field value to an absolute href using the option's URL prefix.
     *
     * Returns null when the value cannot be mapped to an allowlisted absolute URL.
     *
     * @param string $value     Raw contact field value (e.g. a username, email, or URL).
     * @param string $urlPrefix Option URL prefix (e.g. 'https://x.com/').
     * @return string|null      Resolved absolute href, or null when not resolvable.
     */
    public function resolveProfileContactHref(string $value, string $urlPrefix): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1) {
            return $this->allowlistedAbsoluteHref($value);
        }

        if ($urlPrefix === '') {
            if (preg_match('#^https?://#i', $value) === 1) {
                return $this->allowlistedAbsoluteHref($value);
            }

            return null;
        }

        if (str_ends_with($urlPrefix, '/')) {
            return $this->allowlistedAbsoluteHref($urlPrefix . ltrim($value, '@/'));
        }

        return $this->allowlistedAbsoluteHref($urlPrefix . $value);
    }

    /**
     * Extracts an X/Twitter @handle from a profile contact entry for use in meta tags.
     *
     * @param array<int, array<string, mixed>>                    $profiles       Profile contact rows.
     * @param array<string, array{label: string, prefix: string}> $contactOptions Contact option definitions.
     * @return string                                                              '@handle' string, or '' when none found.
     */
    public function twitterCreatorFromProfiles(array $profiles, array $contactOptions): string
    {
        if ($profiles === []) {
            return '';
        }

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $type = $this->input->slug((string) ($profile['type'] ?? ''));
            $value = trim((string) ($profile['value'] ?? ''));
            if ($type === null || $type === '' || $value === '') {
                continue;
            }

            $urlPrefix = trim((string) ($contactOptions[$type]['prefix'] ?? ''));
            if (!$this->isTwitterProfileContactType($type, $urlPrefix)) {
                continue;
            }

            $creator = $this->normalizeTwitterCreatorHandle($value);
            if ($creator !== '') {
                return $creator;
            }
        }

        return '';
    }

    /**
     * Returns whether a contact type slug or URL prefix belongs to X/Twitter.
     *
     * @param string $type      Normalized contact type slug.
     * @param string $urlPrefix Contact option URL prefix.
     * @return bool             True when the contact type maps to X/Twitter.
     */
    private function isTwitterProfileContactType(string $type, string $urlPrefix): bool
    {
        if (in_array($type, ['x', 'twitter'], true)) {
            return true;
        }

        $prefix = strtolower($urlPrefix);
        return str_contains($prefix, 'x.com') || str_contains($prefix, 'twitter.com');
    }

    /**
     * Normalizes a raw X/Twitter profile value to a @handle string.
     *
     * Accepts full profile URLs (x.com or twitter.com) or bare handles.
     *
     * @param string $value Raw value from a contact row.
     * @return string       '@handle' string, or '' when normalization fails.
     */
    private function normalizeTwitterCreatorHandle(string $value): string
    {
        $raw = trim(str_replace(["\r", "\n", "\0"], '', $value));
        if ($raw === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if (!in_array($host, ['x.com', 'twitter.com'], true)) {
                return '';
            }

            $raw = (string) parse_url($raw, PHP_URL_PATH);
        }

        $raw = trim(preg_replace('/[?#].*$/', '', $raw) ?? '');
        $raw = ltrim($raw, '@/');
        if (str_contains($raw, '/')) {
            $raw = (string) explode('/', $raw, 2)[0];
        }

        if ($raw === '' || preg_match('/^[A-Za-z0-9_]{1,30}$/', $raw) !== 1) {
            return '';
        }

        return '@' . $raw;
    }

    /**
     * Validates an absolute href against the allowlisted scheme list and strips control characters.
     *
     * @param string $href Raw absolute href candidate.
     * @return string|null Cleaned href when the scheme is allowlisted, or null when not.
     */
    private function allowlistedAbsoluteHref(string $href): ?string
    {
        $candidate = trim(str_replace(["\r", "\n", "\0"], '', $href));
        if ($candidate === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto', 'tel', 'finger', 'fingers', 'gopher', 'gemini'], true)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Returns the injected user repository for repo-backed reads.
     *
     * @return UserRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function userRepo(): UserRead
    {
        if (!$this->userRepo instanceof UserRead) {
            throw new RuntimeException('UserDataParser requires a UserRead for repository-backed reads.');
        }

        return $this->userRepo;
    }
}
