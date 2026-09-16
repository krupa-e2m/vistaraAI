<?php
/**
 * E2M Connect MCP - Assign Menu Location
 *
 * Binds a menu to a theme location slug. Pass menu_id=0 to clear the slot.
 * Location slugs are discovered via list-menu-locations.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/assign-menu-location', [
	'label'       => __( '[Menu] Assign Location', 'e2mconnect' ),
	'description' => 'Assigns a menu to a theme location. Set menu_id=0 to clear the location.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'location' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Theme location slug.' ],
			'menu_id'  => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0 to clear.' ],
		],
		'required'             => [ 'location', 'menu_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'location' => [ 'type' => 'string' ],
			'menu_id'  => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_assign_menu_location_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Assign Menu Location',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the assign-menu-location ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_assign_menu_location_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to assign menu locations.', 'e2mconnect' ) );
	}

	$location = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : '';
	$menu_id  = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : -1;

	if ( $location === '' || $menu_id < 0 ) {
		return new WP_Error( 'invalid_input', __( 'location and menu_id are required.', 'e2mconnect' ) );
	}

	$registered = (array) get_registered_nav_menus();
	if ( ! isset( $registered[ $location ] ) ) {
		return new WP_Error( 'unknown_location', __( 'Theme has no such menu location.', 'e2mconnect' ) );
	}

	if ( $menu_id > 0 && ! wp_get_nav_menu_object( $menu_id ) ) {
		return new WP_Error( 'menu_not_found', __( 'Menu not found.', 'e2mconnect' ) );
	}

	$assigned                = (array) get_theme_mod( 'nav_menu_locations', [] );
	$assigned[ $location ]   = $menu_id;
	set_theme_mod( 'nav_menu_locations', $assigned );

	return [
		'location' => $location,
		'menu_id'  => $menu_id,
	];
}
