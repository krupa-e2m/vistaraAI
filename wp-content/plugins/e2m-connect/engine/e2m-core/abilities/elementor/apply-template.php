<?php
/**
 * E2M Connect MCP - Elementor Apply Template
 *
 * Pastes a saved template's elements into a target post. Two modes:
 *   - mode=replace: overwrite the target's _elementor_data wholesale
 *   - mode=append:  insert the template's top-level elements at the end
 *
 * Every copied node receives a fresh Elementor-style 7-char hex id so
 * multiple applications of the same template never collide.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-apply-template', [
	'label'       => __( '[Elementor] Apply Template', 'e2mconnect' ),
	'description' => 'Applies a saved Elementor template to a target post. Defaults to append; pass mode=replace to overwrite.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'mode'        => [ 'type' => 'string', 'enum' => [ 'append', 'replace' ], 'default' => 'append' ],
		],
		'required'             => [ 'post_id', 'template_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer' ],
			'template_id' => [ 'type' => 'integer' ],
			'applied'     => [ 'type' => 'integer', 'description' => 'Number of top-level nodes appended or replaced.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_apply_template_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Apply Template',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-apply-template ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_apply_template_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$template_id = isset( $input['template_id'] ) ? (int) $input['template_id'] : 0;
	$mode        = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'append';

	if ( $post_id <= 0 || $template_id <= 0 ) {
		return new WP_Error( 'invalid_input', __( 'post_id and template_id are required.', 'e2mconnect' ) );
	}
	if ( ! get_post( $post_id ) || ! get_post( $template_id ) ) {
		return new WP_Error( 'not_found', __( 'Target post or template does not exist.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$template_data = E2M_Elementor_Helper::read_post_data( $template_id );
	$template_data = array_map( 'e2m_engine_elementor_rekey_tree', $template_data );

	$target_data = $mode === 'replace'
		? $template_data
		: array_merge( E2M_Elementor_Helper::read_post_data( $post_id ), $template_data );

	$write = E2M_Elementor_Helper::write_post_data( $post_id, $target_data );
	if ( is_wp_error( $write ) ) {
		return $write;
	}

	return [
		'post_id'     => $post_id,
		'template_id' => $template_id,
		'applied'     => count( $template_data ),
	];
}

/**
 * Recursively assign new 7-char ids to every node in a native Elementor
 * tree so a template can be pasted alongside existing content without
 * id collisions.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function e2m_engine_elementor_rekey_tree( $node ): array {
	$node       = (array) $node;
	$node['id'] = strtolower( substr( (string) wp_generate_password( 7, false, false ), 0, 7 ) );

	if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
		$node['elements'] = array_map( 'e2m_engine_elementor_rekey_tree', $node['elements'] );
	}

	return $node;
}
