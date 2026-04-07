<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Extension/ExtensionRegistry.php
 * Legacy location passthrough — ExtensionRegistry now lives in private/lib/Extension/.
 * Docs: https://raven.lanterns.io
 *
 * This file is a redirect shim only. All logic lives in the lib version.
 * Callers that use Raven\Core\Extension\ExtensionRegistry are mapped to
 * Raven\Lib\Extension\ExtensionRegistry via class_alias so they continue to
 * work without a namespace update. Update call sites to the lib namespace when
 * convenient and remove this shim once none remain.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/Extension/ExtensionRegistry.php';

// Register the old namespace as an alias so callers using Raven\Core\Extension\ExtensionRegistry
// still resolve to the same class without requiring a mass namespace update in one pass.
if (!class_exists('Raven\Core\Extension\ExtensionRegistry', false)) {
    class_alias('Raven\Lib\Extension\ExtensionRegistry', 'Raven\Core\Extension\ExtensionRegistry');
}
