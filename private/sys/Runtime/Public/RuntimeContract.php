<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/RuntimeContract.php
 * Required public runtime factory-key contract for entry orchestration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Raven\Core\Runtime\RuntimeAssert;

/**
 * Declares and validates the minimum callable payload expected by public entry orchestration.
 */
final class RuntimeContract
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_FACTORY_KEYS = [
        'initialize_public_runtime',
        'public_page_controller',
        'public_auth_controller',
        'public_profile_controller',
        'public_category_controller',
        'public_channel_controller',
        'public_group_controller',
        'public_feed_controller',
        'public_tag_controller',
        'public_request_context',
    ];

    /**
     * Validates that one runtime payload contains all required public callables.
     *
     * @param array<string, mixed> $runtime Public runtime payload array.
     * @return void
     */
    public static function assert(array $runtime): void
    {
        RuntimeAssert::assertRequiredCallables($runtime, self::REQUIRED_FACTORY_KEYS, 'public');
    }

    /**
     * Returns the required callable-key list for public entry orchestration.
     *
     * @return array<int, string> Ordered required public runtime keys.
     */
    public static function requiredFactoryKeys(): array
    {
        return self::REQUIRED_FACTORY_KEYS;
    }
}
