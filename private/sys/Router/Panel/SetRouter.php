<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/SetRouter.php
 * Shared panel set-route registration helper for taxonomy families.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Closure;
use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers the shared set-management route pattern under one taxonomy prefix.
 */
final class SetRouter
{
    /**
     * Registers the set-management route family under one taxonomy base path.
     *
     * Route layout: GET {base}/set, GET {base}/set/edit, GET {base}/set/edit/{id},
     * POST {base}/set/save, POST {base}/set/delete.
     *
     * @param RouteHandler $router Mutable router receiving set routes.
     * @param string $basePath Taxonomy base path including leading slash (for example `/category`).
     * @param Closure $onSetList Handler for listing taxonomy sets.
     * @param Closure $onSetEditNew Handler for rendering create-set form.
     * @param Closure $onSetEditById Handler for rendering edit-set form by ID.
     * @param Closure $onSetSave Handler for persisting a set row.
     * @param Closure $onSetDelete Handler for deleting a set row.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Not-found renderer for invalid route params.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        string $basePath,
        Closure $onSetList,
        Closure $onSetEditNew,
        Closure $onSetEditById,
        Closure $onSetSave,
        Closure $onSetDelete,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $base = rtrim($basePath, '/');

        $router->add('GET', $base . '/set', static function () use ($onSetList): void {
            $onSetList();
        });

        $router->add('GET', $base . '/set/edit', static function () use ($onSetEditNew): void {
            $onSetEditNew();
        });

        // Set edit by ID uses min=0 because set IDs may be zero-indexed in some contexts.
        $router->add('GET', $base . '/set/edit/{id}', static function (array $params) use ($onSetEditById, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 0, $renderNotFound);
            if ($id === null) {
                return;
            }

            $onSetEditById($id);
        });

        $router->add('POST', $base . '/set/save', static function () use ($onSetSave): void {
            $onSetSave();
        });

        $router->add('POST', $base . '/set/delete', static function () use ($onSetDelete): void {
            $onSetDelete();
        });
    }
}
