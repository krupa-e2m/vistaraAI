<?php
/**
 * E2M Connect MCP - List Menu Items
 *
 * Shortcut that returns just the ordered items of a menu, without the parent
 * menu envelope. Useful when an agent already knows it is editing a menu and
 * just needs the next position / existing structure.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-menu-items', [
	'label'       => __( '[Menu] List Items', 'e2mconnect' ),
	'description' => 'Lists the ordered items of a nav menu.',
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
			'items'   => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'item_id'   => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'url'       => [ 'type' => 'string' ],
						'type'      => [ 'type' => 'string' ],
						'object_id' => [ 'type' => 'integer' ],
						'parent_id' => [ 'type' => 'integer' ],
						'position'  => [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_menu_items_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Menu Items',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-menu-items ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_menu_items_ability( array $input ) {
	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	if ( $menu_id <= 0 || ! wp_get_nav_menu_object( $menu_id ) ) {
		return new WP_Error( 'invalid_menu_id', __( 'A valid menu_id is required.', 'e2mconnect' ) );
	}

	$items = wp_get_nav_menu_items( $menu_id ) ?: [];

	$rows = [];
	foreach ( $items as $item ) {
		$rows[] = [
			'item_id'   => (int) $item->ID,
			'title'     => (string) $item->title,
			'url'       => (string) $item->url,
			'type'      => (string) $item->type,
			'object_id' => (int) $item->object_id,
			'parent_id' => (int) $item->menu_item_parent,
			'position'  => (int) $item->menu_order,
		];
	}

	return [
		'menu_id' => $menu_id,
		'items'   => $rows,
	];
}
