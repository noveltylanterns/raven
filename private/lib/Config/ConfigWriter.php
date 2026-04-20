<?php

/**
 * RAVEN CMS
 * ~/private/lib/Config/ConfigWriter.php
 * Legacy alias to the canonical config scribe.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Config;

use Raven\Lib\Scribe\ConfigScribe;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Scribe\ConfigScribe`.
 */
if (!class_exists(__NAMESPACE__ . '\ConfigWriter', false)) {
    class_alias(ConfigScribe::class, __NAMESPACE__ . '\ConfigWriter');
}
