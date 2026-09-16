<?php
/**
 * E2M Connect MCP - Delete Post
 *
 * Moves a post to Trash by default, or permanently deletes it when force=true.
 * The final state ("trashed" vs "deleted") is returned so clients can surface
 * accurate confirmation copy.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-post', [
	'label'       => __( '[Post] Delete Post', 'e2mconnect' ),
	'description' => 'Deletes a WordPress post. Defaults to soft delete (trash). Pass force=true to permanently remove.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'force'   => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Post',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$force   = ! empty( $input['force'] );

	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'delete_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete this post.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, $force );
	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete post.', 'e2mconnect' ) );
	}

	return [
		'post_id' => $post_id,
		'state'   => $force ? 'deleted' : 'trashed',
	];
}
