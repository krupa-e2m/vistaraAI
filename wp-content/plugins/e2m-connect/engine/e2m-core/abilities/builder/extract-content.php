<?php
/**
 * E2M Connect MCP - Extract Content
 *
 * Returns the page/post content as a E2M Connect canonical tree, routed through
 * the builder adapter responsible for the post. MCP agents use this as the
 * starting point for any structural analysis or mutation: read once, mutate
 * in memory, write back with inject-content.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/extract-content', [
	'label'       => __( '[Builder] Extract Content', 'e2mconnect' ),
	'description' => 'Returns a post\'s builder content as a E2M Connect canonical tree. Works with Elementor, Gutenberg, and Bricks.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'builder' => [
				'type'        => 'string',
				'enum'        => [ 'auto', 'elementor', 'gutenberg', 'bricks' ],
				'default'     => 'auto',
				'description' => 'Force a specific adapter. "auto" detects from the stored post.',
			],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer' ],
			'builder'  => [ 'type' => 'string' ],
			'elements' => [
				'type'  => 'array',
				'items' => [ 'type' => 'object', 'additionalProperties' => true ],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_extract_content_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Extract Builder Content',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the extract-content ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_extract_content_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$registry = E2M_Builder_Registry::instance();
	$forced   = isset( $input['builder'] ) ? sanitize_key( (string) $input['builder'] ) : 'auto';

	$adapter = $forced === 'auto'
		? $registry->for_post( $post_id )
		: $registry->get( $forced );

	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for this post.', 'e2mconnect' ) );
	}

	$tree = $adapter->extract( $post_id );

	return [
		'post_id'  => $post_id,
		'builder'  => $adapter->slug(),
		'elements' => $tree['elements'] ?? [],
	];
}
