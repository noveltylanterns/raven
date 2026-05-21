<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PageRouter.php
 * Public homepage, page-route, and embedded-form route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers homepage, channel-qualified page, and embedded-form routes for the public runtime.
 */
final class PageRouter
{
    /**
     * Registers the public page and embedded-form route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving page and form routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicPageController = $deps->publicPageController;
        $publicRequestContext = $deps->publicRequestContext;
        $input = $deps->input;
        $reservedPrefixes = is_array($deps->routeConfig['reserved_prefixes'] ?? null)
            ? array_values($deps->routeConfig['reserved_prefixes'])
            : [];

        // Embedded-form submission; extension-agnostic and globally available to all
        // public pages. Registered here since FormController was folded into PageController.
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

        $router->add('GET', '/', static function () use ($publicPageController): void {
            $publicPageController()->home();
        });

        // Channel + page route for pages assigned to channels.
        $router->add('GET', '/{channel}/{slug}', static function (array $params) use ($publicPageController, $publicRequestContext, $input, $reservedPrefixes): void {
            $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            $slugRaw = strtolower(trim((string) ($params['slug'] ?? '')));

            if (
                $channel === null
                || $slugRaw === ''
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugRaw) !== 1
                || in_array($channel, $reservedPrefixes, true)
            ) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicPageController()->page($slugRaw, $channel);
        });
    }
}
