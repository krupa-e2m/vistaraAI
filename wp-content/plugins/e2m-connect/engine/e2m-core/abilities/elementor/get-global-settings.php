<?php
/**
 * E2M Connect MCP - Elementor Get Global Settings
 *
 * Reads the "Site Settings" / kit globals: palette colours, system
 * typography, and the space/button/form presets a site's design system
 * is built on. Everything the Elementor UI calls a "Global" lives here.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-get-global-settings', [
	'label'       => __( '[Elementor] Get Global Settings', 'e2mconnect' ),
	'description' => 'Returns the current Elementor kit globals (colors, typography, layout settings).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'kit_id'        => [ 'type' => 'integer' ],
			'system_colors' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'custom_colors' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'system_typography' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'custom_typography' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'settings'      => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Full raw kit settings blob for advanced callers.' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_get_global_settings_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Get Global Settings',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-get-global-settings ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_get_global_settings_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$kit_id = e2m_engine_elementor_active_kit_id();
	if ( $kit_id <= 0 ) {
		return new WP_Error( 'kit_missing', __( 'No active Elementor kit was found.', 'e2mconnect' ) );
	}

	$raw      = get_post_meta( $kit_id, '_elementor_page_settings', true );
	$settings = is_array( $raw ) ? $raw : [];

	return [
		'kit_id'            => $kit_id,
		'system_colors'     => (array) ( $settings['system_colors'] ?? [] ),
		'custom_colors'     => (array) ( $settings['custom_colors'] ?? [] ),
		'system_typography' => (array) ( $settings['system_typography'] ?? [] ),
		'custom_typography' => (array) ( $settings['custom_typography'] ?? [] ),
		'settings'          => $settings,
	];
}

/**
 * Resolve the active kit post ID. Elementor stores the active kit as a
 * site option; we fall back to querying for any "elementor_library" post
 * of type "kit" when the option is missing.
 */
function e2m_engine_elementor_active_kit_id(): int {
	$id = (int) get_option( 'elementor_active_kit' );
	if ( $id > 0 && get_post( $id ) ) {
		return $id;
	}

	$fallback = get_posts(
		[
			'post_type'      => 'elementor_library',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'       => '_elementor_template_type',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => 'kit',
			'fields'         => 'ids',
		]
	);

	return isset( $fallback[0] ) ? (int) $fallback[0] : 0;
}
