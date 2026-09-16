<?php
/**
 * E2M Connect MCP - Elementor Manage Global Styles V3
 *
 * Read and write Elementor 3.x global styles and kit settings,
 * including the new v3 design system structure (tokens, breakpoints,
 * and the full kit settings blob).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-manage-global-styles-v3', [
	'label'       => __( '[Elementor] Manage Global Styles V3', 'e2mconnect' ),
	'description' => 'Read and write Elementor 3.x global styles and kit settings, including breakpoints, tokens, and the full design system.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get', 'update' ],
				'description' => 'get — return current global styles; update — merge provided settings into the kit.',
			],
			'settings' => [
				'type'        => 'object',
				'description' => 'Key-value pairs to merge into the Elementor kit settings. Only used with action=update.',
				'additionalProperties' => true,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'         => [ 'type' => 'string' ],
			'kit_id'         => [ 'type' => 'integer' ],
			'elementor_version' => [ 'type' => 'string' ],
			'settings'       => [ 'type' => 'object', 'additionalProperties' => true ],
			'updated_keys'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_manage_global_styles_v3_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Manage Global Styles V3',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-manage-global-styles-v3 ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_manage_global_styles_v3_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Elementor global styles.', 'e2mconnect' ) );
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$action           = sanitize_key( $input['action'] );
	$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown';
	$settings         = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );

	if ( $action === 'get' ) {
		return [
			'action'            => 'get',
			'kit_id'            => $kit_id,
			'elementor_version' => $elementor_version,
			'settings'          => $settings,
		];
	}

	if ( $action === 'update' ) {
		$incoming = $input['settings'] ?? [];
		if ( empty( $incoming ) || ! is_array( $incoming ) ) {
			return new WP_Error( 'missing_settings', __( 'Provide a settings object with keys to update.', 'e2mconnect' ) );
		}

		// Sanitize string keys; preserve nested structure.
		$updated_keys = [];
		foreach ( $incoming as $k => $v ) {
			$clean_key = sanitize_key( (string) $k );
			if ( $clean_key !== '' ) {
				$settings[ $clean_key ] = $v;
				$updated_keys[]         = $clean_key;
			}
		}

		update_post_meta( $kit_id, '_elementor_page_settings', $settings );
		delete_post_meta( $kit_id, '_elementor_css' );

		return [
			'action'            => 'update',
			'kit_id'            => $kit_id,
			'elementor_version' => $elementor_version,
			'updated_keys'      => $updated_keys,
		];
	}

	return new WP_Error( 'invalid_action', __( 'Invalid action. Use get or update.', 'e2mconnect' ) );
}
