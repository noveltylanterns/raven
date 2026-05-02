<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/PanelAccessCatalog.php
 * Legacy compatibility alias for AccessCatalog.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

require_once __DIR__ . '/AccessCatalog.php';

/**
 * @deprecated Use AccessCatalog instead.
 *
 * Backward-compat alias retained for extension-facing class imports.
 */
if (!class_exists(PanelAccessCatalog::class, false)) {
    class_alias(AccessCatalog::class, PanelAccessCatalog::class);
}
