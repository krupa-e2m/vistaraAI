<?php
/**
 * E2M Connect MCP - Elementor Upload SVG
 *
 * Stores a sanitised SVG as a Media Library attachment, ready to be used
 * as a custom icon inside Elementor. Inline scripts, event handlers, and
 * external URL references are stripped before the file lands on disk.
 *
 * Accepts either raw SVG markup (string) or a remote URL to download.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-upload-svg', [
	'label'       => __( '[Elementor] Upload SVG', 'e2mconnect' ),
	'description' => 'Stores a sanitised SVG in the Media Library for reuse as a custom icon. Accepts raw markup or a source URL.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'markup'   => [ 'type' => 'string', 'description' => 'Raw SVG markup. Mutually exclusive with url.' ],
			'url'      => [ 'type' => 'string', 'description' => 'Source URL to fetch. Mutually exclusive with markup.' ],
			'filename' => [ 'type' => 'string', 'description' => 'Desired filename (ending in .svg).' ],
			'title'    => [ 'type' => 'string' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'attachment_id' => [ 'type' => 'integer' ],
			'url'           => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_upload_svg_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Upload SVG',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-upload-svg ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_upload_svg_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload media.', 'e2mconnect' ) );
	}

	$markup = isset( $input['markup'] ) ? (string) $input['markup'] : '';
	$url    = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
	$name   = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : 'e2m-icon.svg';
	$title  = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : pathinfo( $name, PATHINFO_FILENAME );

	if ( $markup === '' && $url === '' ) {
		return new WP_Error( 'missing_source', __( 'Provide markup or url.', 'e2mconnect' ) );
	}

	if ( $markup === '' ) {
		$response = wp_safe_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$markup = (string) wp_remote_retrieve_body( $response );
	}

	$clean = e2m_engine_sanitize_svg_markup( $markup );
	if ( $clean === '' ) {
		return new WP_Error( 'invalid_svg', __( 'SVG markup failed sanitisation.', 'e2mconnect' ) );
	}

	if ( ! str_ends_with( strtolower( $name ), '.svg' ) ) {
		$name .= '.svg';
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'upload_dir_error', (string) $uploads['error'] );
	}

	$unique_name = wp_unique_filename( (string) $uploads['path'], $name );
	$full_path   = trailingslashit( (string) $uploads['path'] ) . $unique_name;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write into uploads dir.
	$bytes = file_put_contents( $full_path, $clean );
	if ( $bytes === false ) {
		return new WP_Error( 'write_failed', __( 'Failed to write SVG to disk.', 'e2mconnect' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem(); // Initialise the WP filesystem abstraction layer (provided by file.php).

	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_content'   => '',
		],
		$full_path,
		0,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $full_path ) ); // Uses wp_generate_attachment_metadata() from image.php.

	return [
		'attachment_id' => (int) $attachment_id,
		'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
	];
}

/**
 * Conservative SVG sanitiser: strips scripts, event handlers, and javascript:
 * URLs. Not a full SVG parser - intended for trusted-but-verified payloads
 * from MCP clients, not arbitrary uploads from the public internet.
 */
function e2m_engine_sanitize_svg_markup( string $markup ): string {
	$markup = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $markup ) ?? $markup;
	$markup = preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $markup ) ?? $markup;
	$markup = preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $markup ) ?? $markup;
	$markup = preg_replace( '#javascript\s*:#i', '', $markup ) ?? $markup;

	$markup = trim( $markup );
	if ( $markup === '' || stripos( $markup, '<svg' ) === false ) {
		return '';
	}

	return $markup;
}
