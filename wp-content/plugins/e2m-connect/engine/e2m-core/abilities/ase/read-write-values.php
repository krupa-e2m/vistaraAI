<?php
/**
 * E2M Connect MCP - ASE Read / Write Custom Field Values
 *
 * Read and write ASE (Admin and Site Enhancements) Pro custom field
 * values using the official ASE functions API:
 *
 *   get_cf( $field_name, $output_format, $object_id )
 *     — retrieve a field value (or all fields when field_name is false/'all')
 *
 *   update_cf( $post_id, $fields_data, $single_new_value )
 *     — update one or multiple field values on a post
 *
 * ASE custom fields are post-based only (stored as standard post meta).
 * The feature is part of ASE Pro.
 *
 * Supported operations:
 *   read       — get a single field value (or all fields) for a post
 *   write      — update one or multiple field values on a post
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/ase-read-write-values', [
	'label'       => __( '[ASE] Read / Write Custom Field Values', 'e2mconnect' ),
	'description' => 'Read and write ASE Pro (Admin and Site Enhancements) custom field values on any post or CPT. Uses get_cf() and update_cf() — ASE Pro required.',
	'category'    => 'e2m-ase',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write' ],
				'description' => 'read — get field value(s) for a post; write — update one or more field values.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID to read from or write to. Required.',
			],
			'field_name' => [
				'type'        => 'string',
				'description' => 'ASE custom field name (machine name). For read: omit or pass "all" to return every field on the post.',
			],
			'output_format' => [
				'type'        => 'string',
				'enum'        => [ 'default', 'raw' ],
				'description' => 'Read output format. "default" returns the formatted value; "raw" returns the raw DB value. Default: default.',
				'default'     => 'default',
			],
			'value' => [
				'description' => 'Value to write for a single field (used with field_name). Required when writing a single field.',
			],
			'fields' => [
				'type'                 => 'object',
				'description'          => 'Key→value map for updating multiple fields at once, e.g. {"release_year": "2024", "runtime": "2h"}. Use instead of field_name + value.',
				'additionalProperties' => true,
			],
		],
		'required'             => [ 'action', 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'post_id'    => [ 'type' => 'integer' ],
			'field_name' => [ 'type' => 'string' ],
			'value'      => [ 'description' => 'Field value or key→value map of all fields.' ],
			'updated'    => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_ase_read_write_values_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'ASE: Read / Write Custom Field Values',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the ase-read-write-values ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_ase_read_write_values_ability( array $input ) {
	if ( ! e2m_engine_has_ase() ) {
		return new WP_Error(
			'ase_missing',
			__( 'ASE Pro (Admin and Site Enhancements) is not installed or activated on this site.', 'e2mconnect' )
		);
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage ASE custom field values.', 'e2mconnect' ) );
	}

	$action     = sanitize_key( $input['action'] );
	$post_id    = (int) $input['post_id'];
	$field_name = isset( $input['field_name'] ) ? sanitize_text_field( $input['field_name'] ) : false;

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post_id — post not found.', 'e2mconnect' ) );
	}

	switch ( $action ) {

		// ── Read ──────────────────────────────────────────────────────────────
		case 'read':
			$output_format = in_array( $input['output_format'] ?? 'default', [ 'default', 'raw' ], true )
				? ( $input['output_format'] ?? 'default' )
				: 'default';

			// field_name = false or 'all' → return all fields.
			$name = ( $field_name === '' || $field_name === 'all' ) ? false : $field_name;

			$value = function_exists( 'get_cf' )
				? get_cf( $name, $output_format, $post_id )
				: get_post_meta( $post_id, $field_name ?: '', $field_name !== false );

			return [
				'action'     => 'read',
				'post_id'    => $post_id,
				'field_name' => $field_name ?: 'all',
				'value'      => $value,
			];

		// ── Write ─────────────────────────────────────────────────────────────
		case 'write':
			// Multi-field update via 'fields' key.
			if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
				if ( ! function_exists( 'update_cf' ) ) {
					// Fallback: iterate and use update_post_meta directly.
					foreach ( $input['fields'] as $key => $val ) {
						update_post_meta( $post_id, sanitize_key( $key ), $val );
					}
					return [ 'action' => 'write', 'post_id' => $post_id, 'updated' => true ];
				}
				// update_cf( $post_id, $fields_array ) — pass assoc array as second arg.
				update_cf( $post_id, $input['fields'] );
				return [ 'action' => 'write', 'post_id' => $post_id, 'updated' => true ];
			}

			// Single-field update.
			if ( ! $field_name || $field_name === '' ) {
				return new WP_Error( 'missing_field', __( 'field_name (or fields map) is required for the write action.', 'e2mconnect' ) );
			}
			if ( ! array_key_exists( 'value', $input ) ) {
				return new WP_Error( 'missing_value', __( 'A value is required for the write action.', 'e2mconnect' ) );
			}

			if ( function_exists( 'update_cf' ) ) {
				// update_cf( $post_id, $field_name_string, $new_value )
				update_cf( $post_id, $field_name, $input['value'] );
			} else {
				update_post_meta( $post_id, $field_name, $input['value'] );
			}

			return [
				'action'     => 'write',
				'post_id'    => $post_id,
				'field_name' => $field_name,
				'updated'    => true,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use "read" or "write".', 'e2mconnect' ) );
	}
}
