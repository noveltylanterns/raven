<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/RouteValidator.php
 * Shared route-param validation guards for public/panel registrars.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared route-param validation helpers that trigger not-found callbacks.
 */
final class RouteValidator
{
    /**
     * Normalizes one slug route param or invokes one not-found callback.
     *
     * @param InputSanitizer $input Shared input normalizer.
     * @param mixed $value Raw route param value.
     * @param callable(): void $notFound Not-found callback for invalid params.
     * @return string|null Normalized slug when valid, otherwise null.
     */
    public static function slugOrNotFound(InputSanitizer $input, mixed $value, callable $notFound): ?string
    {
        $slug = $input->slug($value);
        // Invalid slug params immediately trigger the caller-provided not-found flow.
        if ($slug === null) {
            $notFound();
            return null;
        }

        return $slug;
    }

    /**
     * Normalizes one integer route param or invokes one not-found callback.
     *
     * @param InputSanitizer $input Shared input normalizer.
     * @param mixed $value Raw route param value.
     * @param int $min Minimum allowed integer value.
     * @param callable(): void $notFound Not-found callback for invalid params.
     * @return int|null Normalized integer when valid, otherwise null.
     */
    public static function intOrNotFound(InputSanitizer $input, mixed $value, int $min, callable $notFound): ?int
    {
        $number = $input->int($value, $min);
        // Invalid numeric params immediately trigger the caller-provided not-found flow.
        if ($number === null) {
            $notFound();
            return null;
        }

        return $number;
    }

    /**
     * Validates one slug against a disallowed list or invokes not-found.
     *
     * @param string|null $slug Normalized slug candidate.
     * @param array<int, string> $disallowed Slugs that must be rejected.
     * @param callable(): void $notFound Not-found callback for invalid params.
     * @return string|null Accepted slug, otherwise null.
     */
    public static function slugAllowedOrNotFound(?string $slug, array $disallowed, callable $notFound): ?string
    {
        // Reject absent slugs and reserved slugs with one shared not-found behavior.
        if ($slug === null || in_array($slug, $disallowed, true)) {
            $notFound();
            return null;
        }

        return $slug;
    }
}
