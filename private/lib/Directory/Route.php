<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Route.php
 * Legacy alias to the canonical route parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\RouteParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\RouteParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Route', false)) {
    class_alias(RouteParser::class, __NAMESPACE__ . '\Route');
}
