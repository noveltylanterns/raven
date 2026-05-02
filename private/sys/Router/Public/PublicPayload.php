<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PublicPayload.php
 * Shared dependency payload for public route-family registrars.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Closure;
use Raven\Lib\Security\InputSanitizer;

/**
 * Canonical public route-registrar dependency payload.
 */
final class PublicPayload
{
    /** @var array<string, mixed> */
    public readonly array $rvn;
    public readonly Closure $publicAuthController;
    public readonly Closure $publicPageController;
    public readonly Closure $publicUserController;
    public readonly Closure $publicCategoryController;
    public readonly Closure $publicChannelController;
    public readonly Closure $publicGroupController;
    public readonly Closure $publicFeedController;
    public readonly Closure $publicTagController;
    public readonly Closure $publicRequestContext;
    public readonly InputSanitizer $input;
    /** @var array<string, mixed> */
    public readonly array $routeConfig;

    /**
     * @param array<string, mixed> $rvn Shared Raven runtime container.
     * @param callable(): object $publicAuthController Lazy auth controller factory.
     * @param callable(): object $publicPageController Lazy page controller factory.
     * @param callable(): object $publicUserController Lazy user controller factory.
     * @param callable(): object $publicCategoryController Lazy category controller factory.
     * @param callable(): object $publicChannelController Lazy channel controller factory.
     * @param callable(): object $publicGroupController Lazy group controller factory.
     * @param callable(): object $publicFeedController Lazy feed controller factory.
     * @param callable(): object $publicTagController Lazy tag controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array<string, mixed> $routeConfig Normalized public route policy.
     */
    public function __construct(
        array $rvn,
        callable $publicAuthController,
        callable $publicPageController,
        callable $publicUserController,
        callable $publicCategoryController,
        callable $publicChannelController,
        callable $publicGroupController,
        callable $publicFeedController,
        callable $publicTagController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ) {
        $this->rvn = $rvn;
        $this->publicAuthController = Closure::fromCallable($publicAuthController);
        $this->publicPageController = Closure::fromCallable($publicPageController);
        $this->publicUserController = Closure::fromCallable($publicUserController);
        $this->publicCategoryController = Closure::fromCallable($publicCategoryController);
        $this->publicChannelController = Closure::fromCallable($publicChannelController);
        $this->publicGroupController = Closure::fromCallable($publicGroupController);
        $this->publicFeedController = Closure::fromCallable($publicFeedController);
        $this->publicTagController = Closure::fromCallable($publicTagController);
        $this->publicRequestContext = Closure::fromCallable($publicRequestContext);
        $this->input = $input;
        $this->routeConfig = $routeConfig;
    }
}
