<?php
/**
 * E2M Connect MCP - Get Comment
 *
 * Retrieves a single comment by ID with the full author payload and moderation
 * state. Agents use this before running moderation actions so they can show
 * the user the exact content under review.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-comment', [
	'label'       => __( '[Comment] Get Comment', 'e2mconnect' ),
	'description' => 'Retrieves a single comment by comment_id.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'comment_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id'   => [ 'type' => 'integer' ],
			'post_id'      => [ 'type' => 'integer' ],
			'parent_id'    => [ 'type' => 'integer' ],
			'status'       => [ 'type' => 'string' ],
			'author'       => [ 'type' => 'string' ],
			'author_email' => [ 'type' => 'string' ],
			'author_url'   => [ 'type' => 'string' ],
			'author_ip'    => [ 'type' => 'string' ],
			'user_id'      => [ 'type' => 'integer' ],
			'content'      => [ 'type' => 'string' ],
			'date'         => [ 'type' => 'string' ],
			'agent'        => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_comment_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Comment',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-comment ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_comment_ability( array $input ) {
	$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
	if ( $comment_id <= 0 ) {
		return new WP_Error( 'invalid_comment_id', __( 'A valid comment_id is required.', 'e2mconnect' ) );
	}

	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return new WP_Error( 'comment_not_found', __( 'Comment not found.', 'e2mconnect' ) );
	}

	return [
		'comment_id'   => (int) $comment->comment_ID,
		'post_id'      => (int) $comment->comment_post_ID,
		'parent_id'    => (int) $comment->comment_parent,
		'status'       => (string) wp_get_comment_status( $comment->comment_ID ),
		'author'       => (string) $comment->comment_author,
		'author_email' => (string) $comment->comment_author_email,
		'author_url'   => (string) $comment->comment_author_url,
		'author_ip'    => (string) $comment->comment_author_IP,
		'user_id'      => (int) $comment->user_id,
		'content'      => (string) $comment->comment_content,
		'date'         => mysql2date( 'c', $comment->comment_date_gmt, false ),
		'agent'        => (string) $comment->comment_agent,
	];
}
