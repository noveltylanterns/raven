<?php

/**
 * RAVEN CMS
 * ~/private/sys/Factory/Panel/RuntimeContract.php
 * Required panel runtime factory-key contract for entry orchestration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Factory\Panel;

use Raven\Core\Factory\RuntimePayloadAssert;

/**
 * Declares and validates the minimum callable payload expected by panel entry orchestration.
 */
final class RuntimeContract
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_FACTORY_KEYS = [
        'auth_controller',
        'panel_dashboard_controller',
        'panel_channel_list_controller',
        'panel_channel_edit_controller',
        'panel_category_list_controller',
        'panel_category_edit_controller',
        'panel_tag_list_controller',
        'panel_tag_edit_controller',
        'panel_redirect_list_controller',
        'panel_redirect_edit_controller',
        'panel_user_list_controller',
        'panel_user_edit_controller',
        'panel_group_list_controller',
        'panel_group_edit_controller',
        'panel_page_list_controller',
        'panel_page_edit_controller',
        'panel_preferences_controller',
        'panel_config_controller',
        'panel_logs_controller',
        'panel_routing_controller',
        'panel_update_controller',
        'panel_system_controller',
        'panel_permission_map_provider',
        'panel_request_context',
        'initialize_panel_runtime',
    ];

    /**
     * Returns the required callable-key list for panel entry orchestration.
     *
     * @return array<int, string> Ordered required panel runtime keys.
     */
    public static function requiredFactoryKeys(): array
    {
        return self::REQUIRED_FACTORY_KEYS;
    }

    /**
     * Validates that one runtime payload contains all required panel callables.
     *
     * @param array<string, mixed> $runtime Panel runtime payload array.
     * @return void
     */
    public static function assert(array $runtime): void
    {
        RuntimePayloadAssert::assertRequiredCallables($runtime, self::REQUIRED_FACTORY_KEYS, 'panel');
    }
}
