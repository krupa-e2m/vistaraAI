<?php
/**
 * E2M Connect MCP - Elementor Update Page Settings
 *
 * Mutates the _elementor_page_settings meta - the blob that controls
 * page-level Elementor options like "hide title", "full width", page
 * background, etc. Supports partial updates: only supplied keys are
 * touched, everything else stays intact.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-update-page-settings', [
	'label'       => __( '[Elementor] Update Page Settings', 'e2mconnect' ),
	'description' => 'Merges partial updates into a post\'s _elementor_page_settings blob (page layout, hide title, background, etc.).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			'settings' => [ 'type' => 'object', 'additionalProperties' => true ],
		],
		'required'             => [ 'post_id', 'settings' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_update_page_settings_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Update Page Settings',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-update-page-settings ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_update_page_settings_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$patch    = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}
	if ( $patch === [] ) {
		return new WP_Error( 'empty_patch', __( 'Provide at least one setting to update.', 'e2mconnect' ) );
	}

	$existing = (array) get_post_meta( $post_id, '_elementor_page_settings', true );
	$touched  = [];
	foreach ( $patch as $key => $value ) {
		$k              = sanitize_key( (string) $key );
		$existing[ $k ] = $value;
		$touched[]      = $k;
	}

	update_post_meta( $post_id, '_elementor_page_settings', $existing );
	delete_post_meta( $post_id, '_elementor_css' );

	return [
		'post_id'        => $post_id,
		'updated_fields' => $touched,
	];
}
