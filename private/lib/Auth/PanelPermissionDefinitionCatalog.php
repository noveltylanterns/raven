<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/PanelPermissionDefinitionCatalog.php
 * Legacy compatibility alias for PermissionDefinitionCatalog.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

require_once __DIR__ . '/PermissionDefinitionCatalog.php';

/**
 * @deprecated Use PermissionDefinitionCatalog instead.
 *
 * Backward-compat alias retained for extension-facing class imports.
 */
if (!class_exists(PanelPermissionDefinitionCatalog::class, false)) {
    class_alias(PermissionDefinitionCatalog::class, PanelPermissionDefinitionCatalog::class);
}
