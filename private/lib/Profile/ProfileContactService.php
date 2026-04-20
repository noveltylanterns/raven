<?php

/**
 * RAVEN CMS
 * ~/private/lib/Profile/ProfileContactService.php
 * Legacy alias to the canonical user parser.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Profile;

use Raven\Lib\Parser\UserParser;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\Parser\UserParser`.
 */
if (!class_exists(__NAMESPACE__ . '\ProfileContactService', false)) {
    class_alias(UserParser::class, __NAMESPACE__ . '\ProfileContactService');
}
