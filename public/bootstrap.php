<?php

/**
 * RAVEN CMS
 * ~/public/bootstrap.php
 * Public runtime bootstrap compatibility shim.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Routing\Public\PublicRuntimeBuilder;

/**
 * Temporary adapter for callers that still require `public/bootstrap.php`.
 *
 * Keep route- and controller-specific assembly out of this file. The real
 * public runtime builder now lives under `private/sys/Core/Routing/Public/`.
 */
return static function (array $rvn): array {
    return PublicRuntimeBuilder::build($rvn);
};
