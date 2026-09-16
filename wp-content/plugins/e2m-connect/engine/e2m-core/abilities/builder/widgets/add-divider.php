<?php
/**
 * E2M Connect MCP - Add Divider
 *
 * Inserts a horizontal rule / divider widget. Style is an intent label
 * (solid / dashed / dotted) mapped by each adapter.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-divider', [
	'label'       => __( '[Widget] Divider', 'e2mconnect' ),
	'description' => 'Inserts a divider (horizontal rule) widget with optional line style.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'style' => [ 'type' => 'string', 'enum' => [ 'solid', 'dashed', 'dotted', 'double' ], 'default' => 'solid' ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_divider_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Divider' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_divider_ability( array $input ) {
	$style = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : 'solid';

	$settings = [
		'style'        => $style,
		'__inner_html' => '<hr class="wp-block-separator has-alpha-channel-opacity e2m-divider-' . esc_attr( $style ) . '"/>',
	];

	return e2m_engine_widget_shortcut_run( $input, 'divider', $settings );
}
