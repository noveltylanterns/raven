<?php

declare(strict_types=1);

namespace Raven\Lib\Profile;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared profile-contact option normalization and social metadata helpers.
 */
final class ProfileContactService
{
    private const REQUIRED_OPTION_KEYS = [
        'email' => true,
        'phone' => true,
        'homepage' => true,
        'x' => true,
    ];

    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @return array<string, array{label: string, url_prefix: string}>
     */
    public function defaultOptions(): array
    {
        return [
            'email' => ['label' => 'Email', 'url_prefix' => 'mailto:'],
            'phone' => ['label' => 'Phone', 'url_prefix' => 'tel:'],
            'homepage' => ['label' => 'Homepage', 'url_prefix' => 'https://'],
            'x' => ['label' => 'X', 'url_prefix' => 'https://x.com/'],
        ];
    }

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
     * @return array<string, array{label: string, url_prefix: string}>
     */
    public function requiredOptions(): array
    {
        return array_intersect_key($this->defaultOptions(), self::REQUIRED_OPTION_KEYS);
    }

    /**
     * @return array<string, array{label: string, url_prefix: string}>
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
            $defaultPrefix = (string) ($defaults[$slug]['url_prefix'] ?? '');

            $safeLabel = $defaultLabel;
            $safePrefix = $defaultPrefix;
            if (is_array($definition)) {
                $safeLabel = $this->input->text((string) ($definition['label'] ?? $defaultLabel), 80);
                $safePrefix = $this->input->text((string) ($definition['url_prefix'] ?? $defaultPrefix), 255);
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
                'url_prefix' => $safePrefix,
            ];
            $priorities[$slug] = $priority;
        }

        foreach ($requiredDefaults as $requiredSlug => $requiredConfig) {
            if (isset($normalized[$requiredSlug])) {
                continue;
            }

            $normalized[$requiredSlug] = [
                'label' => (string) ($requiredConfig['label'] ?? ucwords(str_replace('-', ' ', $requiredSlug))),
                'url_prefix' => trim((string) ($requiredConfig['url_prefix'] ?? '')),
            ];
        }

        if ($normalized === []) {
            return $requiredDefaults;
        }

        return $normalized;
    }

    /**
     * @return array<string, array{label: string, url_prefix: string}>
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

            $urlPrefix = trim($this->input->text((string) ($entry['url_prefix'] ?? ''), 255));
            if (isset($normalized[$type])) {
                continue;
            }

            $normalized[$type] = [
                'label' => $label,
                'url_prefix' => $urlPrefix,
            ];

            if (count($normalized) >= 100) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, array{label: string, url_prefix: string}> $allowedOptions
     * @return array<int, array{type: string, value: string}>
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
     * @param array<string, mixed> $profile
     * @param array<string, array{label: string, url_prefix: string}> $options
     * @return array<string, mixed>
     */
    public function decorateProfileContacts(array $profile, array $options): array
    {
        $rawEntries = is_array($profile['contact_profiles'] ?? null) ? $profile['contact_profiles'] : [];
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
                'url_prefix' => '',
            ];
            $label = (string) ($option['label'] ?? $type);
            $urlPrefix = trim((string) ($option['url_prefix'] ?? ''));
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

        $profile['contact_profiles'] = $entries;
        return $profile;
    }

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
     * @param array<int, array<string, mixed>> $profiles
     * @param array<string, array{label: string, url_prefix: string}> $contactOptions
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

            $urlPrefix = trim((string) ($contactOptions[$type]['url_prefix'] ?? ''));
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

    private function isTwitterProfileContactType(string $type, string $urlPrefix): bool
    {
        if (in_array($type, ['x', 'twitter'], true)) {
            return true;
        }

        $prefix = strtolower($urlPrefix);
        return str_contains($prefix, 'x.com') || str_contains($prefix, 'twitter.com');
    }

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
