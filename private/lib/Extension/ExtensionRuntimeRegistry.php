<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/ExtensionRuntimeRegistry.php
 * Enabled-extension discovery plus lazy bootstrap/service/runtime helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

use Raven\Lib\Extension\ExtensionRegistry;

/**
 * Tracks enabled extension bootstrap providers and exposes lazy runtime hooks.
 */
final class ExtensionRuntimeRegistry
{
    private string $root;
    private ExtensionBootstrapContractResolver $bootstrapResolver;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $extensionManifests = [];

    /**
     * @var array<string, array{local: string, aux: array<string, string>, panel: string, public: string, bin: string}>
     */
    private array $extensionStorage = [];

    /**
     * @var array<string, callable(array<string, mixed>&): void>
     */
    private array $extensionBootProviders = [];

    /**
     * @var array<int, string>
     */
    private array $schedulerExtensions = [];

    /**
     * @var array<string, bool>
     */
    private array $bootedExtensionDirectories = [];

    /**
     * @var array<string, array{state: string, services: array<string, mixed>}>
     */
    private array $extensionServiceResolutionStates = [];

    /**
     * @param string                    $root                        Project root path.
     * @param array<int, string>        $enabledExtensionDirectories Enabled extension directory names.
     * @param ExtensionBootstrapContractResolver|null $bootstrapResolver Optional bootstrap contract resolver override.
     */
    public function __construct(
        string $root,
        array $enabledExtensionDirectories,
        ?ExtensionBootstrapContractResolver $bootstrapResolver = null
    ) {
        $this->root = rtrim($root, '/');
        $this->bootstrapResolver = $bootstrapResolver ?? new ExtensionBootstrapContractResolver();
        $this->discoverEnabledExtensions($enabledExtensionDirectories);
    }

    /**
     * Returns enabled extension manifests keyed by extension directory.
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifests(): array
    {
        return $this->extensionManifests;
    }

    /**
     * Returns provisioned extension storage roots keyed by extension directory.
     *
     * @return array<string, array{local: string, aux: array<string, string>, panel: string, public: string, bin: string}>
     */
    public function storageMap(): array
    {
        return $this->extensionStorage;
    }

    /**
     * Returns enabled extension directories that declared scheduler support.
     *
     * @return array<int, string>
     */
    public function schedulerDirectories(): array
    {
        return $this->schedulerExtensions;
    }

    /**
     * Runs one extension bootstrap provider exactly once per request lifecycle.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container, passed by reference so
     *                                  extension providers can publish services/aliases.
     * @return array<string, mixed> Mutated bootstrap container.
     */
    public function bootExtension(array &$rvn, string $directory): array
    {
        $directory = trim($directory);
        if ($directory === '' || isset($this->bootedExtensionDirectories[$directory])) {
            return $rvn;
        }

        $this->bootedExtensionDirectories[$directory] = true;
        $provider = $this->extensionBootProviders[$directory] ?? null;
        if (!is_callable($provider)) {
            return $rvn;
        }

        try {
            $provider($rvn);
        } catch (\Throwable $exception) {
            error_log('Raven extension bootstrap failed for extension "' . $directory . '": ' . $exception->getMessage());
        }

        return $rvn;
    }

    /**
     * Resolves one extension service map, materializing lazy service values on demand.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @return array<string, mixed> Materialized service map for the requested extension.
     */
    public function resolveExtensionServices(array &$rvn, string $directory): array
    {
        $directory = trim($directory);
        if ($directory === '') {
            return [];
        }

        $this->bootExtension($rvn, $directory);

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        if (!is_array($rawExtensionServices)) {
            return [];
        }

        if (!array_key_exists($directory, $rawExtensionServices) || !is_array($rawExtensionServices[$directory])) {
            return [];
        }

        $resolutionState = $this->extensionServiceResolutionStates[$directory] ?? null;
        if (is_array($resolutionState)) {
            $cachedServices = $resolutionState['services'] ?? [];
            if (in_array((string) ($resolutionState['state'] ?? ''), ['resolving', 'resolved'], true) && is_array($cachedServices)) {
                return $cachedServices;
            }
        }

        /** @var array<string, mixed> $services */
        $services = $rawExtensionServices[$directory];
        $this->extensionServiceResolutionStates[$directory] = [
            'state' => 'resolving',
            'services' => $services,
        ];

        try {
            foreach ($services as $serviceKey => &$serviceValue) {
                $this->materializeExtensionValue($serviceValue);
                $services[$serviceKey] = $serviceValue;
                $this->extensionServiceResolutionStates[$directory] = [
                    'state' => 'resolving',
                    'services' => $services,
                ];
            }
            unset($serviceValue);
        } catch (\Throwable $exception) {
            unset($this->extensionServiceResolutionStates[$directory]);
            throw $exception;
        }

        $rawExtensionServices[$directory] = $services;
        $rvn['extension_services'] = $rawExtensionServices;
        $this->extensionServiceResolutionStates[$directory] = [
            'state' => 'resolved',
            'services' => $services,
        ];

        return $services;
    }

    /**
     * Resolves all enabled extension service maps, booting only extensions that
     * actually expose runtime boot providers.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @return array<string, array<string, mixed>>
     */
    public function resolveAllExtensionServices(array &$rvn): array
    {
        $services = [];
        foreach (array_keys($this->extensionBootProviders) as $directory) {
            $services[$directory] = $this->resolveExtensionServices($rvn, $directory);
        }

        return $services;
    }

    /**
     * Returns the extension-owned overlay that route registrars should see in
     * their local `$rvn` context.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @param array<string, bool>  $coreContainerKeys Keys owned by core bootstrap itself.
     * @return array<string, mixed> Booted extension overlay plus current extension service map.
     */
    public function extensionContext(array &$rvn, string $directory, array $coreContainerKeys): array
    {
        $directory = trim($directory);
        if ($directory === '') {
            return [];
        }

        $this->resolveExtensionServices($rvn, $directory);

        $overlay = [];
        foreach ($rvn as $key => $value) {
            if ($key === 'extension_services') {
                if (is_array($value) && array_key_exists($directory, $value)) {
                    $overlay[$key] = $value;
                }
                continue;
            }

            if (isset($coreContainerKeys[$key])) {
                continue;
            }

            $overlay[$key] = $value;
        }

        return $overlay;
    }

    /**
     * Boots every enabled extension provider, preserving the legacy eager mode
     * for maintenance/debug paths that still want the full extension graph.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @return array<string, mixed> Mutated bootstrap container.
     */
    public function bootAllExtensions(array &$rvn): array
    {
        foreach (array_keys($this->extensionBootProviders) as $directory) {
            $this->bootExtension($rvn, $directory);
        }

        return $rvn;
    }

    /**
     * Discovers enabled extension manifests, storage requests, and boot providers.
     *
     * @param array<int, string> $enabledExtensionDirectories Enabled extension directory names.
     * @return void
     */
    private function discoverEnabledExtensions(array $enabledExtensionDirectories): void
    {
        foreach ($enabledExtensionDirectories as $directory) {
            $manifest = ExtensionRegistry::readManifest($this->root, $directory);
            if (!is_array($manifest)) {
                continue;
            }

            $this->extensionManifests[$directory] = $manifest;

            $bootstrap = $this->bootstrapResolver->resolve($this->root, $directory, $manifest);
            if (!$bootstrap['valid']) {
                error_log('Raven extension bootstrap is invalid for extension "' . $directory . '": ' . (string) ($bootstrap['error'] ?? 'Unknown error.'));
                continue;
            }

            if (!empty($bootstrap['scheduler'])) {
                $this->schedulerExtensions[] = $directory;
            }

            $storage = is_array($bootstrap['storage'] ?? null) ? (array) $bootstrap['storage'] : [];
            $this->extensionStorage[$directory] = [
                'local' => !empty($storage['local']) ? ($this->root . '/private/dat/ext/' . $directory) : '',
                'aux' => [],
                'panel' => !empty($storage['panel']) ? ($this->root . '/panel/ext/' . $directory) : '',
                'public' => !empty($storage['public']) ? ($this->root . '/public/uploads/ext/' . $directory) : '',
                // Bin storage resolves to the extension's own bin/ directory; private/bin/ contains the symlinks.
                'bin' => !empty($storage['bin']) ? ($this->root . '/private/ext/' . $directory . '/bin') : '',
            ];

            foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
                if (!is_string($auxDirectory) || $auxDirectory === '') {
                    continue;
                }

                $this->extensionStorage[$directory]['aux'][$auxDirectory] = $this->root . '/' . $auxDirectory;
            }

            $provider = $bootstrap['boot'] ?? null;
            if (is_callable($provider)) {
                $this->extensionBootProviders[$directory] = $provider;
            }
        }
    }

    /**
     * Resolves nested extension-service values and memoizes any closure output
     * back into the original array structure.
     *
     * @param mixed $value Extension service value or nested service payload.
     * @return mixed Materialized service value.
     */
    private function materializeExtensionValue(mixed &$value): mixed
    {
        if ($value instanceof \Closure) {
            $value = $value();
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => &$childValue) {
            $this->materializeExtensionValue($childValue);
            $value[$key] = $childValue;
        }
        unset($childValue);

        return $value;
    }
}
