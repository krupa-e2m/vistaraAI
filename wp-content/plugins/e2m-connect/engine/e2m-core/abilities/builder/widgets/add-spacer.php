<?php
/**
 * E2M Connect MCP - Add Spacer
 *
 * Inserts a vertical spacer widget. height_px is the single authoritative
 * dimension; adapters translate to their native size units as needed.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-spacer', [
	'label'       => __( '[Widget] Spacer', 'e2mconnect' ),
	'description' => 'Inserts a vertical spacer with a configurable pixel height.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'height_px' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 40 ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_spacer_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Spacer' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_spacer_ability( array $input ) {
	$height = isset( $input['height_px'] ) ? max( 1, min( 2000, (int) $input['height_px'] ) ) : 40;

	$settings = [
		'height'       => [ 'unit' => 'px', 'size' => $height ],
		'space'        => [ 'unit' => 'px', 'size' => $height ],
		'height_px'    => $height,
		'__inner_html' => sprintf( '<div style="height:%dpx" aria-hidden="true" class="wp-block-spacer"></div>', $height ),
	];

	return e2m_engine_widget_shortcut_run( $input, 'spacer', $settings );
}
