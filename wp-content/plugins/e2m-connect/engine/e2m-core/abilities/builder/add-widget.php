<?php
/**
 * E2M Connect MCP - Add Widget (universal)
 *
 * Generic "insert any widget" tool. Accepts a E2M Connect-friendly widget slug
 * (e.g. "heading", "pricing-table") plus a free-form settings map, and the
 * active builder's adapter resolves the native widget type. Used when the
 * caller already knows the widget type and does not need a per-widget
 * schema hint.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-widget', [
	'label'       => __( '[Builder] Add Widget', 'e2mconnect' ),
	'description' => 'Adds a widget of any registered friendly type (heading, button, image, form, etc.) to a post. Settings are passed through to the builder as-is.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'widget_type' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'E2M Connect-friendly widget slug.' ],
				'settings'    => [ 'type' => 'object', 'additionalProperties' => true ],
			]
		),
		'required'             => [ 'post_id', 'widget_type' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_widget_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Widget' ),
	],
] );

/**
 * Execute the add-widget ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_widget_ability( array $input ) {
	$friendly = isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : '';
	if ( $friendly === '' ) {
		return new WP_Error( 'invalid_widget_type', __( 'widget_type is required.', 'e2mconnect' ) );
	}
	return e2m_engine_widget_shortcut_run( $input, $friendly );
}
