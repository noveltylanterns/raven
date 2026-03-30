<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/fields.php
 * Repositories fields provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Repo does not currently expose custom page body-block editors.
 *
 * @return array<int, array{slug: string, label: string, editor: string}>
 */
return static function (): array {
    return [];
};
