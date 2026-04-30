<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/RouteDeps.php
 * Shared dependency payload for panel route-family registrars.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Closure;
use Raven\Lib\Security\InputSanitizer;

/**
 * Canonical panel route-registrar dependency payload.
 */
final class RouteDeps
{
    /** @var array<string, mixed> */
    public readonly array $rvn;
    public readonly Closure $authController;
    public readonly Closure $panelDashboardController;
    public readonly Closure $panelChannelListController;
    public readonly Closure $panelChannelEditController;
    public readonly Closure $panelCategoryListController;
    public readonly Closure $panelCategoryEditController;
    public readonly Closure $panelTagListController;
    public readonly Closure $panelTagEditController;
    public readonly Closure $panelRedirectListController;
    public readonly Closure $panelRedirectEditController;
    public readonly Closure $panelUserListController;
    public readonly Closure $panelUserEditController;
    public readonly Closure $panelGroupListController;
    public readonly Closure $panelGroupEditController;
    public readonly Closure $panelPageListController;
    public readonly Closure $panelPageEditController;
    public readonly Closure $panelPreferencesController;
    public readonly Closure $panelConfigController;
    public readonly Closure $panelLogsController;
    public readonly Closure $panelRoutingController;
    public readonly Closure $panelUpdateController;
    public readonly Closure $panelSystemController;
    public readonly InputSanitizer $input;
    public readonly bool $categoryEnabled;
    public readonly bool $tagEnabled;
    public readonly Closure $renderNotFound;
    /** @var array<string, mixed> */
    public readonly array $enabledExtensions;
    /** @var array<string, array<string, mixed>> */
    public readonly array $enabledExtensionManifests;
    /** @var array<string, array<string, mixed>> */
    public readonly array $extensionPermissionCatalog;
    public readonly string $internalPath;
    public readonly Closure $renderPublicNotFound;

    /**
     * @param array<string, mixed> $rvn Shared Raven runtime container.
     * @param callable(): object $authController Lazy auth controller factory.
     * @param callable(): object $panelDashboardController Lazy dashboard controller factory.
     * @param callable(): object $panelChannelListController Lazy channel-list controller factory.
     * @param callable(): object $panelChannelEditController Lazy channel-edit controller factory.
     * @param callable(): object $panelCategoryListController Lazy category-list controller factory.
     * @param callable(): object $panelCategoryEditController Lazy category-edit controller factory.
     * @param callable(): object $panelTagListController Lazy tag-list controller factory.
     * @param callable(): object $panelTagEditController Lazy tag-edit controller factory.
     * @param callable(): object $panelRedirectListController Lazy redirect-list controller factory.
     * @param callable(): object $panelRedirectEditController Lazy redirect-edit controller factory.
     * @param callable(): object $panelUserListController Lazy user-list controller factory.
     * @param callable(): object $panelUserEditController Lazy user-edit controller factory.
     * @param callable(): object $panelGroupListController Lazy group-list controller factory.
     * @param callable(): object $panelGroupEditController Lazy group-edit controller factory.
     * @param callable(): object $panelPageListController Lazy page-list controller factory.
     * @param callable(): object $panelPageEditController Lazy page-edit controller factory.
     * @param callable(): object $panelPreferencesController Lazy preferences controller factory.
     * @param callable(): object $panelConfigController Lazy config controller factory.
     * @param callable(): object $panelLogsController Lazy logs controller factory.
     * @param callable(): object $panelRoutingController Lazy routing controller factory.
     * @param callable(): object $panelUpdateController Lazy update controller factory.
     * @param callable(): object $panelSystemController Lazy system controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $categoryEnabled Whether category routes should be active.
     * @param bool $tagEnabled Whether tag routes should be active.
     * @param callable(): void $renderNotFound Renders panel not-found for invalid panel route params.
     * @param array<string, mixed> $enabledExtensions Enabled extension-state map.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Enabled extension manifests keyed by directory.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Extension permission metadata keyed by directory.
     * @param string $internalPath Normalized panel-internal request path.
     * @param callable(): void $renderPublicNotFound Renders stock public 404 for extension gate failures.
     */
    public function __construct(
        array $rvn,
        callable $authController,
        callable $panelDashboardController,
        callable $panelChannelListController,
        callable $panelChannelEditController,
        callable $panelCategoryListController,
        callable $panelCategoryEditController,
        callable $panelTagListController,
        callable $panelTagEditController,
        callable $panelRedirectListController,
        callable $panelRedirectEditController,
        callable $panelUserListController,
        callable $panelUserEditController,
        callable $panelGroupListController,
        callable $panelGroupEditController,
        callable $panelPageListController,
        callable $panelPageEditController,
        callable $panelPreferencesController,
        callable $panelConfigController,
        callable $panelLogsController,
        callable $panelRoutingController,
        callable $panelUpdateController,
        callable $panelSystemController,
        InputSanitizer $input,
        bool $categoryEnabled,
        bool $tagEnabled,
        callable $renderNotFound,
        array $enabledExtensions,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        string $internalPath,
        callable $renderPublicNotFound
    ) {
        $this->rvn = $rvn;
        $this->authController = Closure::fromCallable($authController);
        $this->panelDashboardController = Closure::fromCallable($panelDashboardController);
        $this->panelChannelListController = Closure::fromCallable($panelChannelListController);
        $this->panelChannelEditController = Closure::fromCallable($panelChannelEditController);
        $this->panelCategoryListController = Closure::fromCallable($panelCategoryListController);
        $this->panelCategoryEditController = Closure::fromCallable($panelCategoryEditController);
        $this->panelTagListController = Closure::fromCallable($panelTagListController);
        $this->panelTagEditController = Closure::fromCallable($panelTagEditController);
        $this->panelRedirectListController = Closure::fromCallable($panelRedirectListController);
        $this->panelRedirectEditController = Closure::fromCallable($panelRedirectEditController);
        $this->panelUserListController = Closure::fromCallable($panelUserListController);
        $this->panelUserEditController = Closure::fromCallable($panelUserEditController);
        $this->panelGroupListController = Closure::fromCallable($panelGroupListController);
        $this->panelGroupEditController = Closure::fromCallable($panelGroupEditController);
        $this->panelPageListController = Closure::fromCallable($panelPageListController);
        $this->panelPageEditController = Closure::fromCallable($panelPageEditController);
        $this->panelPreferencesController = Closure::fromCallable($panelPreferencesController);
        $this->panelConfigController = Closure::fromCallable($panelConfigController);
        $this->panelLogsController = Closure::fromCallable($panelLogsController);
        $this->panelRoutingController = Closure::fromCallable($panelRoutingController);
        $this->panelUpdateController = Closure::fromCallable($panelUpdateController);
        $this->panelSystemController = Closure::fromCallable($panelSystemController);
        $this->input = $input;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->renderNotFound = Closure::fromCallable($renderNotFound);
        $this->enabledExtensions = $enabledExtensions;
        $this->enabledExtensionManifests = $enabledExtensionManifests;
        $this->extensionPermissionCatalog = $extensionPermissionCatalog;
        $this->internalPath = $internalPath;
        $this->renderPublicNotFound = Closure::fromCallable($renderPublicNotFound);
    }
}
