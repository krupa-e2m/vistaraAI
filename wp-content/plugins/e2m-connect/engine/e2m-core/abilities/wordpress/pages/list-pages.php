<?php
/**
 * E2M Connect MCP - List Pages
 *
 * Returns a paginated listing of WordPress pages with key metadata useful to
 * MCP clients: id, title, status, slug, parent, order, and the active page
 * builder detected for the entry.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-pages', [
	'label'       => __( '[Page] List Pages', 'e2mconnect' ),
	'description' => 'Lists WordPress pages with pagination, status and search filters, plus detected page builder for each entry.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'status' => [
				'type'        => 'string',
				'description' => 'Filter by post status. Use "any" for all statuses.',
				'enum'        => [ 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
				'default'     => 'any',
			],
			'search' => [
				'type'        => 'string',
				'description' => 'Search term matched against title and content.',
			],
			'parent' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Restrict to direct children of this parent page ID. 0 = top-level only.',
			],
			'per_page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
				'description' => 'Page size.',
			],
			'page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => 'Page number (1-based).',
			],
			'orderby' => [
				'type'        => 'string',
				'enum'        => [ 'date', 'modified', 'title', 'menu_order', 'ID' ],
				'default'     => 'menu_order',
				'description' => 'Sort field.',
			],
			'order' => [
				'type'        => 'string',
				'enum'        => [ 'ASC', 'DESC' ],
				'default'     => 'ASC',
				'description' => 'Sort direction.',
			],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'     => [ 'type' => 'integer' ],
			'page'      => [ 'type' => 'integer' ],
			'per_page'  => [ 'type' => 'integer' ],
			'pages'     => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'page_id'    => [ 'type' => 'integer' ],
						'title'      => [ 'type' => 'string' ],
						'slug'       => [ 'type' => 'string' ],
						'status'     => [ 'type' => 'string' ],
						'parent'     => [ 'type' => 'integer' ],
						'menu_order' => [ 'type' => 'integer' ],
						'author_id'  => [ 'type' => 'integer' ],
						'modified'   => [ 'type' => 'string' ],
						'permalink'  => [ 'type' => 'string' ],
						'edit_url'   => [ 'type' => 'string' ],
						'builder'    => [ 'type' => 'string', 'description' => 'Detected page builder slug or "classic".' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_pages_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'List Pages',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-pages ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_pages_ability( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
	$orderby  = isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'menu_order';
	$order    = isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'DESC' ? 'DESC' : 'ASC';

	$query_args = [
		'post_type'      => 'page',
		'post_status'    => $status === 'any' ? 'any' : $status,
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => $orderby,
		'order'          => $order,
		'no_found_rows'  => false,
	];

	if ( array_key_exists( 'parent', $input ) ) {
		$query_args['post_parent'] = (int) $input['parent'];
	}

	if ( isset( $input['search'] ) && (string) $input['search'] !== '' ) {
		$query_args['s'] = sanitize_text_field( (string) $input['search'] );
	}

	$query = new WP_Query( $query_args );

	$rows = [];
	foreach ( $query->posts as $post ) {
		$rows[] = [
			'page_id'    => (int) $post->ID,
			'title'      => get_the_title( $post ),
			'slug'       => (string) $post->post_name,
			'status'     => (string) $post->post_status,
			'parent'     => (int) $post->post_parent,
			'menu_order' => (int) $post->menu_order,
			'author_id'  => (int) $post->post_author,
			'modified'   => mysql2date( 'c', $post->post_modified_gmt, false ),
			'permalink'  => (string) get_permalink( $post ),
			'edit_url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'builder'    => e2m_engine_detect_builder_for_post( (int) $post->ID ),
		];
	}

	return [
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'pages'    => $rows,
	];
}

/**
 * Inspect a post and infer which page builder produced its content.
 *
 * Detection is intentionally lightweight: we look for the strongest marker
 * (meta key or content signature) each builder leaves behind.
 */
function e2m_engine_detect_builder_for_post( int $post_id ): string {
	if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
		return 'elementor';
	}

	if ( get_post_meta( $post_id, '_bricks_editor_mode', true ) ) {
		return 'bricks';
	}

	if ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
		return 'divi';
	}

	if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
		return 'beaver';
	}

	$content = (string) get_post_field( 'post_content', $post_id );
	if ( str_contains( $content, '<!-- wp:' ) ) {
		return 'gutenberg';
	}

	return 'classic';
}
