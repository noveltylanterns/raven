<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Panel.php
 * Legacy alias to the canonical panel parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Lib\Parser\PanelParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\PanelParser`.
 */
if (!class_exists(__NAMESPACE__ . '\Panel', false)) {
    class_alias(PanelParser::class, __NAMESPACE__ . '\Panel');
}
