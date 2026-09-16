<?php
/**
 * E2M Connect MCP — Memory: Get Instructions
 *
 * Returns the Goal, Instructions text, and uploaded MD file content
 * that the site owner saved in the Memory & Instructions tab.
 * Claude should read this at the start of every session to stay aligned.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

wp_register_ability( 'e2m/get-instructions', [
    'label'       => '[Memory] Get Site Instructions',
    'description' => 'Returns the site owner\'s stored Goal, Instructions, and any extra MD file content. Call this at the start of a session to understand site context, conventions, brand rules, and how the owner expects work to be done.',
    'category'    => 'e2m-admin',

    'permission_callback' => 'e2m_engine_permission_callback',

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'goal'         => [ 'type' => 'string', 'description' => 'High-level site/project goal set by the owner.' ],
            'instructions' => [ 'type' => 'string', 'description' => 'Custom instructions for agents (Markdown).' ],
            'md_file'      => [ 'type' => 'string', 'description' => 'Content of uploaded .md file, if any.' ],
            'has_content'  => [ 'type' => 'boolean' ],
        ],
    ],

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => [ 'public' => true ],
        'annotations'  => [
            'title'        => 'Get Site Instructions',
            'instructions' => implode( "\n", [
                'MANDATORY: Call this tool at the start of EVERY conversation before doing anything else.',
                'The result contains site owner rules that govern all your actions on this site.',
                'Read every field carefully: goal, instructions, md_file.',
                'Follow all rules strictly for the entire conversation — they override your defaults.',
                'Common rules you may find: which page builder to use, password requirements for',
                'delete/update operations, design system guidelines, and content conventions.',
            ] ),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],

    'execute_callback' => 'e2m_memory_ability_get_instructions',
] );

function e2m_memory_ability_get_instructions( ?array $input = null ): array {
    $goal    = (string) get_option( 'e2m_memory_instructions_goal', '' );
    $instr   = (string) get_option( 'e2m_memory_instructions_content', '' );
    $md_file = (string) get_option( 'e2m_memory_instructions_md', '' );

    return [
        'goal'         => $goal,
        'instructions' => $instr,
        'md_file'      => $md_file,
        'has_content'  => ( $goal !== '' || $instr !== '' || $md_file !== '' ),
    ];
}
