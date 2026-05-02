<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/FormRouter.php
 * Public embedded-form route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public embedded-form submission routes.
 */
final class FormRouter
{
    /**
     * Registers embedded-form routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving the form route.
     * @param PublicRouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicRouteDeps $deps): void
    {
        self::register($router, $deps->publicPageController, $deps->publicRequestContext, $deps->input);
    }

    /**
     * Registers the embedded-form submission route family.
     *
     * @param RouteHandler $router Mutable router receiving the form route.
     * @param callable(): object $publicPageController Lazy public-page controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route payloads.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $publicPageController,
        callable $publicRequestContext,
        InputSanitizer $input
    ): void {
        // This route is extension-agnostic and remains globally available to embedded forms.
        $router->add('POST', '/forms/submit', static function () use ($publicPageController, $publicRequestContext, $input): void {
            $notFound = static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            };
            $type = RouteValidator::slugOrNotFound($input, (string) ($_POST['_rvn_form_type'] ?? ''), $notFound);
            $slug = RouteValidator::slugOrNotFound($input, (string) ($_POST['_rvn_form_slug'] ?? ''), $notFound);
            if ($type === null || $slug === null) {
                return;
            }

            $publicPageController()->submitEmbeddedForm($type, $slug);
        });
    }
}
