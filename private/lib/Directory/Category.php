<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Category.php
 * Legacy alias to the canonical category parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\CategoryParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\CategoryParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Category', false)) {
    class_alias(CategoryParser::class, __NAMESPACE__ . '\Category');
}
