<?php
/**
 * Manage E2M MCP module settings and advanced workspace behavior.
 *
 * Exposes read/update abilities so AI clients can inspect and adjust
 * enabled modules without editing plugin options directly.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('e2m/get-mcp-settings', [
    'label'       => __('[E2M] Get Settings', 'e2mconnect'),
    'description' => __('Returns current MCP module settings, workspace status, and ability counts per module.', 'e2mconnect'),
    'category'    => 'e2m-bridge',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'e2m_engine_ability_get_settings',
    'permission_callback' => 'e2m_engine_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns MCP settings including which ability modules are enabled or disabled, workspace status, and a count of registered abilities. Use this to understand what's active. Use e2m/update-mcp-settings to toggle modules.",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('e2m/update-mcp-settings', [
    'label'       => __('[E2M] Update Settings', 'e2mconnect'),
    'description' => __('Enable or disable ability modules and workspace features. Changes take effect on the next request.', 'e2mconnect'),
    'category'    => 'e2m-bridge',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'sandbox_enabled' => [
                'type' => 'boolean',
                'description' => 'Enable or disable the advanced workspace loader when available in the current build.',
            ],
            'modules' => [
                'type' => 'object',
                'description' => 'Module toggles. Keys: wordpress (pages, filesystem, theme, design), elementor (page builder layouts and widgets), the_plus_addons (The Plus Addons widget abilities), nexter_extension (Nexter Extension settings), nexter_blocks (Nexter Blocks for Gutenberg - coming soon). Values: true/false.',
                'properties' => [
                    'wordpress'        => ['type' => 'boolean', 'description' => 'Core WordPress abilities: pages, filesystem, theme, and design.'],
                    'elementor'        => ['type' => 'boolean', 'description' => 'Elementor page builder abilities.'],
                    'the_plus_addons'  => ['type' => 'boolean', 'description' => 'The Plus Addons for Elementor widget abilities.'],
                    'nexter_extension' => ['type' => 'boolean', 'description' => 'Nexter Extension abilities.'],
                    'nexter_blocks'    => ['type' => 'boolean', 'description' => 'Nexter Blocks for Gutenberg.'],
                ],
                'additionalProperties' => true,
            ],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'e2m_engine_ability_update_settings',
    'permission_callback' => 'e2m_engine_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Toggles ability modules on/off. Only provided keys are changed - omitted keys keep their current value. Changes take effect on the next MCP request. Tip: disable modules you don't need to reduce the number of tools exposed and save tokens.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function e2m_engine_ability_get_settings(array $input): array {
    $settings = e2m_engine_get_settings();
    $ai_enabled = e2m_engine_is_enabled();

    // Count abilities per prefix to show what each module provides.
    $all = wp_get_abilities();
    $prefix_counts = ['e2m/' => 0, 'nexter/' => 0, 'theplus' => 0];
    foreach ($all as $name => $ab) {
        if (str_starts_with($name, 'e2m/')) { $prefix_counts['e2m/']++; }
        elseif (str_starts_with($name, 'nexter/')) { $prefix_counts['nexter/']++; }
        if (str_contains($name, 'theplus')) { $prefix_counts['theplus']++; }
    }

    $module_info = [
        'wordpress' => [
            'enabled' => $settings['modules']['wordpress'],
            'description' => 'Core WordPress: pages, filesystem, theme, and design',
        ],
        'elementor' => [
            'enabled' => $settings['modules']['elementor'],
            'description' => 'Elementor page builder: layouts, containers, widgets, templates',
        ],
        'the_plus_addons' => [
            'enabled' => $settings['modules']['the_plus_addons'],
            'description' => 'The Plus Addons for Elementor: 100+ widget abilities',
        ],
        'nexter_extension' => [
            'enabled' => $settings['modules']['nexter_extension'],
            'description' => 'Nexter Extension: snippets, theme builder, fonts, SMTP, security, performance, admin settings',
        ],
        'nexter_blocks' => [
            'enabled' => $settings['modules']['nexter_blocks'],
            'description' => 'Nexter Blocks for Gutenberg (coming soon)',
        ],
    ];

    return [
        'success' => true,
        'ai_abilities_enabled' => $ai_enabled,
        'sandbox_enabled' => $settings['sandbox_enabled'],
        'modules' => $module_info,
        'ability_counts' => [
            'total' => count($all),
            'e2m_core' => $prefix_counts['e2m/'],
            'nexter' => $prefix_counts['nexter/'],
            'the_plus' => $prefix_counts['theplus'],
        ],
        'tip' => 'Disable modules you don\'t need to reduce tool count and save tokens. Use e2m/update-mcp-settings to toggle.',
    ];
}

function e2m_engine_ability_update_settings(array $input): array {
    $current = e2m_engine_get_settings();
    $changed = [];

    if (isset($input['sandbox_enabled'])) {
        $current['sandbox_enabled'] = (bool) $input['sandbox_enabled'];
        $changed[] = 'sandbox_enabled → ' . ($current['sandbox_enabled'] ? 'on' : 'off');
    }

    if (isset($input['modules']) && is_array($input['modules'])) {
        foreach ($input['modules'] as $key => $val) {
            if (isset($current['modules'][$key])) {
                $current['modules'][$key] = (bool) $val;
                $changed[] = $key . ' → ' . ($current['modules'][$key] ? 'on' : 'off');
            }
        }
    }

    if (empty($changed)) {
        return [
            'success' => true,
            'message' => 'No changes provided.',
            'settings' => $current,
        ];
    }

    update_option('e2m_engine_settings', $current);

    return [
        'success' => true,
        'message' => 'Settings updated. Changes take effect on next request.',
        'changed' => $changed,
        'settings' => $current,
    ];
}
