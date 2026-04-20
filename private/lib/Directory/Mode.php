<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Mode.php
 * Legacy alias to the canonical mode parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\ModeParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\ModeParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Mode', false)) {
    class_alias(ModeParser::class, __NAMESPACE__ . '\Mode');
}
