<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Page.php
 * Legacy alias to the canonical page parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\PageParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\PageParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Page', false)) {
    class_alias(PageParser::class, __NAMESPACE__ . '\Page');
}
