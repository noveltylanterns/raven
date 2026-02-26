<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/public_routes.php
 * Contact extension public route registrar.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;
use Raven\Core\Routing\Router;
use Raven\Core\Security\InputSanitizer;

/**
 * Registers Contact extension routes into the public router.
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

    // Public contact-form submit endpoint used by embedded [contact] shortcodes.
    $router->add('POST', '/contact-form/submit/{slug}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $controller->notFound();
            return;
        }

        $controller->submitEmbeddedForm('contact', $slug);
    });
};
