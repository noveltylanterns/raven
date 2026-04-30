<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/FormRouter.php
 * Public embedded-form route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\RouteParamGuard;
use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public embedded-form submission routes.
 */
final class FormRouter
{
    /**
     * Registers embedded-form routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving the form route.
     * @param RouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        self::register($router, $deps->publicPageController, $deps->publicRequestContext, $deps->input);
    }

    /**
     * Registers the embedded-form submission route family.
     *
     * @param Router $router Mutable router receiving the form route.
     * @param callable(): object $publicPageController Lazy public-page controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route payloads.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicPageController,
        callable $publicRequestContext,
        InputSanitizer $input
    ): void {
        // This route is extension-agnostic and remains globally available to embedded forms.
        $router->add('POST', '/forms/submit', static function () use ($publicPageController, $publicRequestContext, $input): void {
            $notFound = static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            };
            $type = RouteParamGuard::slugOrNotFound($input, (string) ($_POST['_rvn_form_type'] ?? ''), $notFound);
            $slug = RouteParamGuard::slugOrNotFound($input, (string) ($_POST['_rvn_form_slug'] ?? ''), $notFound);
            if ($type === null || $slug === null) {
                return;
            }

            $publicPageController()->submitEmbeddedForm($type, $slug);
        });
    }
}
