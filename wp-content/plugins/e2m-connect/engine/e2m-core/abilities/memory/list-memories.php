<?php
/**
 * E2M Connect MCP — Memory: List Memories
 *
 * Returns all memory entries (Name, Description, Content) saved by the
 * site owner in the Memory & Instructions tab.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

wp_register_ability( 'e2m/list-memories', [
    'label'       => '[Memory] List All Memories',
    'description' => 'Returns all persistent memory entries stored by the site owner — project context, brand rules, user preferences, references, etc. Read these to carry context across conversations.',
    'category'    => 'e2m-admin',

    'permission_callback' => 'e2m_engine_permission_callback',

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'memories' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => [ 'type' => 'integer' ],
                        'name'        => [ 'type' => 'string' ],
                        'description' => [ 'type' => 'string' ],
                        'content'     => [ 'type' => 'string' ],
                        'updated_at'  => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'total' => [ 'type' => 'integer' ],
        ],
    ],

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => [ 'public' => true ],
        'annotations'  => [
            'title'       => 'List Site Memories',
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],

    'execute_callback' => 'e2m_memory_ability_list_memories',
] );

function e2m_memory_ability_list_memories( ?array $input = null ): array {
    $posts = get_posts( [
        'post_type'      => 'e2m_memory',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $memories = [];
    foreach ( $posts as $post ) {
        $memories[] = [
            'id'          => $post->ID,
            'name'        => $post->post_title,
            'description' => $post->post_excerpt,
            'content'     => $post->post_content,
            'updated_at'  => $post->post_modified_gmt,
        ];
    }

    return [
        'memories' => $memories,
        'total'    => count( $memories ),
    ];
}
