<?php
/**
 * E2M Connect MCP - Update Comment
 *
 * Partial update for comment fields plus moderation state transitions
 * (approve / hold / spam / trash). Status changes are applied after field
 * edits so the returned payload reflects the final state.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-comment', [
	'label'       => __( '[Comment] Update Comment', 'e2mconnect' ),
	'description' => 'Updates comment fields and/or moderation status (approve, hold, spam, trash).',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
			'content'      => [ 'type' => 'string' ],
			'author'       => [ 'type' => 'string' ],
			'author_email' => [ 'type' => 'string' ],
			'author_url'   => [ 'type' => 'string' ],
			'status'       => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash' ] ],
		],
		'required'             => [ 'comment_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id'     => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'status'         => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_comment_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Comment',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-comment ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_comment_ability( array $input ) {
	$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
	if ( $comment_id <= 0 ) {
		return new WP_Error( 'invalid_comment_id', __( 'A valid comment_id is required.', 'e2mconnect' ) );
	}

	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return new WP_Error( 'comment_not_found', __( 'Comment not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this comment.', 'e2mconnect' ) );
	}

	$patch   = [ 'comment_ID' => $comment_id ];
	$touched = [];

	if ( array_key_exists( 'content', $input ) ) {
		$patch['comment_content'] = wp_kses_post( (string) $input['content'] );
		$touched[]                = 'content';
	}
	if ( array_key_exists( 'author', $input ) ) {
		$patch['comment_author'] = sanitize_text_field( (string) $input['author'] );
		$touched[]               = 'author';
	}
	if ( array_key_exists( 'author_email', $input ) ) {
		$patch['comment_author_email'] = sanitize_email( (string) $input['author_email'] );
		$touched[]                     = 'author_email';
	}
	if ( array_key_exists( 'author_url', $input ) ) {
		$patch['comment_author_url'] = esc_url_raw( (string) $input['author_url'] );
		$touched[]                   = 'author_url';
	}

	if ( count( $patch ) > 1 ) {
		wp_update_comment( wp_slash( $patch ) );
	}

	$final_status = wp_get_comment_status( $comment_id );

	if ( array_key_exists( 'status', $input ) ) {
		$status = sanitize_key( (string) $input['status'] );
		wp_set_comment_status( $comment_id, $status );
		$final_status = $status;
		$touched[]    = 'status';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	return [
		'comment_id'     => $comment_id,
		'updated_fields' => $touched,
		'status'         => (string) $final_status,
	];
}
