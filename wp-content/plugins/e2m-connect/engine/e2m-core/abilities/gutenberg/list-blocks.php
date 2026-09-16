<?php
/**
 * E2M Connect MCP - Gutenberg List Blocks
 *
 * Enumerates every block registered with WordPress's block type registry on
 * this site - core, third-party, and custom. Useful as the first call when
 * an MCP agent is composing a Gutenberg/FSE page and wants to know which
 * blocks are actually available before picking one.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-list-blocks', [
	'label'       => __( '[Gutenberg] List Blocks', 'e2mconnect' ),
	'description' => 'Lists every block registered with WordPress, including name, title, category, keywords, and whether it is a core block.',
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'category' => [ 'type' => 'string', 'description' => 'Filter to a single block category slug (e.g. "text", "design", "widgets").' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'blocks' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name'       => [ 'type' => 'string' ],
						'title'      => [ 'type' => 'string' ],
						'category'   => [ 'type' => 'string' ],
						'keywords'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'is_core'    => [ 'type' => 'boolean' ],
						'is_dynamic' => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_list_blocks_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: List Blocks',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the gutenberg-list-blocks ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_list_blocks_ability( array $input ) {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return new WP_Error( 'block_registry_missing', __( 'The WordPress block type registry is unavailable.', 'e2mconnect' ) );
	}

	$filter = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
	$blocks = [];

	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $block_type ) {
		$category = (string) ( $block_type->category ?? '' );
		if ( $filter !== '' && $category !== $filter ) {
			continue;
		}

		$name = (string) $block_type->name;
		$blocks[] = [
			'name'       => $name,
			'title'      => (string) ( $block_type->title ?? $name ),
			'category'   => $category,
			'keywords'   => (array) ( $block_type->keywords ?? [] ),
			'is_core'    => str_starts_with( $name, 'core/' ),
			'is_dynamic' => is_callable( $block_type->render_callback ?? null ),
		];
	}

	return [ 'blocks' => $blocks ];
}
