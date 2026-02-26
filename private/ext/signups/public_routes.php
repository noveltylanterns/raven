<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/public_routes.php
 * Signup Sheets extension public route registrar.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;
use Raven\Core\Routing\Router;
use Raven\Core\Security\InputSanitizer;

/**
 * Registers Signup Sheets extension routes into the public router.
 *
 * @param array{
 *   controller: PublicController,
 *   input: InputSanitizer
 * } $context
 */
return static function (Router $router, array $context): void {
    $controller = $context['controller'] ?? null;
    $input = $context['input'] ?? null;

    if (
        !$controller instanceof PublicController
        || !$input instanceof InputSanitizer
    ) {
        return;
    }

    // Public signup-sheet submit endpoint used by embedded [signups] shortcodes.
    $router->add('POST', '/signups/submit/{slug}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $controller->notFound();
            return;
        }

        $controller->submitEmbeddedForm('signups', $slug);
    });
};
