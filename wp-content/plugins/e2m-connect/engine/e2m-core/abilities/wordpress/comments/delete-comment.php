<?php
/**
 * E2M Connect MCP - Delete Comment
 *
 * Sends a comment to Trash or permanently deletes it. Uses WordPress' native
 * wp_delete_comment() which cascades to child comments and wipes related
 * meta in the same transaction.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-comment', [
	'label'       => __( '[Comment] Delete Comment', 'e2mconnect' ),
	'description' => 'Deletes a comment (default: trash). Pass force=true to permanently remove it.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'force'      => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'comment_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'comment_id' => [ 'type' => 'integer' ],
			'state'      => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_comment_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Comment',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-comment ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_comment_ability( array $input ) {
	$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
	$force      = ! empty( $input['force'] );

	if ( $comment_id <= 0 ) {
		return new WP_Error( 'invalid_comment_id', __( 'A valid comment_id is required.', 'e2mconnect' ) );
	}

	if ( ! get_comment( $comment_id ) ) {
		return new WP_Error( 'comment_not_found', __( 'Comment not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete this comment.', 'e2mconnect' ) );
	}

	$result = wp_delete_comment( $comment_id, $force );
	if ( $result === false ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete comment.', 'e2mconnect' ) );
	}

	return [
		'comment_id' => $comment_id,
		'state'      => $force ? 'deleted' : 'trashed',
	];
}
