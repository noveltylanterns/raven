<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Tag.php
 * Legacy alias to the canonical tag parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\TagParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\TagParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Tag', false)) {
    class_alias(TagParser::class, __NAMESPACE__ . '\Tag');
}
