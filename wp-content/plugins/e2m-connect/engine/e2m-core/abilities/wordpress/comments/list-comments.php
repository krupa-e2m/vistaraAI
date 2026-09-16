<?php
/**
 * E2M Connect MCP - List Comments
 *
 * Paginated listing of comments with filters for status, target post, and
 * author email. Returns the fields agents typically need for moderation
 * workflows: author, text preview, status, parent relationship.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-comments', [
	'label'       => __( '[Comment] List Comments', 'e2mconnect' ),
	'description' => 'Lists comments with pagination and filters (status, post_id, author email, search).',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'status'       => [ 'type' => 'string', 'enum' => [ 'all', 'approve', 'hold', 'spam', 'trash' ], 'default' => 'approve' ],
			'post_id'      => [ 'type' => 'integer', 'minimum' => 0 ],
			'author_email' => [ 'type' => 'string' ],
			'search'       => [ 'type' => 'string' ],
			'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'orderby'      => [ 'type' => 'string', 'enum' => [ 'comment_date', 'comment_date_gmt', 'comment_ID' ], 'default' => 'comment_date_gmt' ],
			'order'        => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'comments' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'comment_id'   => [ 'type' => 'integer' ],
						'post_id'      => [ 'type' => 'integer' ],
						'parent_id'    => [ 'type' => 'integer' ],
						'status'       => [ 'type' => 'string' ],
						'author'       => [ 'type' => 'string' ],
						'author_email' => [ 'type' => 'string' ],
						'author_url'   => [ 'type' => 'string' ],
						'content'      => [ 'type' => 'string' ],
						'date'         => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_list_comments_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Comments',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the list-comments ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_list_comments_ability( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'approve';

	$args = [
		'status'  => $status === 'all' ? '' : $status,
		'number'  => $per_page,
		'offset'  => ( $page - 1 ) * $per_page,
		'orderby' => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'comment_date_gmt',
		'order'   => ( isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
	];

	if ( ! empty( $input['post_id'] ) ) {
		$args['post_id'] = (int) $input['post_id'];
	}
	if ( ! empty( $input['author_email'] ) ) {
		$args['author_email'] = sanitize_email( (string) $input['author_email'] );
	}
	if ( ! empty( $input['search'] ) ) {
		$args['search'] = sanitize_text_field( (string) $input['search'] );
	}

	$count_args          = $args;
	$count_args['count'] = true;
	unset( $count_args['number'], $count_args['offset'] );
	$total = (int) get_comments( $count_args );

	$rows = [];
	foreach ( (array) get_comments( $args ) as $comment ) {
		$rows[] = [
			'comment_id'   => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'parent_id'    => (int) $comment->comment_parent,
			'status'       => (string) wp_get_comment_status( $comment->comment_ID ),
			'author'       => (string) $comment->comment_author,
			'author_email' => (string) $comment->comment_author_email,
			'author_url'   => (string) $comment->comment_author_url,
			'content'      => (string) $comment->comment_content,
			'date'         => mysql2date( 'c', $comment->comment_date_gmt, false ),
		];
	}

	return [
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'comments' => $rows,
	];
}
