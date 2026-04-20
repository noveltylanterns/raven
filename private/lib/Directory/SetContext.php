<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/SetContext.php
 * Legacy alias to the canonical taxonomy-set parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\SetParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\SetParser`.
 */
if (!class_exists(__NAMESPACE__ . '\SetContext', false)) {
    class_alias(SetParser::class, __NAMESPACE__ . '\SetContext');
}
