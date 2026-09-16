<?php
/**
 * E2M Connect MCP - Delete Custom Post
 *
 * Works across any post type. Soft-deletes to Trash unless force=true, in
 * which case the post and its meta are removed permanently via wp_delete_post.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-custom-post', [
	'label'       => __( '[CPT] Delete Post', 'e2mconnect' ),
	'description' => 'Deletes an entry from any post type. Defaults to trash; force=true removes permanently.',
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
			'type'    => [ 'type' => 'string' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_custom_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Custom Post',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-custom-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_custom_post_ability( array $input ) {
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
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete this entry.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, $force );
	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete entry.', 'e2mconnect' ) );
	}

	return [
		'post_id' => $post_id,
		'type'    => (string) $post->post_type,
		'state'   => $force ? 'deleted' : 'trashed',
	];
}
