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
use Raven\Core\Router\PagePolicy;
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
        $publicChannelController = $deps->publicChannelController;
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
            // Both form-type and form-slug must validate before dispatching the submission.
            if ($type === null || $slug === null) {
                return;
            }

            $publicPageController()->submitEmbeddedForm($type, $slug);
        });

        $router->add('GET', '/', static function () use ($publicPageController): void {
            $publicPageController()->home();
        });

        // Channel + page route for pages assigned to channels.
        $router->add('GET', '/{channel}/{slug}', static function (array $params) use ($publicPageController, $publicChannelController, $publicRequestContext, $input, $reservedPrefixes): void {
            $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            $requestedSlug = strtolower(trim((string) ($params['slug'] ?? '')));
            $slugRaw = PagePolicy::stripPeriodSuffix($requestedSlug);

            // Validate the suffix-free segment, but pass the original request through so the
            // controller can issue a canonical redirect for file-looking aliases such as .md.
            if (
                $channel === null
                || $slugRaw === ''
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugRaw) !== 1
                || in_array($channel, $reservedPrefixes, true)
            ) {
                $publicRequestContext()->notFound();
                return;
            }

            $nestedChannelPath = $channel . '/' . $slugRaw;
            // A two-segment channel path takes precedence over a same-shaped page URL.
            if ($publicChannelController()->channelPathExists($nestedChannelPath)) {
                $publicChannelController()->channel($channel . '/' . $requestedSlug);
                return;
            }

            $publicPageController()->page($requestedSlug, $channel);
        });

        // Parent-aware channel/page route for paths deeper than one channel segment.
        // The catch-all placeholder is intentionally registered after the exact two-segment
        // route so ordinary /channel/page URLs retain their existing dispatch behavior.
        $router->add('GET', '/{channel}/{path...}', static function (array $params) use ($publicPageController, $publicChannelController, $publicRequestContext, $input, $reservedPrefixes): void {
            $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            $path = trim((string) ($params['path'] ?? ''), '/');
            $segments = $path === '' ? [] : explode('/', $path);
            $lastSegment = array_pop($segments);
            $lookupLastSegment = PagePolicy::stripPeriodSuffix(strtolower(trim((string) $lastSegment)));
            $normalizedSegments = array_map(
                static fn (mixed $segment): string => strtolower(trim((string) $segment)),
                $segments
            );
            $channelSegments = array_merge([$channel ?? ''], $normalizedSegments);

            // Every channel segment must be a canonical channel slug; only the final page
            // segment may use page-specific underscores or a file-looking suffix.
            $validChannelPath = $channel !== null
                && !in_array($channel, $reservedPrefixes, true)
                && $lookupLastSegment !== ''
                && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $lookupLastSegment) === 1;
            foreach ($segments as $segment) {
                if (preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim((string) $segment))) !== 1) {
                    $validChannelPath = false;
                    break;
                }
            }

            if (!$validChannelPath) {
                $publicRequestContext()->notFound();
                return;
            }

            $fullChannelPath = implode('/', array_merge($channelSegments, [$lookupLastSegment]));
            // A deep path with no page suffix can itself be a channel landing route.
            if ($publicChannelController()->channelPathExists($fullChannelPath)) {
                $publicChannelController()->channel(implode('/', array_merge($channelSegments, [(string) $lastSegment])));
                return;
            }

            $parentChannelPath = implode('/', $channelSegments);
            // Otherwise the final segment is a page scoped to the fully resolved parent path.
            if ($publicChannelController()->channelPathExists($parentChannelPath)) {
                $publicPageController()->page((string) $lastSegment, $parentChannelPath);
                return;
            }

            $publicRequestContext()->notFound();
        });
    }
}
