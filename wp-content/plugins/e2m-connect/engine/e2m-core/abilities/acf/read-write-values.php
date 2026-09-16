<?php
/**
 * E2M Connect MCP - ACF Read and Write Values
 *
 * Read and write ACF custom field values on any post, page, term,
 * user, or options page. Uses ACF's native get_field() / update_field()
 * API so all ACF field types (text, image, repeater, relationship,
 * flexible content, etc.) are handled correctly.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/acf-read-write-values', [
	'label'       => __( '[ACF] Read and Write Values', 'e2mconnect' ),
	'description' => 'Read and write ACF custom field values on any post, page, term, user, or options page.',
	'category'    => 'e2m-acf',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'read_all' ],
				'description' => 'read — get a single field; write — update a field value; read_all — get all ACF fields for the object.',
			],
			'post_id' => [
				'type'        => [ 'integer', 'string' ],
				'description' => 'Post ID (integer), term ID as "term_123", user ID as "user_123", or "options" for global options page.',
			],
			'field_key_or_name' => [
				'type'        => 'string',
				'description' => 'ACF field key (e.g. "field_abc123") or field name (e.g. "hero_title"). Required for read and write.',
			],
			'value' => [
				'description' => 'New value to save. Type depends on field type — string for text/textarea, integer for number, array for repeater/relationship/checkbox. Required for write.',
			],
			'format_value' => [
				'type'        => 'boolean',
				'description' => 'Whether to apply ACF value formatting on read (default: true). Set false to get raw database values.',
				'default'     => true,
			],
		],
		'required'             => [ 'action', 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'post_id'  => [ 'type' => [ 'integer', 'string' ] ],
			'field'    => [ 'type' => 'string' ],
			'value'    => [ 'description' => 'The field value — type varies by ACF field type.' ],
			'fields'   => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'All field values keyed by field name (read_all only).' ],
			'updated'  => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_acf_read_write_values_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'ACF: Read and Write Values',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the acf-read-write-values ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_acf_read_write_values_ability( array $input ) {
	if ( ! e2m_engine_has_acf() ) {
		return new WP_Error( 'acf_missing', __( 'Advanced Custom Fields is not installed or activated on this site.', 'e2mconnect' ) );
	}

	$action        = sanitize_key( $input['action'] ?? 'read' );
	$raw_post_id   = $input['post_id'];
	$format_value  = (bool) ( $input['format_value'] ?? true );

	// Resolve post_id — ACF accepts int, "term_123", "user_123", or "options".
	$post_id = e2m_engine_acf_resolve_post_id( $raw_post_id );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Permission check.
	if ( ! e2m_engine_acf_user_can( $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to access ACF fields for this object.', 'e2mconnect' ) );
	}

	if ( $action === 'read_all' ) {
		$all_fields = get_fields( $post_id, $format_value );
		return [
			'action'  => 'read_all',
			'post_id' => $raw_post_id,
			'fields'  => is_array( $all_fields ) ? $all_fields : [],
		];
	}

	$field_key_or_name = sanitize_text_field( $input['field_key_or_name'] ?? '' );
	if ( $field_key_or_name === '' ) {
		return new WP_Error( 'missing_field', __( 'field_key_or_name is required for read and write actions.', 'e2mconnect' ) );
	}

	if ( $action === 'read' ) {
		$value = get_field( $field_key_or_name, $post_id, $format_value );
		return [
			'action'  => 'read',
			'post_id' => $raw_post_id,
			'field'   => $field_key_or_name,
			'value'   => $value,
		];
	}

	if ( $action === 'write' ) {
		if ( ! array_key_exists( 'value', $input ) ) {
			return new WP_Error( 'missing_value', __( 'A value is required for the write action.', 'e2mconnect' ) );
		}

		$updated = update_field( $field_key_or_name, $input['value'], $post_id );

		return [
			'action'  => 'write',
			'post_id' => $raw_post_id,
			'field'   => $field_key_or_name,
			'updated' => (bool) $updated,
		];
	}

	return new WP_Error( 'invalid_action', __( 'Invalid action. Use read, write, or read_all.', 'e2mconnect' ) );
}

/**
 * Resolve a flexible post_id input into the format ACF expects.
 *
 * @param mixed $raw
 * @return int|string|WP_Error
 */
function e2m_engine_acf_resolve_post_id( $raw ) {
	if ( $raw === 'options' ) {
		return 'options';
	}

	// "term_123" or "user_123" — pass through as-is to ACF.
	if ( is_string( $raw ) && preg_match( '/^(term|user)_\d+$/', $raw ) ) {
		return $raw;
	}

	$id = (int) $raw;
	if ( $id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'Invalid post_id. Use an integer, "term_123", "user_123", or "options".', 'e2mconnect' ) );
	}

	if ( ! get_post( $id ) ) {
		return new WP_Error( 'post_not_found', __( 'No post found with the provided ID.', 'e2mconnect' ) );
	}

	return $id;
}

/**
 * Capability check for ACF field access.
 *
 * @param int|string $post_id
 */
function e2m_engine_acf_user_can( $post_id ): bool {
	if ( $post_id === 'options' ) {
		return current_user_can( 'manage_options' );
	}
	if ( is_string( $post_id ) && str_starts_with( $post_id, 'user_' ) ) {
		return current_user_can( 'edit_users' );
	}
	if ( is_string( $post_id ) && str_starts_with( $post_id, 'term_' ) ) {
		return current_user_can( 'manage_categories' );
	}
	return current_user_can( 'edit_post', (int) $post_id );
}
