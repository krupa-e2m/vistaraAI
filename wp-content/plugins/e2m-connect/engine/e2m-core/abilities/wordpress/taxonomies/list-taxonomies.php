<?php
/**
 * E2M Connect MCP - List Taxonomies
 *
 * Enumerates taxonomies registered on the site and reports which post types
 * each one is bound to. Used to surface categories, tags, and any custom
 * taxonomies introduced by themes or plugins.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-taxonomies', [
	'label'       => __( '[Taxonomy] List Taxonomies', 'e2mconnect' ),
	'description' => 'Lists registered taxonomies with their bound post types, labels, and visibility flags.',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'public_only'  => [ 'type' => 'boolean', 'default' => false ],
			'exclude_core' => [ 'type' => 'boolean', 'default' => false ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'taxonomies' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'slug'           => [ 'type' => 'string' ],
						'label'          => [ 'type' => 'string' ],
						'label_singular' => [ 'type' => 'string' ],
						'hierarchical'   => [ 'type' => 'boolean' ],
						'public'         => [ 'type' => 'boolean' ],
						'show_in_rest'   => [ 'type' => 'boolean' ],
						'post_types'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'is_core'        => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_taxonomies_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Taxonomies',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-taxonomies ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_taxonomies_ability( array $input ): array {
	$public_only  = ! empty( $input['public_only'] );
	$exclude_core = ! empty( $input['exclude_core'] );

	$args    = $public_only ? [ 'public' => true ] : [];
	$objects = get_taxonomies( $args, 'objects' );

	$rows = [];
	foreach ( $objects as $slug => $tax ) {
		if ( $exclude_core && ! empty( $tax->_builtin ) ) {
			continue;
		}
		$rows[] = [
			'slug'           => (string) $slug,
			'label'          => (string) $tax->label,
			'label_singular' => isset( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : (string) $tax->label,
			'hierarchical'   => (bool) $tax->hierarchical,
			'public'         => (bool) $tax->public,
			'show_in_rest'   => (bool) $tax->show_in_rest,
			'post_types'     => (array) $tax->object_type,
			'is_core'        => (bool) ( $tax->_builtin ?? false ),
		];
	}

	return [ 'taxonomies' => $rows ];
}
