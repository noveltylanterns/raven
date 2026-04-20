<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Duplicate.php
 * Legacy alias to the canonical duplicate parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\DuplicateParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\DuplicateParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Duplicate', false)) {
    class_alias(DuplicateParser::class, __NAMESPACE__ . '\Duplicate');
}
