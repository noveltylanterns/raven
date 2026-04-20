<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/ChannelContext.php
 * Legacy alias to the canonical channel-context parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\ChannelContextParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\ChannelContextParser`.
 */
if (!class_exists(__NAMESPACE__ . '\ChannelContext', false)) {
    class_alias(ChannelContextParser::class, __NAMESPACE__ . '\ChannelContext');
}
