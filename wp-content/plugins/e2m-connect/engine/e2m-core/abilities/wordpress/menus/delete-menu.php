<?php
/**
 * E2M Connect MCP - Delete Menu
 *
 * Permanently removes a nav menu and all of its items. Theme location
 * assignments are automatically detached by core.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-menu', [
	'label'       => __( '[Menu] Delete Menu', 'e2mconnect' ),
	'description' => 'Permanently removes a nav menu and its items.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'menu_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'menu_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_menu_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Menu',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-menu ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_menu_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage menus.', 'e2mconnect' ) );
	}

	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	if ( $menu_id <= 0 ) {
		return new WP_Error( 'invalid_menu_id', __( 'A valid menu_id is required.', 'e2mconnect' ) );
	}

	$result = wp_delete_nav_menu( $menu_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete menu.', 'e2mconnect' ) );
	}

	return [
		'menu_id' => $menu_id,
		'state'   => 'deleted',
	];
}
