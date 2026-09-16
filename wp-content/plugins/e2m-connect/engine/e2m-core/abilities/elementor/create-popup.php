<?php
/**
 * E2M Connect MCP - Elementor Create Popup (Pro)
 *
 * Creates a new Popup template (a specialised elementor_library post with
 * _elementor_template_type = "popup"). Popup-level settings (trigger,
 * timing, conditions, close behaviour) are handled by the companion
 * elementor-set-popup-settings ability.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-create-popup', [
	'label'       => __( '[Elementor] Create Popup', 'e2mconnect' ),
	'description' => 'Pro-only. Creates a new Elementor Popup template. Use elementor-set-popup-settings to configure triggers/display conditions afterwards.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'    => [ 'type' => 'string', 'minLength' => 1 ],
			'content' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
		'required'             => [ 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'popup_id' => [ 'type' => 'integer' ],
			'edit_url' => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_create_popup_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Create Popup (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-create-popup ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_create_popup_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create popups.', 'e2mconnect' ) );
	}

	$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$content = isset( $input['content'] ) && is_array( $input['content'] ) ? $input['content'] : [];

	if ( $name === '' ) {
		return new WP_Error( 'invalid_name', __( 'name is required.', 'e2mconnect' ) );
	}

	$popup_id = wp_insert_post(
		[
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'post_title'  => $name,
		],
		true
	);
	if ( is_wp_error( $popup_id ) ) {
		return $popup_id;
	}

	update_post_meta( (int) $popup_id, '_elementor_template_type', 'popup' );
	update_post_meta( (int) $popup_id, '_elementor_edit_mode', 'builder' );

	if ( $content !== [] ) {
		E2M_Elementor_Helper::write_post_data( (int) $popup_id, $content );
	}

	return [
		'popup_id' => (int) $popup_id,
		'edit_url' => (string) get_edit_post_link( (int) $popup_id, 'raw' ),
	];
}
