<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicFormRouteRegistrar.php
 * Public embedded-form route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public embedded-form submission routes.
 */
final class PublicFormRouteRegistrar
{
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
            $type = $input->slug((string) ($_POST['_rvn_form_type'] ?? ''));
            $slug = $input->slug((string) ($_POST['_rvn_form_slug'] ?? ''));
            if ($type === null || $slug === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicPageController()->submitEmbeddedForm($type, $slug);
        });
    }
}
