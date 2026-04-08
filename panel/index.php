<?php

/**
 * RAVEN CMS
 * ~/panel/index.php
 * Admin panel front controller and route dispatcher.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

// Keep the panel webroot limited to universal entry delegation only.
// Route-specific registration and runtime wiring belong under `private/sys/`.
require_once dirname(__DIR__) . '/private/sys/Core/Routing/Panel/PanelEntrypoint.php';

\Raven\Core\Routing\Panel\PanelEntrypoint::handle();
