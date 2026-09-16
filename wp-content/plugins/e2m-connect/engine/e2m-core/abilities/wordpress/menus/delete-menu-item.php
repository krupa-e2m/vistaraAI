<?php
/**
 * E2M Connect MCP - Delete Menu Item
 *
 * Removes a single menu item from its parent menu. Any children of the deleted
 * item are promoted to the menu's root level by core; this ability does not
 * cascade-delete children by default.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-menu-item', [
	'label'       => __( '[Menu] Delete Item', 'e2mconnect' ),
	'description' => 'Removes a menu item from its menu.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'item_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'item_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'item_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_menu_item_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Menu Item',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-menu-item ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_menu_item_ability( array $input ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit menus.', 'e2mconnect' ) );
	}

	$item_id = isset( $input['item_id'] ) ? (int) $input['item_id'] : 0;
	if ( $item_id <= 0 ) {
		return new WP_Error( 'invalid_item_id', __( 'A valid item_id is required.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $item_id, true );
	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete menu item.', 'e2mconnect' ) );
	}

	return [
		'item_id' => $item_id,
		'state'   => 'deleted',
	];
}
