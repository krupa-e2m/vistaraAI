<?php
/**
 * E2M Connect MCP - List Posts
 *
 * Paginated listing of blog posts with search, status, author, category and
 * tag filters. Returns light-weight rows tuned for MCP clients that typically
 * just need "show me what posts exist" before drilling into one.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-posts', [
	'label'       => __( '[Post] List Posts', 'e2mconnect' ),
	'description' => 'Lists WordPress posts with pagination and filters (status, search, author, category, tag).',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'status'   => [
				'type'    => 'string',
				'enum'    => [ 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
				'default' => 'any',
			],
			'search'   => [ 'type' => 'string', 'description' => 'Search term.' ],
			'author'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Filter by author ID.' ],
			'category' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Filter by category term ID.' ],
			'tag'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Filter by tag term ID.' ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'  => [ 'type' => 'string', 'enum' => [ 'date', 'modified', 'title', 'ID', 'comment_count' ], 'default' => 'date' ],
			'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'posts'    => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'slug'      => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'author_id' => [ 'type' => 'integer' ],
						'date'      => [ 'type' => 'string' ],
						'modified'  => [ 'type' => 'string' ],
						'excerpt'   => [ 'type' => 'string' ],
						'permalink' => [ 'type' => 'string' ],
						'edit_url'  => [ 'type' => 'string' ],
						'categories'=> [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'tags'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_posts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'List Posts',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-posts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_posts_ability( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'post_type'      => 'post',
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
	if ( ! empty( $input['author'] ) ) {
		$args['author'] = (int) $input['author'];
	}
	if ( ! empty( $input['category'] ) ) {
		$args['cat'] = (int) $input['category'];
	}
	if ( ! empty( $input['tag'] ) ) {
		$args['tag_id'] = (int) $input['tag'];
	}

	$query = new WP_Query( $args );

	$rows = [];
	foreach ( $query->posts as $post ) {
		$rows[] = [
			'post_id'    => (int) $post->ID,
			'title'      => get_the_title( $post ),
			'slug'       => (string) $post->post_name,
			'status'     => (string) $post->post_status,
			'author_id'  => (int) $post->post_author,
			'date'       => mysql2date( 'c', $post->post_date_gmt, false ),
			'modified'   => mysql2date( 'c', $post->post_modified_gmt, false ),
			'excerpt'    => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
			'permalink'  => (string) get_permalink( $post ),
			'edit_url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'categories' => array_map( 'intval', wp_get_post_categories( $post->ID ) ),
			'tags'       => array_map( 'intval', wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] ) ),
		];
	}

	return [
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'posts'    => $rows,
	];
}
