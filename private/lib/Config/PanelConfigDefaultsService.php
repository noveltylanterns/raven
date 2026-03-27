<?php

declare(strict_types=1);

namespace Raven\Lib\Config;

/**
 * Shared configuration-editor defaults + scalar coercion bundle.
 */
final class PanelConfigDefaultsService
{
    private ConfigEditorSchemaService $schema;
    private ConfigEditorNormalizer $normalizer;

    public function __construct(ConfigEditorSchemaService $schema, ConfigEditorNormalizer $normalizer)
    {
        $this->schema = $schema;
        $this->normalizer = $normalizer;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $publicThemeOptions
     * @param callable(string, bool): ?string $normalizePanelThemeChoice
     * @return array<string, mixed>
     */
    public function apply(
        array $config,
        array $publicThemeOptions,
        callable $normalizePanelThemeChoice
    ): array {
        $config = $this->schema->ensureContentEditorConfig($config);
        $config = $this->schema->ensureDatabaseConfig($config);
        $config = $this->schema->ensureTaxonomyRoutePrefixConfig($config);
        $config = $this->schema->ensurePublicProfileConfig($config);
        $config = $this->schema->ensureUserAuthConfig($config);
        $config = $this->schema->ensureSiteEnabledConfig($config, $publicThemeOptions);
        $config = $this->schema->ensurePanelBrandingConfig($config, $normalizePanelThemeChoice);
        $config = $this->schema->ensureCaptchaConfig($config);
        $config = $this->schema->ensureMailConfig($config);
        $config = $this->schema->ensureDebugToolbarConfig($config);

        return $config;
    }

    public function normalizeInt(string $path, string $value): int
    {
        return $this->normalizer->normalizeInt($path, $value);
    }

    public function normalizeFloat(string $path, string $value): float
    {
        return $this->normalizer->normalizeFloat($path, $value);
    }

    public function normalizeBool(string $path, string $value): bool
    {
        return $this->normalizer->normalizeBool($path, $value);
    }

    public function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        return $this->normalizer->normalizeImageConfigValue($path, $value);
    }
}
