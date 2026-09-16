<?php
/**
 * E2M Connect MCP - Upload Media
 *
 * Accepts a file as a base64-encoded payload (for binary safety in JSON) and
 * writes it into the Media Library via WordPress' upload machinery. The
 * filename is used to drive MIME detection and the attachment post title.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/upload-media', [
	'label'       => __( '[Media] Upload Media', 'e2mconnect' ),
	'description' => 'Uploads a base64-encoded file into the WordPress Media Library and returns the attachment record.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'filename'  => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Desired filename with extension (e.g. "hero.jpg").' ],
			'data'      => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Base64-encoded binary content.' ],
			'alt'       => [ 'type' => 'string' ],
			'title'     => [ 'type' => 'string' ],
			'caption'   => [ 'type' => 'string' ],
			'attach_to' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Optional post/page ID to attach to.' ],
		],
		'required'             => [ 'filename', 'data' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer' ],
			'url'           => [ 'type' => 'string' ],
			'mime_type'     => [ 'type' => 'string' ],
			'filesize'      => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_upload_media_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Upload Media',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the upload-media ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_upload_media_ability( array $input ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload files.', 'e2mconnect' ) );
	}

	$filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
	$payload  = isset( $input['data'] ) ? (string) $input['data'] : '';

	if ( $filename === '' || $payload === '' ) {
		return new WP_Error( 'invalid_input', __( 'filename and data are required.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MCP payloads are explicit base64 envelopes.
	$binary = base64_decode( $payload, true );
	if ( $binary === false ) {
		return new WP_Error( 'invalid_base64', __( 'data is not valid base64.', 'e2mconnect' ) );
	}

	$file_type = wp_check_filetype( $filename );
	if ( empty( $file_type['ext'] ) ) {
		return new WP_Error( 'invalid_file_type', __( 'The file extension is not allowed by WordPress.', 'e2mconnect' ) );
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'upload_dir_error', (string) $uploads['error'] );
	}

	$target_path = trailingslashit( (string) $uploads['path'] ) . wp_unique_filename( (string) $uploads['path'], $filename );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem(); // Initialise the WP filesystem abstraction layer (provided by file.php).

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write into uploads dir.
	$written = file_put_contents( $target_path, $binary );
	if ( $written === false ) {
		return new WP_Error( 'write_failed', __( 'Failed to write upload to disk.', 'e2mconnect' ) );
	}

	$attach_id = wp_insert_attachment(
		[
			'post_mime_type' => (string) $file_type['type'],
			'post_title'     => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : pathinfo( $filename, PATHINFO_FILENAME ),
			'post_excerpt'   => isset( $input['caption'] ) ? wp_kses_post( (string) $input['caption'] ) : '',
			'post_status'    => 'inherit',
			'post_content'   => '',
		],
		$target_path,
		isset( $input['attach_to'] ) ? (int) $input['attach_to'] : 0,
		true
	);

	if ( is_wp_error( $attach_id ) ) {
		return $attach_id;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attach_meta = wp_generate_attachment_metadata( (int) $attach_id, $target_path ); // Uses wp_generate_attachment_metadata() from image.php.
	wp_update_attachment_metadata( (int) $attach_id, $attach_meta );

	if ( isset( $input['alt'] ) ) {
		update_post_meta( (int) $attach_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	// Stamp the content hash so e2m/find-media-by-hash lookups can dedupe future uploads.
	update_post_meta( (int) $attach_id, '_e2m_sha1', sha1_file( $target_path ) );

	return [
		'attachment_id' => (int) $attach_id,
		'url'           => (string) wp_get_attachment_url( (int) $attach_id ),
		'mime_type'     => (string) $file_type['type'],
		'filesize'      => (int) $written,
	];
}
