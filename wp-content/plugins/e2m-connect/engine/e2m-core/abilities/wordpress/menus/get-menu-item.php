<?php
/**
 * E2M Connect MCP - Get Menu Item
 *
 * Returns a single menu item record. Used to inspect a specific item before
 * calling update-menu-item with partial changes.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-menu-item', [
	'label'       => __( '[Menu] Get Item', 'e2mconnect' ),
	'description' => 'Retrieves a single menu item by its ID.',
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
			'item_id'     => [ 'type' => 'integer' ],
			'menu_id'     => [ 'type' => 'integer' ],
			'title'       => [ 'type' => 'string' ],
			'url'         => [ 'type' => 'string' ],
			'type'        => [ 'type' => 'string' ],
			'object_id'   => [ 'type' => 'integer' ],
			'object_type' => [ 'type' => 'string' ],
			'parent_id'   => [ 'type' => 'integer' ],
			'position'    => [ 'type' => 'integer' ],
			'target'      => [ 'type' => 'string' ],
			'classes'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'xfn'         => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
			'attr_title'  => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_menu_item_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Menu Item',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-menu-item ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_menu_item_ability( array $input ) {
	$item_id = isset( $input['item_id'] ) ? (int) $input['item_id'] : 0;
	if ( $item_id <= 0 ) {
		return new WP_Error( 'invalid_item_id', __( 'A valid item_id is required.', 'e2mconnect' ) );
	}

	$item = wp_setup_nav_menu_item( get_post( $item_id ) );
	if ( ! $item || empty( $item->ID ) ) {
		return new WP_Error( 'item_not_found', __( 'Menu item not found.', 'e2mconnect' ) );
	}

	$menu_ids = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );
	$menu_id  = ! is_wp_error( $menu_ids ) && is_array( $menu_ids ) && isset( $menu_ids[0] ) ? (int) $menu_ids[0] : 0;

	return [
		'item_id'     => (int) $item->ID,
		'menu_id'     => $menu_id,
		'title'       => (string) $item->title,
		'url'         => (string) $item->url,
		'type'        => (string) $item->type,
		'object_id'   => (int) $item->object_id,
		'object_type' => (string) $item->object,
		'parent_id'   => (int) $item->menu_item_parent,
		'position'    => (int) $item->menu_order,
		'target'      => (string) $item->target,
		'classes'     => array_values( array_filter( (array) $item->classes ) ),
		'xfn'         => (string) $item->xfn,
		'description' => (string) $item->description,
		'attr_title'  => (string) $item->attr_title,
	];
}
