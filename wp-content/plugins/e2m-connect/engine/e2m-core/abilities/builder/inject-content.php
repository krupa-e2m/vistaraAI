<?php
/**
 * E2M Connect MCP - Inject Content
 *
 * Writes a E2M Connect canonical tree back into a post, delegating to the same
 * adapter that extract-content would pick. Destructive - callers should
 * extract first, mutate in memory, then inject the result.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/inject-content', [
	'label'       => __( '[Builder] Inject Content', 'e2mconnect' ),
	'description' => 'Writes a canonical builder tree back into a post. Targets Elementor, Gutenberg, or Bricks based on the forced or detected builder.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			'elements' => [
				'type'        => 'array',
				'description' => 'Canonical tree (as returned by extract-content).',
				'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'builder'  => [
				'type'    => 'string',
				'enum'    => [ 'auto', 'elementor', 'gutenberg', 'bricks' ],
				'default' => 'auto',
			],
		],
		'required'             => [ 'post_id', 'elements' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'builder' => [ 'type' => 'string' ],
			'wrote'   => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_inject_content_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Inject Builder Content',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the inject-content ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_inject_content_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$elements = isset( $input['elements'] ) && is_array( $input['elements'] ) ? $input['elements'] : [];
	$forced   = isset( $input['builder'] ) ? sanitize_key( (string) $input['builder'] ) : 'auto';

	$registry = E2M_Builder_Registry::instance();
	$adapter  = $forced === 'auto'
		? $registry->for_post( $post_id )
		: $registry->get( $forced );

	if ( ! $adapter ) {
		return new WP_Error( 'no_adapter', __( 'No builder adapter is available for the target post.', 'e2mconnect' ) );
	}

	$result = $adapter->inject( $post_id, [ 'elements' => $elements ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'post_id' => $post_id,
		'builder' => $adapter->slug(),
		'wrote'   => true,
	];
}
