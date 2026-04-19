<?php

/**
 * RAVEN CMS
 * ~/public/index.php
 * Public web front controller for site routing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

// Keep the public webroot limited to universal entry delegation only.
// Route-specific registration and runtime wiring belong under `private/sys/`.
require_once dirname(__DIR__) . '/private/sys/Controller/Public/PublicController.php';

\Raven\Core\Controller\Public\PublicController::handle();
