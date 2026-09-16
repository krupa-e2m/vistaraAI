<?php
/**
 * E2M Connect MCP - Meta Box Read / Write Values
 *
 * Read and write custom field values managed by the Meta Box plugin.
 * Uses the rwmb_meta() / rwmb_set_meta() helper functions which are
 * the canonical Meta Box API — they handle all field types including
 * cloneable, multiple-value, and relationship fields correctly.
 *
 * Supported operations:
 *   read  — get one or all field values for an object
 *   write — set a field value on an object
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/metabox-read-write-values', [
	'label'       => __( '[Meta Box] Read / Write Field Values', 'e2mconnect' ),
	'description' => 'Read and write custom field values managed by the Meta Box plugin on any post, term, user, or settings page.',
	'category'    => 'e2m-metabox',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write' ],
				'description' => 'read — get field value(s); write — set a field value.',
			],
			'field_id' => [
				'type'        => 'string',
				'description' => 'Meta Box field ID. If omitted on read, all meta for the object is returned.',
			],
			'object_id' => [
				'type'        => 'integer',
				'description' => 'Post ID, term ID, or user ID. Omit for options/settings pages (uses site-wide storage).',
			],
			'object_type' => [
				'type'        => 'string',
				'enum'        => [ 'post', 'term', 'user', 'setting' ],
				'description' => 'Object type. Default: post.',
				'default'     => 'post',
			],
			'value' => [
				'description' => 'Value to write (required for write action).',
			],
			'args' => [
				'type'                 => 'object',
				'description'          => 'Extra args passed to rwmb_meta() — e.g. {object_type: "term", field_id: "my_field"}.',
				'additionalProperties' => true,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'      => [ 'type' => 'string' ],
			'field_id'    => [ 'type' => 'string' ],
			'object_id'   => [ 'type' => 'integer' ],
			'object_type' => [ 'type' => 'string' ],
			'value'       => [ 'description' => 'Field value or all-meta array.' ],
			'updated'     => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_metabox_read_write_values_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Meta Box: Read / Write Field Values',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the metabox-read-write-values ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_metabox_read_write_values_ability( array $input ) {
	if ( ! e2m_engine_has_metabox() ) {
		return new WP_Error( 'metabox_missing', __( 'Meta Box plugin is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Meta Box field values.', 'e2mconnect' ) );
	}

	$action      = sanitize_key( $input['action'] ?? 'read' );
	$field_id    = sanitize_key( $input['field_id'] ?? '' );
	$object_id   = (int) ( $input['object_id'] ?? 0 );
	$object_type = sanitize_key( $input['object_type'] ?? 'post' );
	$extra_args  = is_array( $input['args'] ?? null ) ? $input['args'] : [];

	// Build the args array that rwmb_meta / rwmb_set_meta accept.
	$rwmb_args = array_merge( [ 'object_type' => $object_type ], $extra_args );

	switch ( $action ) {

		case 'read':
			if ( $field_id === '' ) {
				// No field_id — return all raw post/term/user meta.
				$value = e2m_engine_mb_get_all_meta( $object_id, $object_type );
				return [
					'action'      => 'read',
					'object_id'   => $object_id,
					'object_type' => $object_type,
					'value'       => $value,
				];
			}

			// Use rwmb_meta() — the canonical Meta Box read API.
			if ( function_exists( 'rwmb_meta' ) ) {
				$value = rwmb_meta( $field_id, $rwmb_args, $object_id ?: null );
			} else {
				$value = e2m_engine_mb_fallback_get( $field_id, $object_id, $object_type );
			}

			return [
				'action'      => 'read',
				'field_id'    => $field_id,
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'value'       => $value,
			];

		case 'write':
			if ( $field_id === '' ) {
				return new WP_Error( 'missing_field_id', __( 'field_id is required for the write action.', 'e2mconnect' ) );
			}
			if ( ! array_key_exists( 'value', $input ) ) {
				return new WP_Error( 'missing_value', __( 'A value is required for the write action.', 'e2mconnect' ) );
			}

			// Use rwmb_set_meta() — the canonical Meta Box write API.
			if ( function_exists( 'rwmb_set_meta' ) ) {
				rwmb_set_meta( $object_id, $field_id, $input['value'], $rwmb_args );
			} else {
				e2m_engine_mb_fallback_set( $field_id, $object_id, $object_type, $input['value'] );
			}

			return [
				'action'      => 'write',
				'field_id'    => $field_id,
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'updated'     => true,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use "read" or "write".', 'e2mconnect' ) );
	}
}

/**
 * Get all raw meta for an object when no field_id is given.
 *
 * @param int    $object_id
 * @param string $object_type
 * @return array<string, mixed>
 */
function e2m_engine_mb_get_all_meta( int $object_id, string $object_type ): array {
	switch ( $object_type ) {
		case 'term':
			return $object_id > 0 ? (array) get_term_meta( $object_id ) : [];
		case 'user':
			return $object_id > 0 ? (array) get_user_meta( $object_id ) : [];
		case 'setting':
			// Settings pages are stored as options; return empty rather than dumping all options.
			return [];
		default:
			return $object_id > 0 ? (array) get_post_meta( $object_id ) : [];
	}
}

/**
 * Fallback read (plain WordPress meta) when rwmb_meta is unavailable.
 *
 * @param string $field_id
 * @param int    $object_id
 * @param string $object_type
 * @return mixed
 */
function e2m_engine_mb_fallback_get( string $field_id, int $object_id, string $object_type ) {
	switch ( $object_type ) {
		case 'term':
			return get_term_meta( $object_id, $field_id, true );
		case 'user':
			return get_user_meta( $object_id, $field_id, true );
		default:
			return get_post_meta( $object_id, $field_id, true );
	}
}

/**
 * Fallback write (plain WordPress meta) when rwmb_set_meta is unavailable.
 *
 * @param string $field_id
 * @param int    $object_id
 * @param string $object_type
 * @param mixed  $value
 */
function e2m_engine_mb_fallback_set( string $field_id, int $object_id, string $object_type, $value ): void {
	switch ( $object_type ) {
		case 'term':
			update_term_meta( $object_id, $field_id, $value );
			break;
		case 'user':
			update_user_meta( $object_id, $field_id, $value );
			break;
		default:
			update_post_meta( $object_id, $field_id, $value );
	}
}
