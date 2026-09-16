<?php
/**
 * E2M Connect MCP - Elementor List Pages
 *
 * Returns only the posts (any post type) that have Elementor content.
 * Filters by _elementor_edit_mode=builder meta, which Elementor sets as
 * soon as a post is edited with the builder at least once.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-list-pages', [
	'label'       => __( '[Elementor] List Pages', 'e2mconnect' ),
	'description' => 'Lists posts that are authored with Elementor. Filter by post_type, status, and pagination.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_type' => [ 'type' => 'string', 'default' => 'any' ],
			'status'    => [ 'type' => 'string', 'default' => 'any' ],
			'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'pages'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'type'     => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'modified'  => [ 'type' => 'string' ],
						'permalink' => [ 'type' => 'string' ],
						'edit_url'  => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_list_pages_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: List Pages',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-list-pages ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_list_pages_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$query = new WP_Query(
		[
			'post_type'      => isset( $input['post_type'] ) && $input['post_type'] !== 'any' ? sanitize_key( (string) $input['post_type'] ) : 'any',
			'post_status'    => isset( $input['status'] ) && $input['status'] !== 'any' ? sanitize_key( (string) $input['status'] ) : 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'       => '_elementor_edit_mode',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => 'builder',
			'no_found_rows'  => false,
		]
	);

	$rows = [];
	foreach ( $query->posts as $post ) {
		$rows[] = [
			'post_id'   => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'type'      => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
			'permalink' => (string) get_permalink( $post ),
			'edit_url'  => (string) get_edit_post_link( $post->ID, 'raw' ),
		];
	}

	return [
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'pages'    => $rows,
	];
}
