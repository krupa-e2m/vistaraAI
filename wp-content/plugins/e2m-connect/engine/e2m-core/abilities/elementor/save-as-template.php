<?php
/**
 * E2M Connect MCP - Elementor Save As Template
 *
 * Copies a post's Elementor content into the template library under a new
 * name. When element_id is supplied, only that subtree is saved (useful
 * for saving a single section for reuse).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-save-as-template', [
	'label'       => __( '[Elementor] Save As Template', 'e2mconnect' ),
	'description' => 'Saves a whole post or a single element as a new template in the Elementor library.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
			'element_id'    => [ 'type' => 'string', 'description' => 'Optional. Save only this element\'s subtree.' ],
			'name'          => [ 'type' => 'string', 'minLength' => 1 ],
			'template_type' => [ 'type' => 'string', 'default' => 'page', 'description' => 'page / section / container / header / footer / popup, etc.' ],
		],
		'required'             => [ 'post_id', 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'template_id' => [ 'type' => 'integer' ],
			'name'        => [ 'type' => 'string' ],
			'type'        => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_save_as_template_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Save As Template',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-save-as-template ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_save_as_template_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$name       = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$type       = isset( $input['template_type'] ) ? sanitize_key( (string) $input['template_type'] ) : 'page';
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';

	if ( $post_id <= 0 || ! get_post( $post_id ) || $name === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and name are required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create templates.', 'e2mconnect' ) );
	}

	$source = E2M_Elementor_Helper::read_post_data( $post_id );

	if ( $element_id !== '' ) {
		$node = E2M_Elementor_Helper::find_node( $source, $element_id );
		if ( $node === null ) {
			return new WP_Error( 'element_not_found', __( 'Element not found inside the source post.', 'e2mconnect' ) );
		}
		$payload = [ $node ];
	} else {
		$payload = $source;
	}

	$template_id = wp_insert_post(
		[
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'post_title'  => $name,
		],
		true
	);

	if ( is_wp_error( $template_id ) ) {
		return $template_id;
	}

	E2M_Elementor_Helper::write_post_data( (int) $template_id, $payload );
	update_post_meta( (int) $template_id, '_elementor_template_type', $type );

	return [
		'template_id' => (int) $template_id,
		'name'        => $name,
		'type'        => $type,
	];
}
