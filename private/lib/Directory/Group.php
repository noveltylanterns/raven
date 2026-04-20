<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Group.php
 * Legacy alias to the canonical group parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\GroupParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\GroupParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Group', false)) {
    class_alias(GroupParser::class, __NAMESPACE__ . '\Group');
}
