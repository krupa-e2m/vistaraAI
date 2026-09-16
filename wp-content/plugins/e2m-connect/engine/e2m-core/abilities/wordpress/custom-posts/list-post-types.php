<?php
/**
 * E2M Connect MCP - List Post Types
 *
 * Enumerates every registered post type on the site (built-in + custom),
 * filtered by visibility when requested. MCP clients call this first when
 * they need to discover what content vocabulary a site exposes.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-post-types', [
	'label'       => __( '[CPT] List Post Types', 'e2mconnect' ),
	'description' => 'Lists all registered post types (built-in and custom), including labels, visibility flags, and supported features.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'public_only' => [
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'When true, returns only publicly visible post types.',
			],
			'exclude_core' => [
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'When true, skips WordPress built-in types (post, page, attachment, etc.).',
			],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_types' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'slug'            => [ 'type' => 'string' ],
						'label'           => [ 'type' => 'string' ],
						'label_singular'  => [ 'type' => 'string' ],
						'public'          => [ 'type' => 'boolean' ],
						'show_in_rest'    => [ 'type' => 'boolean' ],
						'hierarchical'    => [ 'type' => 'boolean' ],
						'has_archive'     => [ 'type' => 'boolean' ],
						'rest_base'       => [ 'type' => 'string' ],
						'supports'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'taxonomies'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'is_core'         => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_post_types_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Post Types',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-post-types ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_post_types_ability( array $input ): array {
	$public_only  = ! empty( $input['public_only'] );
	$exclude_core = ! empty( $input['exclude_core'] );

	$args    = $public_only ? [ 'public' => true ] : [];
	$objects = get_post_types( $args, 'objects' );

	$rows = [];
	foreach ( $objects as $slug => $type ) {
		if ( $exclude_core && ! empty( $type->_builtin ) ) {
			continue;
		}

		$rows[] = [
			'slug'           => (string) $slug,
			'label'          => (string) $type->label,
			'label_singular' => isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : (string) $type->label,
			'public'         => (bool) $type->public,
			'show_in_rest'   => (bool) $type->show_in_rest,
			'hierarchical'   => (bool) $type->hierarchical,
			'has_archive'    => (bool) $type->has_archive,
			'rest_base'      => (string) ( $type->rest_base !== false ? $type->rest_base : $slug ),
			'supports'       => array_keys( get_all_post_type_supports( $slug ) ),
			'taxonomies'     => get_object_taxonomies( $slug ),
			'is_core'        => (bool) ( $type->_builtin ?? false ),
		];
	}

	return [ 'post_types' => $rows ];
}
