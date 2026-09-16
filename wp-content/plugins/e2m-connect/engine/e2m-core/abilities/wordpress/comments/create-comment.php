<?php
/**
 * E2M Connect MCP - Create Comment
 *
 * Creates a comment against any post, supporting nested replies via parent_id.
 * When the current user is logged in, author details are auto-filled; for
 * anonymous comments the caller must supply author name/email.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-comment', [
	'label'       => __( '[Comment] Create Comment', 'e2mconnect' ),
	'description' => 'Creates a new comment on a post. Supports threaded replies via parent_id.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
			'content'      => [ 'type' => 'string', 'minLength' => 1 ],
			'parent_id'    => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
			'author'       => [ 'type' => 'string' ],
			'author_email' => [ 'type' => 'string' ],
			'author_url'   => [ 'type' => 'string' ],
			'status'       => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam' ], 'default' => 'approve' ],
		],
		'required'             => [ 'post_id', 'content' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id' => [ 'type' => 'integer' ],
			'status'     => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_comment_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Comment',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-comment ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_comment_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$content = isset( $input['content'] ) ? trim( wp_kses_post( (string) $input['content'] ) ) : '';

	if ( $post_id <= 0 || $content === '' ) {
		return new WP_Error( 'invalid_input', __( 'post_id and non-empty content are required.', 'e2mconnect' ) );
	}

	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'post_not_found', __( 'Target post not found.', 'e2mconnect' ) );
	}

	$user        = wp_get_current_user();
	$author      = isset( $input['author'] ) ? sanitize_text_field( (string) $input['author'] ) : ( $user->ID ? (string) $user->display_name : '' );
	$email       = isset( $input['author_email'] ) ? sanitize_email( (string) $input['author_email'] ) : ( $user->ID ? (string) $user->user_email : '' );
	$url         = isset( $input['author_url'] ) ? esc_url_raw( (string) $input['author_url'] ) : '';
	$parent      = isset( $input['parent_id'] ) ? (int) $input['parent_id'] : 0;
	$status      = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'approve';
	$approved    = match ( $status ) {
		'hold'  => 0,
		'spam'  => 'spam',
		default => 1,
	};

	$data = [
		'comment_post_ID'      => $post_id,
		'comment_content'      => $content,
		'comment_parent'       => $parent,
		'comment_author'       => $author,
		'comment_author_email' => $email,
		'comment_author_url'   => $url,
		'comment_approved'     => $approved,
		'user_id'              => (int) $user->ID,
	];

	$comment_id = wp_insert_comment( wp_slash( $data ) );
	if ( ! $comment_id ) {
		return new WP_Error( 'create_failed', __( 'Unable to create comment.', 'e2mconnect' ) );
	}

	return [
		'comment_id' => (int) $comment_id,
		'status'     => $status,
	];
}
