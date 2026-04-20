<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Channel.php
 * Legacy alias to the canonical channel parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\ChannelParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\ChannelParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Channel', false)) {
    class_alias(ChannelParser::class, __NAMESPACE__ . '\Channel');
}
