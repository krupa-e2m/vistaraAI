<?php
/**
 * E2M Connect MCP - List Terms
 *
 * Lists terms within a taxonomy with search, hide_empty and hierarchy-aware
 * filters. Supports pagination for taxonomies with many terms (e.g. product
 * attributes, tags on content-heavy sites).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-terms', [
	'label'       => __( '[Taxonomy] List Terms', 'e2mconnect' ),
	'description' => 'Lists terms from a taxonomy with search, hide_empty, parent filter, and pagination.',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'taxonomy'   => [ 'type' => 'string', 'minLength' => 1 ],
			'search'     => [ 'type' => 'string' ],
			'parent'     => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Parent term ID for hierarchical taxonomies.' ],
			'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
			'per_page'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ],
			'page'       => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'    => [ 'type' => 'string', 'enum' => [ 'name', 'count', 'term_id', 'slug' ], 'default' => 'name' ],
			'order'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'ASC' ],
		],
		'required'             => [ 'taxonomy' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'terms'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'term_id'     => [ 'type' => 'integer' ],
						'name'        => [ 'type' => 'string' ],
						'slug'        => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'parent'      => [ 'type' => 'integer' ],
						'count'       => [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_terms_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Terms',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-terms ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_terms_ability( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', __( 'Unknown taxonomy.', 'e2mconnect' ) );
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 50;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'taxonomy'   => $taxonomy,
		'hide_empty' => ! empty( $input['hide_empty'] ),
		'number'     => $per_page,
		'offset'     => ( $page - 1 ) * $per_page,
		'orderby'    => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'name',
		'order'      => ( isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'DESC' ) ? 'DESC' : 'ASC',
	];

	if ( ! empty( $input['search'] ) ) {
		$args['search'] = sanitize_text_field( (string) $input['search'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['parent'] = (int) $input['parent'];
	}

	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	$count_args           = $args;
	$count_args['fields'] = 'count';
	unset( $count_args['number'], $count_args['offset'] );
	$total = (int) get_terms( $count_args );

	$rows = [];
	foreach ( (array) $terms as $term ) {
		$rows[] = [
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		];
	}

	return [
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'terms'    => $rows,
	];
}
