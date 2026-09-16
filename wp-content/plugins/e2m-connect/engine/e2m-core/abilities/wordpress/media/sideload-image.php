<?php
/**
 * E2M Connect MCP - Sideload Image
 *
 * Downloads a remote image by URL and imports it into the Media Library. Ideal
 * for agents that have picked an image from an external source and need the
 * file to live on the WordPress host for long-term availability.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/sideload-image', [
	'label'       => __( '[Media] Sideload Image', 'e2mconnect' ),
	'description' => 'Downloads an external image URL and imports it into the Media Library. Optionally attaches it to a post.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'url'       => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Source URL to download.' ],
			'attach_to' => [ 'type' => 'integer', 'minimum' => 0 ],
			'alt'       => [ 'type' => 'string' ],
			'title'     => [ 'type' => 'string' ],
			'caption'   => [ 'type' => 'string' ],
		],
		'required'             => [ 'url' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer' ],
			'url'           => [ 'type' => 'string' ],
			'source_url'    => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_sideload_image_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Sideload Image',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the sideload-image ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_sideload_image_ability( array $input ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload files.', 'e2mconnect' ) );
	}

	$source_url = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
	if ( $source_url === '' ) {
		return new WP_Error( 'invalid_url', __( 'A valid source URL is required.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp_file = download_url( $source_url, 30 );
	if ( is_wp_error( $tmp_file ) ) {
		return $tmp_file;
	}

	$file_array = [
		'name'     => basename( wp_parse_url( $source_url, PHP_URL_PATH ) ?: 'sideload' ),
		'tmp_name' => $tmp_file,
	];

	$attach_to = isset( $input['attach_to'] ) ? (int) $input['attach_to'] : 0;
	$title     = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

	$attachment_id = media_handle_sideload(
		$file_array,
		$attach_to,
		$title !== '' ? $title : null
	);

	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}
		return $attachment_id;
	}

	if ( isset( $input['alt'] ) ) {
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	if ( isset( $input['caption'] ) ) {
		wp_update_post(
			[
				'ID'           => (int) $attachment_id,
				'post_excerpt' => wp_kses_post( (string) $input['caption'] ),
			]
		);
	}

	return [
		'attachment_id' => (int) $attachment_id,
		'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
		'source_url'    => $source_url,
	];
}
