<?php
/**
 * E2M Connect MCP - Elementor Update Global Colors
 *
 * Overwrites the kit's system and/or custom colour palettes. Callers
 * supply arrays of { _id, title, color } entries; the endpoint validates
 * hex values and writes the payload back onto the active kit.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-update-global-colors', [
	'label'       => __( '[Elementor] Update Global Colors', 'e2mconnect' ),
	'description' => 'Overwrites the kit\'s system or custom colour palette with the supplied entries.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'system_colors' => [
				'type'        => 'array',
				'description' => 'Elementor system slot colours (primary, secondary, text, accent).',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'_id'   => [ 'type' => 'string' ],
						'title' => [ 'type' => 'string' ],
						'color' => [ 'type' => 'string' ],
					],
					'required' => [ '_id', 'color' ],
				],
			],
			'custom_colors' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'_id'   => [ 'type' => 'string' ],
						'title' => [ 'type' => 'string' ],
						'color' => [ 'type' => 'string' ],
					],
					'required' => [ 'color' ],
				],
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

	'execute_callback'    => 'e2m_engine_elementor_update_global_colors_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Update Global Colors',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-update-global-colors ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_update_global_colors_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to change Elementor global colours.', 'e2mconnect' ) );
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$settings = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );
	$updated  = [];

	foreach ( [ 'system_colors', 'custom_colors' ] as $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			continue;
		}

		$rows = [];
		foreach ( $input[ $key ] as $row ) {
			if ( ! is_array( $row ) || empty( $row['color'] ) ) {
				continue;
			}
			$rows[] = [
				'_id'   => sanitize_key( (string) ( $row['_id'] ?? wp_generate_uuid4() ) ),
				'title' => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'color' => e2m_engine_sanitize_color_value( (string) $row['color'] ),
			];
		}
		$settings[ $key ] = $rows;
		$updated[]        = $key;
	}

	if ( $updated === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least system_colors or custom_colors.', 'e2mconnect' ) );
	}

	update_post_meta( $kit_id, '_elementor_page_settings', $settings );
	delete_post_meta( $kit_id, '_elementor_css' );

	return [
		'kit_id'  => $kit_id,
		'updated' => $updated,
	];
}

/**
 * Accept any valid CSS colour string (#rgb, #rrggbb, rgb(), hsl()) and
 * fall back to an empty string when the value is unusable.
 */
function e2m_engine_sanitize_color_value( string $value ): string {
	$value = trim( $value );
	if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
		return strtolower( $value );
	}
	if ( preg_match( '/^(rgb|rgba|hsl|hsla)\s*\(/i', $value ) ) {
		return $value;
	}
	return '';
}
