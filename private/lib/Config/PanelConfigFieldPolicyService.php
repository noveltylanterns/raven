<?php

declare(strict_types=1);

namespace Raven\Lib\Config;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared panel config-editor field normalization and validation policy.
 */
final class PanelConfigFieldPolicyService
{
    private Config $config;
    private InputSanitizer $input;
    private PanelConfigDefaultsService $defaults;
    private ConfigEditorNormalizer $normalizer;

    public function __construct(
        Config $config,
        InputSanitizer $input,
        PanelConfigDefaultsService $defaults,
        ConfigEditorNormalizer $normalizer
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->defaults = $defaults;
        $this->normalizer = $normalizer;
    }

    /**
     * Casts and validates one submitted config field value by expected type.
     *
     * @param array<string, mixed> $workingConfig
     * @param callable(string): string $normalizeBodyTextEditorOption
     * @param callable(string): string $normalizeGlobalRouteSeparator
     * @param callable(string, bool): ?string $normalizePanelThemeChoice
     * @param array<string, string> $publicThemeOptions
     * @param array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}> $feedChannelOptions
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $categorySetOptions
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $tagSetOptions
     */
    public function normalizeFieldValue(
        string $path,
        string $type,
        string $rawValue,
        array $workingConfig,
        callable $normalizeBodyTextEditorOption,
        callable $normalizeGlobalRouteSeparator,
        callable $normalizePanelThemeChoice,
        array $publicThemeOptions,
        array $feedChannelOptions,
        array $categorySetOptions,
        array $tagSetOptions
    ): mixed {
        $value = $this->input->text($rawValue, 1000);

        if ($path === 'panel.path') {
            $slug = $this->input->slug($value);
            if ($slug === null) {
                throw new \RuntimeException('panel.path must be a valid slug.');
            }

            return $slug;
        }

        if ($path === 'site.domain') {
            if ($value === '') {
                throw new \RuntimeException('site.domain is required.');
            }

            return $value;
        }

        if ($path === 'site.enabled') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                throw new \RuntimeException('site.enabled must be public, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'site.protocol') {
            $protocol = strtolower(trim($value));
            if (!in_array($protocol, ['http', 'https'], true)) {
                throw new \RuntimeException('site.protocol must be http or https.');
            }

            return $protocol;
        }

        if ($path === 'database.driver') {
            $driver = strtolower($value);
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new \RuntimeException('database.driver must be sqlite, mysql, or pgsql.');
            }

            return $driver;
        }

        if ($path === 'feed.items') {
            $items = $this->defaults->normalizeInt($path, $value);
            if ($items < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $items;
        }

        if ($path === 'user.bio') {
            $length = $this->defaults->normalizeInt($path, $value);
            if ($length < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $length;
        }

        if ($path === 'category.set' || $path === 'tag.set') {
            $setId = $this->defaults->normalizeInt($path, $value);
            if ($setId < 1) {
                throw new \RuntimeException($path . ' must be a valid set id.');
            }

            $options = $path === 'tag.set' ? $tagSetOptions : $categorySetOptions;
            foreach ($options as $option) {
                if ((int) ($option['id'] ?? 0) === $setId) {
                    return $setId;
                }
            }

            throw new \RuntimeException($path . ' must reference an existing set.');
        }

        if ($path === 'category.enabled' || $path === 'tag.enabled') {
            return $this->defaults->normalizeBool($path, $value);
        }

        if ($path === 'category.prefix' || $path === 'tag.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $isCategoryPath = $path === 'category.prefix';
            $thisEnabled = $isCategoryPath
                ? ConfigValueParser::bool(
                    $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', false),
                    false
                )
                : ConfigValueParser::bool(
                    $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', false),
                    false
                );
            if (!$thisEnabled) {
                return $prefix;
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $otherPath = $path === 'category.prefix' ? 'tag.prefix' : 'category.prefix';
            $otherDefault = $path === 'category.prefix' ? 'tag' : 'cat';
            $otherEnabled = $isCategoryPath
                ? ConfigValueParser::bool(
                    $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', false),
                    false
                )
                : ConfigValueParser::bool(
                    $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', false),
                    false
                );
            $otherRaw = $otherPath === 'category.prefix'
                ? (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', $otherDefault))
                : (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            if ($otherEnabled && $otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('category.prefix and tag.prefix must be different values.');
            }

            return $prefix;
        }

        if ($path === 'feed.rss' || $path === 'feed.atom') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $feedEnabled = ConfigValueParser::bool(
                $workingConfig['feed']['enabled'] ?? $this->config->get('feed.enabled', false),
                false
            );
            if (!$feedEnabled) {
                return $prefix;
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigValueParser::bool(
                $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', false),
                false
            );
            if ($categoryEnabled && $categoryPrefix !== null && $categoryPrefix !== '' && $prefix === $categoryPrefix) {
                throw new \RuntimeException($path . ' cannot match category.prefix while categories are enabled.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigValueParser::bool(
                $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', false),
                false
            );
            if ($tagEnabled && $tagPrefix !== null && $tagPrefix !== '' && $prefix === $tagPrefix) {
                throw new \RuntimeException($path . ' cannot match tag.prefix while tags are enabled.');
            }

            $userPrefix = $this->input->slug(
                (string) ($workingConfig['user']['prefix'] ?? $this->config->get('user.prefix', 'user'))
            );
            if ($userPrefix !== null && $userPrefix !== '' && $prefix === $userPrefix) {
                throw new \RuntimeException($path . ' cannot match user.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ($workingConfig['group']['prefix'] ?? $this->config->get('group.prefix', 'group'))
            );
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException($path . ' cannot match group.prefix.');
            }

            $otherPath = $path === 'feed.rss' ? 'feed.atom' : 'feed.rss';
            $otherDefault = $path === 'feed.rss' ? 'atom' : 'rss';
            $otherRaw = $otherPath === 'feed.rss'
                ? (string) ($workingConfig['feed']['rss'] ?? $this->config->get('feed.rss', $otherDefault))
                : (string) ($workingConfig['feed']['atom'] ?? $this->config->get('feed.atom', $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            if ($otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('feed.rss and feed.atom must be different values.');
            }

            return $prefix;
        }

        if ($path === 'user.privacy') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException('user.privacy must be public_full, public_limited, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'user.auth.login') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['email', 'username'], true)) {
                throw new \RuntimeException('user.auth.login must be email or username.');
            }

            return $mode;
        }

        if ($path === 'user.auth.registration') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['open', 'invite', 'closed'], true)) {
                throw new \RuntimeException('user.auth.registration must be open, invite, or closed.');
            }

            return $mode;
        }

        if ($path === 'user.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('user.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('user.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigValueParser::bool(
                $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', false),
                false
            );
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('user.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigValueParser::bool(
                $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', false),
                false
            );
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('user.prefix cannot match tag.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ($workingConfig['group']['prefix'] ?? $this->config->get('group.prefix', 'group'))
            );
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException('user.prefix cannot match group.prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('user.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'group.privacy') {
            $mode = strtolower(trim($value));
            if ($mode === 'public') {
                $mode = 'public_full';
            }
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException(
                    'group.privacy must be public_full, public_limited, private, or disabled.'
                );
            }

            return $mode;
        }

        if ($path === 'group.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('group.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('group.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['category']['prefix'] ?? $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigValueParser::bool(
                $workingConfig['category']['enabled'] ?? $this->config->get('category.enabled', false),
                false
            );
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('group.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tag']['prefix'] ?? $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigValueParser::bool(
                $workingConfig['tag']['enabled'] ?? $this->config->get('tag.enabled', false),
                false
            );
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('group.prefix cannot match tag.prefix.');
            }

            $profilePrefix = $this->input->slug(
                (string) ($workingConfig['user']['prefix'] ?? $this->config->get('user.prefix', 'user'))
            );
            if ($profilePrefix !== null && $profilePrefix !== '' && $prefix === $profilePrefix) {
                throw new \RuntimeException('group.prefix cannot match user.prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('group.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'captcha.provider') {
            $provider = strtolower($value);
            if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
                throw new \RuntimeException('captcha.provider must be none, hcaptcha, recaptcha2, or recaptcha3.');
            }

            return $provider;
        }

        if ($path === 'mail.agent') {
            $agent = strtolower($value);
            if (!in_array($agent, ['php_mail'], true)) {
                throw new \RuntimeException('mail.agent must be php_mail.');
            }

            return $agent;
        }

        if ($path === 'content.editor_default') {
            $editor = $normalizeBodyTextEditorOption($value);
            if ($editor === 'tinymce' && strtolower(trim($value)) !== 'tinymce') {
                throw new \RuntimeException(
                    'content.editor_default must be tinymce, plaintext, autobr, or markdown.'
                );
            }

            return $editor;
        }

        if ($path === 'content.route_mode') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['slug', 'id'], true)) {
                throw new \RuntimeException('content.route_mode must be slug or id.');
            }

            return $mode;
        }

        if ($path === 'content.route_separator') {
            $separator = $normalizeGlobalRouteSeparator($value);
            if ($separator === '-' && trim($value) !== '-') {
                throw new \RuntimeException(
                    'content.route_separator must be - or _.'
                );
            }

            return $separator;
        }

        if ($path === 'mail.sender_address') {
            $address = trim($value);
            if ($address === '') {
                return '';
            }

            $normalized = $this->input->email($address);
            if ($normalized === null) {
                throw new \RuntimeException('mail.sender_address must be a valid email address or blank.');
            }

            return $normalized;
        }

        if ($path === 'mail.sender_name') {
            return $this->input->text($value, 120);
        }

        if (in_array($path, ['meta.image', 'meta.apple_touch_icon', 'panel.brand_logo'], true)) {
            $siteProtocol = (string) ($workingConfig['site']['protocol'] ?? $this->config->get('site.protocol', 'https'));
            $siteDomain = (string) ($workingConfig['site']['domain'] ?? $this->config->get('site.domain', ''));
            return $this->normalizer->normalizeMetaAbsoluteUrlPathValue($siteProtocol, $siteDomain, $value);
        }

        if ($path === 'panel.default_theme') {
            $theme = $normalizePanelThemeChoice($value, false);
            if (!is_string($theme)) {
                throw new \RuntimeException('panel.default_theme must be corp, ice, or midnight.');
            }

            return $theme;
        }

        if ($path === 'site.default_theme') {
            $theme = strtolower($value);
            if (!isset($publicThemeOptions[$theme])) {
                throw new \RuntimeException('site.default_theme must match one installed theme manifest.');
            }

            return $theme;
        }

        if ($path === 'session.cookie.name') {
            $sessionName = trim($value);
            if ($sessionName === '') {
                throw new \RuntimeException('session.cookie.name is required.');
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
                throw new \RuntimeException('session.cookie.name may contain only letters, numbers, underscores, and hyphens (max 64 chars).');
            }

            return $sessionName;
        }

        if ($path === 'session.cookie.domain') {
            $cookieDomain = strtolower(trim($value));
            if ($cookieDomain === '') {
                return '';
            }

            if (preg_match('/[:\/\s]/', $cookieDomain) === 1) {
                throw new \RuntimeException('session.cookie.domain must be a bare domain (no protocol, path, port, or spaces).');
            }

            if (!preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain)) {
                throw new \RuntimeException('session.cookie.domain must be a valid domain value.');
            }

            return $cookieDomain;
        }

        if ($path === 'session.cookie.prefix') {
            $cookiePrefix = trim($value);
            if ($cookiePrefix === '') {
                return '';
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix)) {
                throw new \RuntimeException('session.cookie.prefix may contain only letters, numbers, underscores, and hyphens (max 40 chars).');
            }

            return $cookiePrefix;
        }

        if ($path === 'session.brute.max') {
            $maxAttempts = $this->defaults->normalizeInt($path, $value);
            if ($maxAttempts < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $maxAttempts;
        }

        if ($path === 'session.brute.window' || $path === 'session.brute.lock') {
            $seconds = $this->defaults->normalizeInt($path, $value);
            if ($seconds < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $seconds;
        }

        if (str_starts_with($path, 'debug.')) {
            return $this->defaults->normalizeBool($path, $value);
        }

        if ($path === 'media.avatars.max_filesize_kb') {
            $size = $this->defaults->normalizeInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if (str_starts_with($path, 'media.images.')) {
            return $this->defaults->normalizeImageConfigValue($path, $value);
        }

        return match ($type) {
            'int' => $this->defaults->normalizeInt($path, $value),
            'float' => $this->defaults->normalizeFloat($path, $value),
            'bool' => $this->defaults->normalizeBool($path, $value),
            'null' => $value === '' ? null : $value,
            default => $value,
        };
    }

    /**
     * @param mixed $rawValue
     * @param array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}> $feedChannelOptions
     * @return array<int, string>
     */
    public function normalizeFeedChannelsValue(mixed $rawValue, array $feedChannelOptions): array
    {
        $submitted = is_array($rawValue) ? $rawValue : [];
        $allowed = [];
        foreach ($feedChannelOptions as $channelOption) {
            $optionSlug = $this->input->slug((string) ($channelOption['slug'] ?? ''));
            if ($optionSlug === null || $optionSlug === '') {
                continue;
            }

            $allowed[$optionSlug] = true;
        }

        $normalized = [];
        foreach ($submitted as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value === '') {
                continue;
            }

            if ($value === 'all') {
                return ['all'];
            }

            $channelSlug = $this->input->slug($value);
            if ($channelSlug === null || $channelSlug === '' || !isset($allowed[$channelSlug])) {
                continue;
            }

            $normalized[$channelSlug] = $channelSlug;
        }

        if ($normalized === []) {
            return [];
        }

        if ($allowed !== [] && count($normalized) === count($allowed)) {
            return ['all'];
        }

        return array_values($normalized);
    }
}
