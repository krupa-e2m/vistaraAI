<?php
/**
 * E2M Connect MCP - Gutenberg Get Block Schema
 *
 * Returns a single block's attribute schema and supports flags so MCP
 * agents can decide whether a native block can reproduce a design section
 * (e.g. does it support spacing/color/typography) before falling back to a
 * custom block. The output is intentionally lossy - we surface the fields
 * useful for planning/code generation, not the entire internal block type.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-get-block-schema', [
	'label'       => __( '[Gutenberg] Get Block Schema', 'e2mconnect' ),
	'description' => 'Returns the attribute schema and supports flags for a single registered block.',
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'block_name' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Registered block name (e.g. "core/group", "core/paragraph").' ],
		],
		'required'             => [ 'block_name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'block'      => [ 'type' => 'string' ],
			'attributes' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name'    => [ 'type' => 'string' ],
						'type'    => [ 'type' => 'string' ],
						'default' => [ 'description' => 'Default value (mixed type).' ],
					],
				],
			],
			'supports' => [
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'Raw supports config (e.g. spacing, color, typography, align).',
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_get_block_schema_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Get Block Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the gutenberg-get-block-schema ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_get_block_schema_ability( array $input ) {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return new WP_Error( 'block_registry_missing', __( 'The WordPress block type registry is unavailable.', 'e2mconnect' ) );
	}

	$block_name = isset( $input['block_name'] ) ? sanitize_text_field( (string) $input['block_name'] ) : '';
	if ( $block_name === '' ) {
		return new WP_Error( 'invalid_block_name', __( 'block_name is required.', 'e2mconnect' ) );
	}

	$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
	if ( ! $block_type ) {
		return new WP_Error( 'block_not_found', __( 'No block matches that name.', 'e2mconnect' ) );
	}

	$attributes = [];

	// Third-party blocks can register malformed attribute schemas; guard so
	// one bad registration cannot bring down the whole response.
	try {
		$raw_attributes = (array) ( $block_type->attributes ?? [] );
	} catch ( \Throwable $e ) {
		return new WP_Error( 'block_attributes_error', sprintf( 'Block threw while reading attributes: %s', $e->getMessage() ) );
	}

	foreach ( $raw_attributes as $key => $attribute ) {
		if ( ! is_array( $attribute ) ) {
			continue;
		}
		$attributes[] = [
			'name'    => (string) $key,
			'type'    => (string) ( $attribute['type'] ?? '' ),
			'default' => $attribute['default'] ?? null,
		];
	}

	$supports = (array) ( $block_type->supports ?? [] );

	return [
		'block'      => $block_name,
		'attributes' => $attributes,
		'supports'   => $supports,
	];
}
