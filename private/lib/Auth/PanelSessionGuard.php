<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/PanelSessionGuard.php
 * Legacy compatibility alias for SessionGuard.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

require_once __DIR__ . '/SessionGuard.php';

/**
 * @deprecated Use SessionGuard instead.
 *
 * Backward-compat alias retained for extension-facing class imports.
 */
if (!class_exists(PanelSessionGuard::class, false)) {
    class_alias(SessionGuard::class, PanelSessionGuard::class);
}
