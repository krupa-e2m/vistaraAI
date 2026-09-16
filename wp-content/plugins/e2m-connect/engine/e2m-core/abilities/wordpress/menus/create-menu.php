<?php
/**
 * E2M Connect MCP - Create Menu
 *
 * Creates a new nav menu by name. Items are added separately via
 * create-menu-item; this endpoint only establishes the container.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-menu', [
	'label'       => __( '[Menu] Create Menu', 'e2mconnect' ),
	'description' => 'Creates a new nav menu. Items are added separately via create-menu-item.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'name' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_menu_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Menu',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-menu ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_menu_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage menus.', 'e2mconnect' ) );
	}

	$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
	if ( $name === '' ) {
		return new WP_Error( 'invalid_name', __( 'Menu name is required.', 'e2mconnect' ) );
	}

	$menu_id = wp_create_nav_menu( $name );
	if ( is_wp_error( $menu_id ) ) {
		return $menu_id;
	}

	return [ 'menu_id' => (int) $menu_id ];
}
