<?php
/**
 * E2M Connect MCP - List Custom Posts
 *
 * Lists entries from any registered post type. Use list-post-types first to
 * discover valid slugs. Supports search, status, meta-key filters, pagination
 * and ordering so MCP agents can drill into CPT-backed content.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-custom-posts', [
	'label'       => __( '[CPT] List Posts', 'e2mconnect' ),
	'description' => 'Lists entries from any registered post type (built-in or custom) with search, status, meta filter, pagination and ordering.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_type' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Post type slug (e.g. "product", "portfolio").' ],
			'status'    => [ 'type' => 'string', 'default' => 'any' ],
			'search'    => [ 'type' => 'string' ],
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'   => [ 'type' => 'string', 'description' => 'Optional meta key filter (paired with meta_value).' ],
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => [ 'type' => 'string', 'description' => 'Meta value to match against meta_key.' ],
			'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'   => [ 'type' => 'string', 'default' => 'date' ],
			'order'     => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'required'             => [ 'post_type' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'items'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'slug'      => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'type'      => [ 'type' => 'string' ],
						'modified'  => [ 'type' => 'string' ],
						'permalink' => [ 'type' => 'string' ],
						'edit_url'  => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_custom_posts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Custom Posts',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-custom-posts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_list_custom_posts_ability( array $input ) {
	$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
	if ( $post_type === '' || ! post_type_exists( $post_type ) ) {
		return new WP_Error( 'invalid_post_type', __( 'Unknown post_type. Call list-post-types to discover valid slugs.', 'e2mconnect' ) );
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'post_type'      => $post_type,
		'post_status'    => ( isset( $input['status'] ) && $input['status'] !== 'any' ) ? sanitize_key( (string) $input['status'] ) : 'any',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'date',
		'order'          => ( isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
		'no_found_rows'  => false,
	];

	if ( isset( $input['search'] ) && (string) $input['search'] !== '' ) {
		$args['s'] = sanitize_text_field( (string) $input['search'] );
	}
	if ( ! empty( $input['meta_key'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$args['meta_key'] = sanitize_key( (string) $input['meta_key'] );
		if ( isset( $input['meta_value'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$args['meta_value'] = sanitize_text_field( (string) $input['meta_value'] );
		}
	}

	$query = new WP_Query( $args );

	$rows = [];
	foreach ( $query->posts as $post ) {
		$rows[] = [
			'post_id'   => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'slug'      => (string) $post->post_name,
			'status'    => (string) $post->post_status,
			'type'      => (string) $post->post_type,
			'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
			'permalink' => (string) get_permalink( $post ),
			'edit_url'  => (string) get_edit_post_link( $post->ID, 'raw' ),
		];
	}

	return [
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'items'    => $rows,
	];
}
