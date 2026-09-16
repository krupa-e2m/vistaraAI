<?php
/**
 * E2M Connect MCP - Elementor Set Popup Settings (Pro)
 *
 * Writes the trigger / timing / conditions configuration for a popup
 * template. Popup settings live inside _elementor_page_settings under a
 * "triggers" and "conditions" key; this ability merges those fields in
 * place without disturbing unrelated page settings.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-set-popup-settings', [
	'label'       => __( '[Elementor] Set Popup Settings', 'e2mconnect' ),
	'description' => 'Pro-only. Updates a popup\'s triggers and display conditions.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'popup_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
			'triggers'   => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Trigger config (e.g. { "on_load": { "enabled": true, "delay": 3 } }).' ],
			'conditions' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Condition strings (same vocabulary as set-template-conditions).' ],
			'extra'      => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Any extra page-settings keys to merge in (e.g. entrance_animation, close_on_esc).' ],
		],
		'required'             => [ 'popup_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'popup_id' => [ 'type' => 'integer' ],
			'updated'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_set_popup_settings_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Set Popup Settings (Pro)',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-set-popup-settings ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_set_popup_settings_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to update popups.', 'e2mconnect' ) );
	}

	$popup_id = isset( $input['popup_id'] ) ? (int) $input['popup_id'] : 0;
	if ( $popup_id <= 0 || ! get_post( $popup_id ) ) {
		return new WP_Error( 'invalid_popup_id', __( 'A valid popup_id is required.', 'e2mconnect' ) );
	}

	$settings = (array) get_post_meta( $popup_id, '_elementor_page_settings', true );
	$touched  = [];

	if ( isset( $input['triggers'] ) && is_array( $input['triggers'] ) ) {
		foreach ( $input['triggers'] as $key => $value ) {
			$settings[ sanitize_key( (string) $key ) ] = $value;
		}
		$touched[] = 'triggers';
	}

	if ( isset( $input['extra'] ) && is_array( $input['extra'] ) ) {
		foreach ( $input['extra'] as $key => $value ) {
			$settings[ sanitize_key( (string) $key ) ] = $value;
		}
		$touched[] = 'extra';
	}

	if ( $touched !== [] ) {
		update_post_meta( $popup_id, '_elementor_page_settings', $settings );
		delete_post_meta( $popup_id, '_elementor_css' );
	}

	if ( isset( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
		$conditions = array_values( array_filter( array_map( 'strval', $input['conditions'] ) ) );
		update_post_meta( $popup_id, '_elementor_conditions', $conditions );
		$touched[] = 'conditions';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least triggers, conditions, or extra.', 'e2mconnect' ) );
	}

	return [
		'popup_id' => $popup_id,
		'updated'  => $touched,
	];
}
