<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/TaxonomyCrudRouter.php
 * Shared panel route builder for taxonomy CRUD and set-management families.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Closure;
use Raven\Core\Routing\RouteParamGuard;
use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared route-pattern builder for panel taxonomy CRUD and set-management families.
 *
 * Registers the canonical 10-route layout (list, edit-new, edit-by-id, save,
 * delete, and matching set-management counterparts) under a caller-supplied
 * prefix. CategoryRouter and TagRouter delegate here so the route structure
 * lives in one place while each registrar supplies its own controller closures.
 */
final class TaxonomyCrudRouter
{
    /**
     * Registers the 10-route taxonomy CRUD and set-management pattern when enabled.
     *
     * Route layout: GET /{prefix}, GET /{prefix}/edit, GET /{prefix}/edit/{id},
     * POST /{prefix}/save, POST /{prefix}/delete, GET /{prefix}/set,
     * GET /{prefix}/set/edit, GET /{prefix}/set/edit/{id},
     * POST /{prefix}/set/save, POST /{prefix}/set/delete.
     *
     * @param Router $router Mutable router receiving the taxonomy routes.
     * @param string $prefix URL prefix segment (e.g. 'category' or 'tag').
     * @param Closure $onList Handler for GET /{prefix} — renders the taxonomy item list.
     * @param Closure $onSetList Handler for GET /{prefix}/set — renders the taxonomy set list.
     * @param Closure $onEditNew Handler for GET /{prefix}/edit — renders the create-item form.
     * @param Closure $onEditById Handler for GET /{prefix}/edit/{id} — renders the edit-item form; receives (int $id).
     * @param Closure $onSave Handler for POST /{prefix}/save — persists a taxonomy item.
     * @param Closure $onDelete Handler for POST /{prefix}/delete — removes a taxonomy item.
     * @param Closure $onSetEditNew Handler for GET /{prefix}/set/edit — renders the create-set form.
     * @param Closure $onSetEditById Handler for GET /{prefix}/set/edit/{id} — renders the edit-set form; receives (int $id).
     * @param Closure $onSetSave Handler for POST /{prefix}/set/save — persists a taxonomy set.
     * @param Closure $onSetDelete Handler for POST /{prefix}/set/delete — removes a taxonomy set.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $enabled Whether this taxonomy route family is active; all routes are skipped when false.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param fails validation.
     * @return void
     */
    public static function register(
        Router $router,
        string $prefix,
        Closure $onList,
        Closure $onSetList,
        Closure $onEditNew,
        Closure $onEditById,
        Closure $onSave,
        Closure $onDelete,
        Closure $onSetEditNew,
        Closure $onSetEditById,
        Closure $onSetSave,
        Closure $onSetDelete,
        InputSanitizer $input,
        bool $enabled,
        callable $renderNotFound
    ): void {
        if (!$enabled) {
            return;
        }

        $base = '/' . $prefix;

        $router->add('GET', $base, static function () use ($onList): void {
            $onList();
        });

        $router->add('GET', $base . '/edit', static function () use ($onEditNew): void {
            $onEditNew();
        });

        $router->add('GET', $base . '/edit/{id}', static function (array $params) use ($onEditById, $input, $renderNotFound): void {
            $id = RouteParamGuard::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
                return;
            }
            $onEditById($id);
        });

        $router->add('POST', $base . '/save', static function () use ($onSave): void {
            $onSave();
        });

        $router->add('POST', $base . '/delete', static function () use ($onDelete): void {
            $onDelete();
        });

        $router->add('GET', $base . '/set', static function () use ($onSetList): void {
            $onSetList();
        });

        $router->add('GET', $base . '/set/edit', static function () use ($onSetEditNew): void {
            $onSetEditNew();
        });

        // Set edit by ID uses min=0 because set IDs may be zero-indexed in some contexts.
        $router->add('GET', $base . '/set/edit/{id}', static function (array $params) use ($onSetEditById, $input, $renderNotFound): void {
            $id = RouteParamGuard::intOrNotFound($input, $params['id'] ?? null, 0, $renderNotFound);
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
