<?php
/**
 * E2M Connect MCP - Get Media
 *
 * Returns a single attachment with caption, description, alt text, and every
 * registered image size (URL + dimensions) so agents can choose the right
 * rendition for the use case at hand.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-media', [
	'label'       => __( '[Media] Get Media', 'e2mconnect' ),
	'description' => 'Retrieves a single attachment including alt text, caption, description, and all registered image sizes.',
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
			'title'         => [ 'type' => 'string' ],
			'caption'       => [ 'type' => 'string' ],
			'description'   => [ 'type' => 'string' ],
			'alt'           => [ 'type' => 'string' ],
			'mime_type'     => [ 'type' => 'string' ],
			'url'           => [ 'type' => 'string' ],
			'filesize'      => [ 'type' => 'integer' ],
			'width'         => [ 'type' => 'integer' ],
			'height'        => [ 'type' => 'integer' ],
			'sizes'         => [
				'type'                 => 'object',
				'additionalProperties' => [
					'type'       => 'object',
					'properties' => [
						'url'    => [ 'type' => 'string' ],
						'width'  => [ 'type' => 'integer' ],
						'height' => [ 'type' => 'integer' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_get_media_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Media',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-media ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_media_ability( array $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	if ( $attachment_id <= 0 ) {
		return new WP_Error( 'invalid_attachment_id', __( 'A valid attachment_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return new WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'e2mconnect' ) );
	}

	$meta  = wp_get_attachment_metadata( $attachment_id );
	$sizes = [];

	if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size_name => $size_info ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size_name );
			if ( is_array( $src ) ) {
				$sizes[ $size_name ] = [
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				];
			}
		}
	}

	return [
		'attachment_id' => $attachment_id,
		'title'         => get_the_title( $post ),
		'caption'       => (string) $post->post_excerpt,
		'description'   => (string) $post->post_content,
		'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'mime_type'     => (string) $post->post_mime_type,
		'url'           => (string) wp_get_attachment_url( $attachment_id ),
		'filesize'      => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0,
		'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		'sizes'         => $sizes,
	];
}
