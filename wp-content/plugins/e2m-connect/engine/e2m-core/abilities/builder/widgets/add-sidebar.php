<?php
/**
 * E2M Connect MCP - Add Sidebar
 *
 * Inserts a widget that renders a WordPress sidebar (registered via
 * register_sidebar). The sidebar's widgets themselves are edited through
 * WP's widget UI; this ability only embeds the slot on the page.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-sidebar', [
	'label'       => __( '[Widget] Sidebar', 'e2mconnect' ),
	'description' => 'Inserts a widget that renders a registered WordPress sidebar by slug.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'sidebar_id' => [ 'type' => 'string', 'minLength' => 1 ],
			]
		),
		'required'             => [ 'post_id', 'sidebar_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_sidebar_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Sidebar Widget' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_sidebar_ability( array $input ) {
	$sidebar_id = sanitize_key( (string) ( $input['sidebar_id'] ?? '' ) );
	if ( $sidebar_id === '' ) {
		return new WP_Error( 'invalid_sidebar', __( 'A sidebar_id is required.', 'e2mconnect' ) );
	}

	$settings = [
		'sidebar'      => $sidebar_id,
		'sidebar_id'   => $sidebar_id,
		'__inner_html' => sprintf( '<aside class="e2m-sidebar" data-sidebar="%s"></aside>', esc_attr( $sidebar_id ) ),
	];

	return e2m_engine_widget_shortcut_run( $input, 'sidebar', $settings );
}
