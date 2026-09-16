<?php
/**
 * E2M Connect MCP - Update Media
 *
 * Edits metadata on an attachment: title, caption, description, alt text.
 * Does not re-encode or replace the underlying file; use upload-media for
 * that scenario.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-media', [
	'label'       => __( '[Media] Update Media', 'e2mconnect' ),
	'description' => 'Updates attachment metadata (title, caption, description, alt text). Does not change the underlying file.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'title'         => [ 'type' => 'string' ],
			'caption'       => [ 'type' => 'string' ],
			'description'   => [ 'type' => 'string' ],
			'alt'           => [ 'type' => 'string' ],
		],
		'required'             => [ 'attachment_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id'  => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_media_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Media',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-media ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_media_ability( array $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	if ( $attachment_id <= 0 ) {
		return new WP_Error( 'invalid_attachment_id', __( 'A valid attachment_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return new WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'e2mconnect' ) );
	}

	$patch   = [ 'ID' => $attachment_id ];
	$touched = [];

	if ( array_key_exists( 'title', $input ) ) {
		$patch['post_title'] = sanitize_text_field( (string) $input['title'] );
		$touched[]           = 'title';
	}
	if ( array_key_exists( 'caption', $input ) ) {
		$patch['post_excerpt'] = wp_kses_post( (string) $input['caption'] );
		$touched[]             = 'caption';
	}
	if ( array_key_exists( 'description', $input ) ) {
		$patch['post_content'] = wp_kses_post( (string) $input['description'] );
		$touched[]             = 'description';
	}

	if ( count( $patch ) > 1 ) {
		$res = wp_update_post( $patch, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}

	if ( array_key_exists( 'alt', $input ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		$touched[] = 'alt';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	return [
		'attachment_id'  => $attachment_id,
		'updated_fields' => $touched,
	];
}
