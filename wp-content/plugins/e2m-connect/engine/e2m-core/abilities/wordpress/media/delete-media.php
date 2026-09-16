<?php
/**
 * E2M Connect MCP - Delete Media
 *
 * Permanently removes an attachment including its sized derivatives, the
 * original file on disk, and any database references. There is no "trash"
 * state for attachments in WordPress core, so force is implicit.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-media', [
	'label'       => __( '[Media] Delete Media', 'e2mconnect' ),
	'description' => 'Permanently deletes an attachment and all of its image sizes from the Media Library.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'attachment_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer' ],
			'state'         => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_media_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Media',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-media ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_media_ability( array $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	if ( $attachment_id <= 0 ) {
		return new WP_Error( 'invalid_attachment_id', __( 'A valid attachment_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return new WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete this attachment.', 'e2mconnect' ) );
	}

	$result = wp_delete_attachment( $attachment_id, true );
	if ( $result === false || $result === null ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete attachment.', 'e2mconnect' ) );
	}

	return [
		'attachment_id' => $attachment_id,
		'state'         => 'deleted',
	];
}
