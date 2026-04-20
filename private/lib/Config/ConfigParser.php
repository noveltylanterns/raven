<?php

/**
 * RAVEN CMS
 * ~/private/lib/Config/ConfigParser.php
 * Legacy alias to the canonical config parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Config;

use Raven\Lib\Parser\ConfigParser as ParserConfigParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\ConfigParser`.
 */
if (!class_exists(__NAMESPACE__ . '\ConfigParser', false)) {
    class_alias(ParserConfigParser::class, __NAMESPACE__ . '\ConfigParser');
}
