<?php
/**
 * E2M Connect MCP - Update Menu
 *
 * Renames a nav menu. WordPress ties the URL-friendly slug to the menu name,
 * so this is the canonical way to "rename" one; slug recomputes automatically.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-menu', [
	'label'       => __( '[Menu] Update Menu', 'e2mconnect' ),
	'description' => 'Renames a nav menu.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'name'    => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'menu_id', 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id' => [ 'type' => 'integer' ],
			'name'    => [ 'type' => 'string' ],
			'slug'    => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_menu_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Menu',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-menu ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_menu_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage menus.', 'e2mconnect' ) );
	}

	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	$name    = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';

	if ( $menu_id <= 0 || $name === '' ) {
		return new WP_Error( 'invalid_input', __( 'menu_id and non-empty name are required.', 'e2mconnect' ) );
	}

	$result = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => $name ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$menu = wp_get_nav_menu_object( $menu_id );

	return [
		'menu_id' => $menu_id,
		'name'    => $menu ? (string) $menu->name : $name,
		'slug'    => $menu ? (string) $menu->slug : '',
	];
}
