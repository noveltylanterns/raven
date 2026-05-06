<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/UserProfileParser.php
 * Profile-contact option normalization, href resolution, and social metadata helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes profile contact options, decorates profile rows with resolved contact hrefs,
 * and extracts social metadata such as X/Twitter creator handles for page meta tags.
 *
 * No database access. Used wherever profile-contact config must be read, submitted options
 * must be validated, or profile rows must be decorated with href-resolved contact links.
 */
final class UserProfileParser
{
    /** @var array<string, true> Required contact type keys that must always appear in option sets. */
    private const REQUIRED_OPTION_KEYS = [
        'email' => true,
        'phone' => true,
        'homepage' => true,
        'x' => true,
    ];

    private InputSanitizer $input;

    /**
     * Prepares the profile-contact parser for option normalization and profile decoration.
     *
     * @param InputSanitizer $input Shared input sanitizer for contact value and slug normalization.
     * @return void
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Returns the built-in contact option definitions.
     *
     * @return array<string, array{label: string, prefix: string}> Keyed by option slug.
     */
    public function defaultOptions(): array
    {
        return [
            'email'    => ['label' => 'Email',    'prefix' => 'mailto:'],
            'phone'    => ['label' => 'Phone',    'prefix' => 'tel:'],
            'homepage' => ['label' => 'Homepage', 'prefix' => 'https://'],
            'x'        => ['label' => 'X',        'prefix' => 'https://x.com/'],
        ];
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

        // Map the old 'website' alias to the canonical 'homepage' slug.
        if ($normalized === 'website') {
            return 'homepage';
        }

        return $normalized;
    }

    /**
     * Normalizes a raw contact options config array against the built-in defaults.
     *
     * Merges the stored config with required defaults so the four built-in option types
     * always appear even when the stored config omits or partially defines them.
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

            $defaultLabel  = (string) ($defaults[$slug]['label']  ?? ucwords(str_replace('-', ' ', $slug)));
            $defaultPrefix = (string) ($defaults[$slug]['prefix'] ?? '');

            $safeLabel  = $defaultLabel;
            $safePrefix = $defaultPrefix;
            if (is_array($definition)) {
                $safeLabel  = $this->input->text((string) ($definition['label']  ?? $defaultLabel), 80);
                $rawPrefix  = $definition['prefix'] ?? $defaultPrefix;
                $safePrefix = $this->input->text((string) $rawPrefix, 255);
            } else {
                $safeLabel = $this->input->text((string) $definition, 80);
            }

            if ($safeLabel === '') {
                continue;
            }
            $safePrefix = trim($safePrefix);

            // Prefer the un-aliased slug when both appear (priority 1 > 0).
            $priority = $rawSlug === $slug ? 1 : 0;
            $existingPriority = $priorities[$slug] ?? -1;
            if ($priority < $existingPriority) {
                continue;
            }

            $normalized[$slug] = [
                'label'  => $safeLabel,
                'prefix' => $safePrefix,
            ];
            $priorities[$slug] = $priority;
        }

        // Ensure all required types are present even when the stored config omits them.
        foreach ($requiredDefaults as $requiredSlug => $requiredConfig) {
            if (isset($normalized[$requiredSlug])) {
                continue;
            }

            $normalized[$requiredSlug] = [
                'label'  => (string) ($requiredConfig['label']  ?? ucwords(str_replace('-', ' ', $requiredSlug))),
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
     * @param mixed $rawOptions Raw submitted value from the contact options panel form.
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
                'label'  => $label,
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
     * @param mixed                                                   $rawProfiles    Raw submitted value from the profile contact rows form.
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

            // Deduplicate by type+value so the same contact entry cannot appear twice.
            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type'  => $type,
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
        $entries    = [];

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
                'label'  => ucwords(str_replace('-', ' ', $type)),
                'prefix' => '',
            ];
            $label     = (string) ($option['label']  ?? $type);
            $urlPrefix = trim((string) ($option['prefix'] ?? ''));
            $href      = $this->resolveProfileContactHref($value, $urlPrefix);

            $entries[] = [
                'type'  => $type,
                'label' => $label,
                'value' => $value,
                'href'  => $href,
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

        // If the value already carries a scheme, validate and return it directly.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1) {
            return $this->allowlistedAbsoluteHref($value);
        }

        if ($urlPrefix === '') {
            // No prefix: only accept values that already look like https?:// URLs.
            if (preg_match('#^https?://#i', $value) === 1) {
                return $this->allowlistedAbsoluteHref($value);
            }

            return null;
        }

        // Trailing slash means value is appended directly (strip leading @ or / from bare handles).
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

            $type  = $this->input->slug((string) ($profile['type']  ?? ''));
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
}
