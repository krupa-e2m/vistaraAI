<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('e2m/list-theme-files', [
    'label' => __('[Theme] List Files', 'e2mconnect'),
    'description' => __('Lists editable files from the active child theme or parent theme.', 'e2mconnect'),
    'category' => 'e2m-theme',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => [
                'type' => 'string',
                'enum' => ['child', 'parent'],
                'default' => 'child',
            ],
            'subdir' => [
                'type' => 'string',
                'description' => 'Optional subdirectory inside the theme.',
            ],
            'max_files' => [
                'type' => 'integer',
                'default' => 200,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => ['type' => 'string'],
            'theme_root' => ['type' => 'string'],
            'files' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'e2m_engine_list_theme_files_ability',
    'permission_callback' => 'e2m_engine_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
]);

function e2m_engine_list_theme_files_ability(array $input)
{
    $theme_scope = ($input['theme_scope'] ?? 'child') === 'parent' ? 'parent' : 'child';
    $subdir = sanitize_text_field((string) ($input['subdir'] ?? ''));
    $max_files = max(1, min(500, absint($input['max_files'] ?? 200)));

    $files = e2m_engine_list_theme_files($theme_scope, $subdir, $max_files);
    if (is_wp_error($files)) {
        return $files;
    }

    return [
        'theme_scope' => $theme_scope,
        'theme_root' => e2m_engine_get_theme_root_by_scope($theme_scope),
        'files' => $files,
    ];
}

