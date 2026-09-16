<?php
/**
 * E2M Connect MCP - Elementor Update Global Typography
 *
 * Overwrites the kit's system and/or custom typography presets. Each entry
 * is a full Elementor "typography" setting (font family, weight, size,
 * etc.). Callers typically read globals first, patch a single preset, and
 * send the full list back.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-update-global-typography', [
	'label'       => __( '[Elementor] Update Typography', 'e2mconnect' ),
	'description' => 'Overwrites the kit\'s system or custom typography presets. Each entry is a full typography setting object.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'system_typography' => [
				'type'  => 'array',
				'items' => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'custom_typography' => [
				'type'  => 'array',
				'items' => [ 'type' => 'object', 'additionalProperties' => true ],
			],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'kit_id'  => [ 'type' => 'integer' ],
			'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_update_global_typography_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Update Global Typography',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-update-global-typography ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_update_global_typography_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to change Elementor global typography.', 'e2mconnect' ) );
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$settings = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );
	$updated  = [];

	foreach ( [ 'system_typography', 'custom_typography' ] as $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			continue;
		}
		// Typography entries are opaque to us; pass them through as-is but
		// reject non-array rows to keep Elementor's parser happy.
		$rows = array_values(
			array_filter( $input[ $key ], static fn( $row ) => is_array( $row ) )
		);
		$settings[ $key ] = $rows;
		$updated[]        = $key;
	}

	if ( $updated === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least system_typography or custom_typography.', 'e2mconnect' ) );
	}

	update_post_meta( $kit_id, '_elementor_page_settings', $settings );
	delete_post_meta( $kit_id, '_elementor_css' );

	return [
		'kit_id'  => $kit_id,
		'updated' => $updated,
	];
}
