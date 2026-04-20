<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Feed.php
 * Legacy alias to the canonical feed parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\FeedParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\FeedParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Feed', false)) {
    class_alias(FeedParser::class, __NAMESPACE__ . '\Feed');
}
