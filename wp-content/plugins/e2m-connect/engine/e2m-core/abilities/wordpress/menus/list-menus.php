<?php
/**
 * E2M Connect MCP - List Menus
 *
 * Returns every nav menu registered on the site with item counts and the
 * theme locations the menu is currently assigned to, if any.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-menus', [
	'label'       => __( '[Menu] List Menus', 'e2mconnect' ),
	'description' => 'Lists all registered nav menus with item counts and assigned theme locations.',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'menus' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'menu_id'    => [ 'type' => 'integer' ],
						'name'       => [ 'type' => 'string' ],
						'slug'       => [ 'type' => 'string' ],
						'item_count' => [ 'type' => 'integer' ],
						'locations'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_menus_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Menus',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-menus ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_menus_ability( array $input ): array {
	$menus = wp_get_nav_menus();
	$map   = (array) get_nav_menu_locations();

	$flip = [];
	foreach ( $map as $location => $menu_id ) {
		$flip[ (int) $menu_id ][] = (string) $location;
	}

	$rows = [];
	foreach ( (array) $menus as $menu ) {
		$rows[] = [
			'menu_id'    => (int) $menu->term_id,
			'name'       => (string) $menu->name,
			'slug'       => (string) $menu->slug,
			'item_count' => (int) $menu->count,
			'locations'  => $flip[ (int) $menu->term_id ] ?? [],
		];
	}

	return [ 'menus' => $rows ];
}
