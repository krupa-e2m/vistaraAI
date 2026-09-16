<?php
/**
 * E2M Connect MCP - ACPT Read / Write Field Values
 *
 * Read and write ACPT (Advanced Custom Post Types) field values using
 * the official ACPT functions API:
 *   - get_acpt_field()           — read a field value
 *   - save_acpt_meta_field_value() — write a field value
 *
 * ACPT organises fields into "boxes" (groups) inside post types/taxonomies/
 * users/option pages. Both box_name and field_name are always required.
 *
 * Supported object types: post, term, user, comment, option_page
 *
 * Supported operations:
 *   read  — get a field value for any object
 *   write — set a field value for any object
 *   list_fields — list all registered field boxes and fields for an object type
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/acpt-read-write-values', [
	'label'       => __( '[ACPT] Read / Write Field Values', 'e2mconnect' ),
	'description' => 'Read and write ACPT (Advanced Custom Post Types) custom field values on any post, term, user, comment, or options page. Requires box_name and field_name for every operation.',
	'category'    => 'e2m-acpt-plugin',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'list_fields' ],
				'description' => 'read — get a field value; write — save a field value; list_fields — list all boxes/fields for an object type.',
			],
			'box_name' => [
				'type'        => 'string',
				'description' => 'ACPT field group (box) name. Required for read and write.',
			],
			'field_name' => [
				'type'        => 'string',
				'description' => 'ACPT field name. Supports dot notation for nested/repeater fields, e.g. "repeater_field.sub_field". Required for read and write.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID — use when the field belongs to a post/CPT.',
			],
			'term_id' => [
				'type'        => 'integer',
				'description' => 'Term ID — use when the field belongs to a taxonomy term.',
			],
			'user_id' => [
				'type'        => 'integer',
				'description' => 'User ID — use when the field belongs to a user.',
			],
			'comment_id' => [
				'type'        => 'integer',
				'description' => 'Comment ID — use when the field belongs to a comment.',
			],
			'option_page' => [
				'type'        => 'string',
				'description' => 'Options page slug — use when the field belongs to an ACPT options page.',
			],
			'value' => [
				'description' => 'Value to save (required for write action). Accepts any type: string, number, array, boolean.',
			],
			'belongs_to' => [
				'type'        => 'string',
				'enum'        => [ 'post', 'term', 'user', 'comment', 'option-page' ],
				'description' => 'Object type for list_fields. Default: post.',
				'default'     => 'post',
			],
			'find' => [
				'type'        => 'string',
				'description' => 'For list_fields: post type slug, taxonomy slug, or options page slug to filter results.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'box_name'   => [ 'type' => 'string' ],
			'field_name' => [ 'type' => 'string' ],
			'value'      => [ 'description' => 'Field value (type varies by field type).' ],
			'saved'      => [ 'type' => 'boolean' ],
			'fields'     => [
				'type'  => 'array',
				'items' => [ 'type' => 'object', 'additionalProperties' => true ],
				'description' => 'All field objects for the requested object type (list_fields action).',
			],
		],
	],

	'execute_callback'    => 'e2m_engine_acpt_read_write_values_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'ACPT: Read / Write Field Values',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the acpt-read-write-values ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_acpt_read_write_values_ability( array $input ) {
	if ( ! e2m_engine_has_acpt() ) {
		return new WP_Error( 'acpt_missing', __( 'ACPT (Advanced Custom Post Types) plugin is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage ACPT field values.', 'e2mconnect' ) );
	}

	$action     = sanitize_key( $input['action'] ?? 'read' );
	$box_name   = sanitize_text_field( $input['box_name'] ?? '' );
	$field_name = sanitize_text_field( $input['field_name'] ?? '' );

	// Build the object identifier args — ACPT accepts exactly one of these.
	$object_args = e2m_engine_acpt_object_args( $input );

	switch ( $action ) {

		// ── Read ──────────────────────────────────────────────────────────────
		case 'read':
			if ( $box_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'box_name and field_name are required for the read action.', 'e2mconnect' ) );
			}
			if ( empty( $object_args ) ) {
				return new WP_Error( 'missing_object', __( 'One of post_id, term_id, user_id, comment_id, or option_page is required.', 'e2mconnect' ) );
			}

			$args = array_merge( $object_args, [
				'box_name'   => $box_name,
				'field_name' => $field_name,
			] );

			$value = function_exists( 'get_acpt_field' )
				? get_acpt_field( $args )
				: null;

			return [
				'action'     => 'read',
				'box_name'   => $box_name,
				'field_name' => $field_name,
				'value'      => $value,
			];

		// ── Write ─────────────────────────────────────────────────────────────
		case 'write':
			if ( $box_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'box_name and field_name are required for the write action.', 'e2mconnect' ) );
			}
			if ( empty( $object_args ) ) {
				return new WP_Error( 'missing_object', __( 'One of post_id, term_id, user_id, comment_id, or option_page is required.', 'e2mconnect' ) );
			}
			if ( ! array_key_exists( 'value', $input ) ) {
				return new WP_Error( 'missing_value', __( 'A value is required for the write action.', 'e2mconnect' ) );
			}

			$args = array_merge( $object_args, [
				'box_name'   => $box_name,
				'field_name' => $field_name,
				'value'      => $input['value'],
			] );

			$saved = function_exists( 'save_acpt_meta_field_value' )
				? (bool) save_acpt_meta_field_value( $args )
				: false;

			return [
				'action'     => 'write',
				'box_name'   => $box_name,
				'field_name' => $field_name,
				'saved'      => $saved,
			];

		// ── List fields ───────────────────────────────────────────────────────
		case 'list_fields':
			$belongs_to = sanitize_text_field( $input['belongs_to'] ?? 'post' );
			$find       = isset( $input['find'] ) ? sanitize_text_field( $input['find'] ) : null;

			if ( ! function_exists( 'get_acpt_meta_field_objects' ) ) {
				return new WP_Error( 'acpt_api_unavailable', __( 'get_acpt_meta_field_objects() is not available.', 'e2mconnect' ) );
			}

			$fields = get_acpt_meta_field_objects( $belongs_to, $find );

			// Normalize to a clean array of field summaries.
			$result = [];
			foreach ( (array) $fields as $field ) {
				$f = (array) $field;
				$result[] = [
					'box_name'   => $f['box_name'] ?? ( $f['boxName'] ?? '' ),
					'field_name' => $f['name'] ?? ( $f['fieldName'] ?? '' ),
					'type'       => $f['type'] ?? '',
					'label'      => $f['label'] ?? '',
					'required'   => (bool) ( $f['required'] ?? false ),
				];
			}

			return [
				'action'     => 'list_fields',
				'belongs_to' => $belongs_to,
				'find'       => $find,
				'fields'     => $result,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use "read", "write", or "list_fields".', 'e2mconnect' ) );
	}
}

/**
 * Extract the object-identifier args (post_id / term_id / user_id /
 * comment_id / option_page) from the ability input.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>  Empty when no valid identifier is present.
 */
function e2m_engine_acpt_object_args( array $input ): array {
	if ( ! empty( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
		return [ 'post_id' => (int) $input['post_id'] ];
	}
	if ( ! empty( $input['term_id'] ) && (int) $input['term_id'] > 0 ) {
		return [ 'term_id' => (int) $input['term_id'] ];
	}
	if ( ! empty( $input['user_id'] ) && (int) $input['user_id'] > 0 ) {
		return [ 'user_id' => (int) $input['user_id'] ];
	}
	if ( ! empty( $input['comment_id'] ) && (int) $input['comment_id'] > 0 ) {
		return [ 'comment_id' => (int) $input['comment_id'] ];
	}
	if ( ! empty( $input['option_page'] ) ) {
		return [ 'option_page' => sanitize_text_field( $input['option_page'] ) ];
	}
	return [];
}
