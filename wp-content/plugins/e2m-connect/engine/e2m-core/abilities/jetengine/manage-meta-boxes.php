<?php
/**
 * E2M Connect MCP - JetEngine Manage Meta Boxes
 *
 * Full CRUD for JetEngine meta boxes and their custom fields.
 * JetEngine stores meta boxes in WordPress options (key: jet_engine_meta_boxes)
 * as an associative array keyed by string ID format "meta-{num}".
 * Each item has 'args' (settings) and 'meta_fields' (field definitions).
 *
 * Supported operations:
 *   list        — list all meta boxes with field counts
 *   get         — full meta box definition including all fields
 *   create      — create a new meta box with fields and conditions
 *   update      — update settings, conditions, or field list
 *   delete      — permanently delete a meta box
 *   add_field   — append a field to an existing meta box
 *   update_field — update an individual field definition
 *   delete_field — remove a field from a meta box
 *   read_value  — get a meta field value from a specific post/term/user
 *   write_value — set a meta field value on a specific post/term/user
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/jetengine-manage-meta-boxes', [
	'label'       => __( '[JetEngine] Manage Meta Boxes', 'e2mconnect' ),
	'description' => 'Full CRUD for JetEngine meta boxes and custom fields — create, read, update, delete meta boxes and their field definitions, plus read/write field values on posts/terms/users.',
	'category'    => 'e2m-jetengine',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete', 'add_field', 'update_field', 'delete_field', 'read_value', 'write_value' ],
				'description' => 'Operation to perform.',
			],
			'meta_box_id' => [
				'type'        => 'string',
				'description' => 'JetEngine meta box ID — string format "meta-{num}" (e.g. "meta-1"). Required for get, update, delete, add_field, update_field, delete_field.',
			],
			'name' => [
				'type'        => 'string',
				'description' => 'Meta box name/label (required for create).',
			],
			'object_type' => [
				'type'        => 'string',
				'enum'        => [ 'post', 'taxonomy', 'user', 'comment', 'option_page' ],
				'description' => 'Object type this meta box applies to (required for create). Default: post.',
			],
			'allowed_post_type' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Post type slugs this meta box is shown for (when object_type=post).',
			],
			'allowed_tax' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Taxonomy slugs this meta box is shown for (when object_type=taxonomy).',
			],
			'args' => [
				'type'                 => 'object',
				'description'          => 'Full args overrides for the meta box settings (advanced). Merged on top of defaults.',
				'additionalProperties' => true,
			],
			'fields' => [
				'type'        => 'array',
				'description' => 'Array of field definitions. Each field: {name, title, type, ...type-specific settings}.',
				'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'field' => [
				'type'                 => 'object',
				'description'          => 'A single field definition for add_field or update_field.',
				'additionalProperties' => true,
			],
			'field_name' => [
				'type'        => 'string',
				'description' => 'Machine name of the field to update_field, delete_field, read_value, or write_value.',
			],
			'object_id' => [
				'type'        => 'integer',
				'description' => 'Post ID, term ID, or user ID to read/write a field value on.',
			],
			'value' => [
				'description' => 'Value to write (write_value action).',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'      => [ 'type' => 'string' ],
			'meta_boxes'  => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'meta_box'    => [ 'type' => 'object', 'additionalProperties' => true ],
			'fields'      => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'field'       => [ 'type' => 'object', 'additionalProperties' => true ],
			'value'       => [ 'description' => 'Field value (type varies).' ],
			'meta_box_id' => [ 'type' => 'string' ],
			'updated'     => [ 'type' => 'boolean' ],
			'deleted'     => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_jetengine_manage_meta_boxes_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'JetEngine: Manage Meta Boxes',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the jetengine-manage-meta-boxes ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_jetengine_manage_meta_boxes_ability( array $input ) {
	if ( ! e2m_engine_has_jetengine() ) {
		return new WP_Error( 'jetengine_missing', __( 'JetEngine is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage JetEngine meta boxes.', 'e2mconnect' ) );
	}

	$data   = jet_engine()->meta_boxes->data;
	$action = sanitize_key( $input['action'] ?? 'list' );

	switch ( $action ) {

		// ── List all meta boxes ──────────────────────────────────────────────
		case 'list':
			$raw        = $data->get_raw();
			$meta_boxes = [];
			foreach ( (array) $raw as $id => $item ) {
				$name   = $item['args']['name'] ?? $id;
				$fields = $item['meta_fields'] ?? [];
				$meta_boxes[] = [
					'id'          => $id,
					'name'        => $name,
					'object_type' => $item['args']['object_type'] ?? '',
					'field_count' => count( (array) $fields ),
				];
			}
			return [ 'action' => 'list', 'meta_boxes' => $meta_boxes ];

		// ── Get one meta box with its fields ─────────────────────────────────
		case 'get':
			$id = sanitize_text_field( $input['meta_box_id'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			$item = e2m_engine_je_get_meta_box_raw( $data, $id );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return [
				'action'   => 'get',
				'meta_box' => [
					'id'   => $id,
					'args' => $item['args'] ?? [],
				],
				'fields' => array_values( (array) ( $item['meta_fields'] ?? [] ) ),
			];

		// ── Create a meta box ─────────────────────────────────────────────────
		case 'create':
			if ( empty( $input['name'] ) ) {
				return new WP_Error( 'missing_name', __( 'A name is required to create a meta box.', 'e2mconnect' ) );
			}
			$args = array_merge(
				[
					'name'              => sanitize_text_field( $input['name'] ),
					'object_type'       => sanitize_key( $input['object_type'] ?? 'post' ),
					'allowed_post_type' => (array) ( $input['allowed_post_type'] ?? [] ),
					'allowed_tax'       => (array) ( $input['allowed_tax'] ?? [] ),
					'position'          => 'normal',
					'priority'          => 'high',
					'active_conditions' => [],
				],
				is_array( $input['args'] ?? null ) ? $input['args'] : []
			);

			$fields = [];
			foreach ( (array) ( $input['fields'] ?? [] ) as $idx => $fdef ) {
				if ( is_array( $fdef ) ) {
					$fields[] = e2m_engine_je_prepare_field( $fdef, $idx );
				}
			}

			$new_id = $data->update_item_in_db( [
				'args'        => $args,
				'meta_fields' => $fields,
			] );

			return [
				'action'      => 'create',
				'meta_box_id' => $new_id,
				'name'        => $args['name'],
			];

		// ── Update a meta box ─────────────────────────────────────────────────
		case 'update':
			$id = sanitize_text_field( $input['meta_box_id'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			$item = e2m_engine_je_get_meta_box_raw( $data, $id );
			if ( is_wp_error( $item ) ) {
				return $item;
			}

			$current_args = $item['args'] ?? [];
			if ( isset( $input['name'] ) ) {
				$current_args['name'] = sanitize_text_field( $input['name'] );
			}
			if ( isset( $input['object_type'] ) ) {
				$current_args['object_type'] = sanitize_key( $input['object_type'] );
			}
			if ( isset( $input['allowed_post_type'] ) ) {
				$current_args['allowed_post_type'] = (array) $input['allowed_post_type'];
			}
			if ( isset( $input['allowed_tax'] ) ) {
				$current_args['allowed_tax'] = (array) $input['allowed_tax'];
			}
			if ( is_array( $input['args'] ?? null ) ) {
				$current_args = array_merge( $current_args, $input['args'] );
			}

			$fields = $item['meta_fields'] ?? [];
			if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
				$fields = [];
				foreach ( $input['fields'] as $idx => $fdef ) {
					$fields[] = e2m_engine_je_prepare_field( (array) $fdef, $idx );
				}
			}

			$data->update_item_in_db( [
				'id'          => $id,
				'args'        => $current_args,
				'meta_fields' => $fields,
			] );

			return [ 'action' => 'update', 'meta_box_id' => $id, 'updated' => true ];

		// ── Delete a meta box ─────────────────────────────────────────────────
		case 'delete':
			$id = sanitize_text_field( $input['meta_box_id'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			$raw = $data->get_raw();
			if ( ! isset( $raw[ $id ] ) ) {
				return new WP_Error( 'not_found', __( 'JetEngine meta box not found.', 'e2mconnect' ) );
			}
			unset( $raw[ $id ] );
			update_option( $data->option_name, $raw );
			$data->raw = null; // reset internal cache
			return [ 'action' => 'delete', 'meta_box_id' => $id, 'deleted' => true ];

		// ── Add a field ───────────────────────────────────────────────────────
		case 'add_field':
			$id = sanitize_text_field( $input['meta_box_id'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			if ( empty( $input['field'] ) || ! is_array( $input['field'] ) ) {
				return new WP_Error( 'missing_field', __( 'A field definition object is required.', 'e2mconnect' ) );
			}
			$item = e2m_engine_je_get_meta_box_raw( $data, $id );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$fields    = array_values( (array) ( $item['meta_fields'] ?? [] ) );
			$new_field = e2m_engine_je_prepare_field( $input['field'], count( $fields ) );
			$fields[]  = $new_field;
			$data->update_item_in_db( array_merge( $item, [ 'id' => $id, 'meta_fields' => $fields ] ) );
			return [ 'action' => 'add_field', 'meta_box_id' => $id, 'field' => $new_field ];

		// ── Update a field ────────────────────────────────────────────────────
		case 'update_field':
			$id         = sanitize_text_field( $input['meta_box_id'] ?? '' );
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			if ( $field_name === '' ) {
				return new WP_Error( 'missing_field_name', __( 'field_name is required.', 'e2mconnect' ) );
			}
			$item = e2m_engine_je_get_meta_box_raw( $data, $id );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$fields  = array_values( (array) ( $item['meta_fields'] ?? [] ) );
			$found   = false;
			$updated = null;
			foreach ( $fields as &$f ) {
				if ( ( $f['name'] ?? '' ) === $field_name ) {
					$updates = is_array( $input['field'] ?? null ) ? $input['field'] : [];
					foreach ( $updates as $k => $v ) {
						$f[ sanitize_key( $k ) ] = $v;
					}
					$found   = true;
					$updated = $f;
					break;
				}
			}
			unset( $f );
			if ( ! $found ) {
				return new WP_Error( 'field_not_found', __( 'Field not found in this meta box.', 'e2mconnect' ) );
			}
			$data->update_item_in_db( array_merge( $item, [ 'id' => $id, 'meta_fields' => $fields ] ) );
			return [ 'action' => 'update_field', 'meta_box_id' => $id, 'field' => $updated ];

		// ── Delete a field ────────────────────────────────────────────────────
		case 'delete_field':
			$id         = sanitize_text_field( $input['meta_box_id'] ?? '' );
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $id === '' ) {
				return new WP_Error( 'missing_meta_box_id', __( 'meta_box_id is required.', 'e2mconnect' ) );
			}
			if ( $field_name === '' ) {
				return new WP_Error( 'missing_field_name', __( 'field_name is required.', 'e2mconnect' ) );
			}
			$item = e2m_engine_je_get_meta_box_raw( $data, $id );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$fields   = array_values( (array) ( $item['meta_fields'] ?? [] ) );
			$original = count( $fields );
			$fields   = array_values( array_filter( $fields, fn( $f ) => ( $f['name'] ?? '' ) !== $field_name ) );
			$data->update_item_in_db( array_merge( $item, [ 'id' => $id, 'meta_fields' => $fields ] ) );
			return [ 'action' => 'delete_field', 'meta_box_id' => $id, 'deleted' => count( $fields ) < $original ];

		// ── Read a field value ────────────────────────────────────────────────
		case 'read_value':
			$object_id  = (int) ( $input['object_id'] ?? 0 );
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $object_id <= 0 ) {
				return new WP_Error( 'invalid_object_id', __( 'A valid object_id is required.', 'e2mconnect' ) );
			}
			if ( $field_name === '' ) {
				return new WP_Error( 'missing_field_name', __( 'field_name is required.', 'e2mconnect' ) );
			}
			$value = get_post_meta( $object_id, $field_name, true );
			return [ 'action' => 'read_value', 'object_id' => $object_id, 'field_name' => $field_name, 'value' => $value ];

		// ── Write a field value ───────────────────────────────────────────────
		case 'write_value':
			$object_id  = (int) ( $input['object_id'] ?? 0 );
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $object_id <= 0 ) {
				return new WP_Error( 'invalid_object_id', __( 'A valid object_id is required.', 'e2mconnect' ) );
			}
			if ( $field_name === '' ) {
				return new WP_Error( 'missing_field_name', __( 'field_name is required.', 'e2mconnect' ) );
			}
			if ( ! array_key_exists( 'value', $input ) ) {
				return new WP_Error( 'missing_value', __( 'A value is required for the write_value action.', 'e2mconnect' ) );
			}
			update_post_meta( $object_id, $field_name, $input['value'] );
			return [ 'action' => 'write_value', 'object_id' => $object_id, 'field_name' => $field_name, 'updated' => true ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}

/**
 * Get a raw meta box item array from the options store.
 *
 * @param Jet_Engine_Meta_Boxes_Data $data
 * @param string                     $id  e.g. "meta-1"
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_je_get_meta_box_raw( $data, string $id ) {
	$raw = $data->get_raw();
	if ( ! isset( $raw[ $id ] ) ) {
		return new WP_Error( 'not_found', sprintf(
			/* translators: %s: meta box ID */
			__( 'JetEngine meta box "%s" not found.', 'e2mconnect' ),
			$id
		) );
	}
	return $raw[ $id ];
}

/**
 * Build a sanitised JetEngine field definition array.
 *
 * @param array<string, mixed> $def
 * @param int                  $order
 * @return array<string, mixed>
 */
function e2m_engine_je_prepare_field( array $def, int $order ): array {
	$name  = sanitize_key( $def['name'] ?? ( 'field_' . $order ) );
	$type  = sanitize_key( $def['type'] ?? 'text' );
	$field = [
		'name'          => $name,
		'title'         => sanitize_text_field( $def['title'] ?? ucfirst( str_replace( '_', ' ', $name ) ) ),
		'type'          => $type,
		'object_type'   => 'field',
		'order'         => $order,
		'is_required'   => (bool) ( $def['is_required'] ?? false ),
		'description'   => sanitize_text_field( $def['description'] ?? '' ),
		'default_value' => $def['default_value'] ?? '',
		'options'       => [],
	];

	$extra = [ 'options', 'repeater-fields', 'width', 'placeholder', 'min_value', 'max_value',
		'step', 'allowed_mime_types', 'is_array', 'val_format', 'conditions' ];
	foreach ( $extra as $k ) {
		if ( array_key_exists( $k, $def ) ) {
			$field[ $k ] = $def[ $k ];
		}
	}

	return $field;
}
