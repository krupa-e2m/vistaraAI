<?php
/**
 * E2M Connect MCP - Get Menu
 *
 * Returns a single menu with its full ordered item tree so agents can render
 * a complete navigation snapshot with one call.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-menu', [
	'label'       => __( '[Menu] Get Menu', 'e2mconnect' ),
	'description' => 'Retrieves a menu with its fully populated item tree.',
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
			'menu_id'   => [ 'type' => 'integer' ],
			'name'      => [ 'type' => 'string' ],
			'slug'      => [ 'type' => 'string' ],
			'locations' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'items'     => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'item_id'   => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'url'       => [ 'type' => 'string' ],
						'type'      => [ 'type' => 'string' ],
						'type_label'=> [ 'type' => 'string' ],
						'object_id' => [ 'type' => 'integer' ],
						'parent_id' => [ 'type' => 'integer' ],
						'position'  => [ 'type' => 'integer' ],
						'target'    => [ 'type' => 'string' ],
						'classes'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_get_menu_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Menu',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-menu ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_menu_ability( array $input ) {
	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	if ( $menu_id <= 0 ) {
		return new WP_Error( 'invalid_menu_id', __( 'A valid menu_id is required.', 'e2mconnect' ) );
	}

	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu ) {
		return new WP_Error( 'menu_not_found', __( 'Menu not found.', 'e2mconnect' ) );
	}

	$items   = wp_get_nav_menu_items( $menu_id ) ?: [];
	$flipped = [];
	foreach ( (array) get_nav_menu_locations() as $location => $assigned ) {
		if ( (int) $assigned === $menu_id ) {
			$flipped[] = (string) $location;
		}
	}

	$rows = [];
	foreach ( $items as $item ) {
		$rows[] = [
			'item_id'    => (int) $item->ID,
			'title'      => (string) $item->title,
			'url'        => (string) $item->url,
			'type'       => (string) $item->type,
			'type_label' => (string) $item->type_label,
			'object_id'  => (int) $item->object_id,
			'parent_id'  => (int) $item->menu_item_parent,
			'position'   => (int) $item->menu_order,
			'target'     => (string) $item->target,
			'classes'    => array_values( array_filter( (array) $item->classes ) ),
		];
	}

	return [
		'menu_id'   => $menu_id,
		'name'      => (string) $menu->name,
		'slug'      => (string) $menu->slug,
		'locations' => $flipped,
		'items'     => $rows,
	];
}
