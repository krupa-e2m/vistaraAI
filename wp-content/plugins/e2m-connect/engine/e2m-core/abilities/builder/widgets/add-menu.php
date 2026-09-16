<?php
/**
 * E2M Connect MCP - Add Menu
 *
 * Inserts a navigation menu widget referencing an existing WordPress nav
 * menu by ID. The widget does not define menu items itself - use the
 * e2m-menus ability family for that.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-menu', [
	'label'       => __( '[Widget] Menu', 'e2mconnect' ),
	'description' => 'Inserts a navigation menu widget that renders an existing nav menu by ID.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'layout'  => [ 'type' => 'string', 'enum' => [ 'horizontal', 'vertical' ], 'default' => 'horizontal' ],
			]
		),
		'required'             => [ 'post_id', 'menu_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_menu_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Menu Widget' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_menu_ability( array $input ) {
	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	$layout  = isset( $input['layout'] ) ? sanitize_key( (string) $input['layout'] ) : 'horizontal';

	if ( $menu_id <= 0 || ! wp_get_nav_menu_object( $menu_id ) ) {
		return new WP_Error( 'invalid_menu_id', __( 'A valid menu_id is required.', 'e2mconnect' ) );
	}

	$menu_obj  = wp_get_nav_menu_object( $menu_id );
	$menu_slug = $menu_obj ? (string) $menu_obj->slug : '';

	$settings = [
		'menu'         => $menu_slug,
		'nav_menu'     => $menu_slug,
		'layout'       => $layout,
		'orientation'  => $layout,
		'menu_id'      => $menu_id,
		'__inner_html' => sprintf(
			'<nav class="e2m-menu e2m-menu-%s">%s</nav>',
			esc_attr( $layout ),
			wp_nav_menu(
				[
					'menu'      => $menu_id,
					'echo'      => false,
					'container' => '',
				]
			)
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'menu', $settings );
}
