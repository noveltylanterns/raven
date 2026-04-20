<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extra/Countries.php
 * Legacy alias to the canonical form-country catalog.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extra;

use Raven\Lib\View\FormCountries;

/**
 * Legacy alias retained while callers migrate to `Raven\Lib\View\FormCountries`.
 */
if (!class_exists(__NAMESPACE__ . '\Countries', false)) {
    class_alias(FormCountries::class, __NAMESPACE__ . '\Countries');
}
