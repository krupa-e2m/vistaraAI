<?php
/**
 * E2M Connect MCP - Find Media By Hash
 *
 * Looks up an existing Media Library attachment by its stored content hash
 * (the `_e2m_sha1` post meta stamped by the upload-media ability). Lets callers
 * deduplicate uploads by checking whether a file with the same sha1 is already
 * present before sending the bytes over the wire.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/find-media-by-hash', [
	'label'       => __( '[Media] Find Media By Hash', 'e2mconnect' ),
	'description' => 'Finds an existing Media Library attachment by its sha1 content hash and returns the attachment id and URL if present.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'sha1' => [ 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'description' => 'SHA-1 hex digest (40 chars) of the file content to look up.' ],
		],
		'required'             => [ 'sha1' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'found'         => [ 'type' => 'boolean' ],
			'attachment_id' => [ 'type' => 'integer' ],
			'url'           => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_find_media_by_hash_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Find Media By Hash',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the find-media-by-hash ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_find_media_by_hash_ability( array $input ) {
	$sha1 = isset( $input['sha1'] ) ? sanitize_text_field( (string) $input['sha1'] ) : '';

	if ( $sha1 === '' ) {
		return new WP_Error( 'invalid_input', __( 'sha1 is required.', 'e2mconnect' ) );
	}

	$query = new WP_Query( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'fields'         => 'ids',
		'meta_key'       => '_e2m_sha1',
		'meta_value'     => $sha1,
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	] );

	if ( empty( $query->posts ) ) {
		return [ 'found' => false ];
	}

	$attach_id = (int) $query->posts[0];

	return [
		'found'         => true,
		'attachment_id' => $attach_id,
		'url'           => (string) wp_get_attachment_url( $attach_id ),
	];
}
