<?php
/**
 * E2M Connect MCP - List Menu Locations
 *
 * Returns every theme menu location (the abstract "slots" themes register via
 * register_nav_menus()) paired with the menu currently assigned to each one.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-menu-locations', [
	'label'       => __( '[Menu] List Locations', 'e2mconnect' ),
	'description' => 'Lists every theme-declared menu location and the assigned menu (if any).',
	'category'    => 'e2m-menus',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'locations' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'slug'            => [ 'type' => 'string' ],
						'label'           => [ 'type' => 'string' ],
						'assigned_menu_id'=> [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_menu_locations_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Menu Locations',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-menu-locations ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_menu_locations_ability( array $input ): array {
	$registered = (array) get_registered_nav_menus();
	$assigned   = (array) get_nav_menu_locations();

	$rows = [];
	foreach ( $registered as $slug => $label ) {
		$rows[] = [
			'slug'             => (string) $slug,
			'label'            => (string) $label,
			'assigned_menu_id' => (int) ( $assigned[ $slug ] ?? 0 ),
		];
	}

	return [ 'locations' => $rows ];
}
