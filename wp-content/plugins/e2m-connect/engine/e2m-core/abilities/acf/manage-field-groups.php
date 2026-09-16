<?php
/**
 * E2M Connect MCP - ACF Manage Field Groups
 *
 * Create, read, update, and delete ACF field groups and their fields.
 * Uses ACF's native acf_update_field_group() / acf_update_field() /
 * acf_delete_field_group() API so all changes appear instantly in the
 * ACF admin UI and are stored correctly in the database.
 *
 * Supports all ACF field types: text, textarea, number, email, url,
 * password, image, file, wysiwyg, oembed, select, checkbox, radio,
 * button_group, true_false, link, post_object, page_link, relationship,
 * taxonomy, user, google_map, date_picker, date_time_picker, time_picker,
 * color_picker, repeater (Pro), flexible_content (Pro), group.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/acf-manage-field-groups', [
	'label'       => __( '[ACF] Manage Field Groups', 'e2mconnect' ),
	'description' => 'Create, read, update, and delete ACF field groups and their fields — including location rules, field settings, and field order.',
	'category'    => 'e2m-acf',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete', 'add_field', 'update_field', 'delete_field', 'list_fields' ],
				'description' => 'Operation to perform.',
			],
			'group_id' => [
				'type'        => [ 'integer', 'string' ],
				'description' => 'Field group ID (integer) or key (e.g. "group_abc123") — required for get, update, delete, add_field, list_fields.',
			],
			'field_id' => [
				'type'        => [ 'integer', 'string' ],
				'description' => 'Field ID (integer) or key (e.g. "field_abc123") — required for update_field and delete_field.',
			],
			'title' => [
				'type'        => 'string',
				'description' => 'Field group title (e.g. "Hero Settings").',
			],
			'location' => [
				'type'        => 'array',
				'description' => 'ACF location rules array — same structure as ACF\'s location param. Example: [[{"param":"post_type","operator":"==","value":"post"}]].',
				'items'       => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			],
			'fields' => [
				'type'        => 'array',
				'description' => 'Array of field definition objects to attach when creating a group.',
				'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'field' => [
				'type'                 => 'object',
				'description'          => 'A single ACF field definition for add_field or update_field.',
				'additionalProperties' => true,
			],
			'menu_order' => [
				'type'        => 'integer',
				'description' => 'Display order for the field group (lower = higher on screen).',
			],
			'active' => [
				'type'        => 'boolean',
				'description' => 'Whether the field group is active (true) or hidden (false).',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [ 'type' => 'string' ],
			'groups' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'group'  => [ 'type' => 'object', 'additionalProperties' => true ],
			'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'field'  => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_acf_manage_field_groups_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'ACF: Manage Field Groups',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the acf-manage-field-groups ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_acf_manage_field_groups_ability( array $input ) {
	if ( ! e2m_engine_has_acf() ) {
		return new WP_Error( 'acf_missing', __( 'Advanced Custom Fields is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage ACF field groups.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] ?? 'list' );

	switch ( $action ) {

		// ── List all field groups ────────────────────────────────────────────
		case 'list':
			$groups = acf_get_field_groups();
			return [
				'action' => 'list',
				'groups' => array_map( fn( $g ) => [
					'id'         => $g['ID'] ?? 0,
					'key'        => $g['key'] ?? '',
					'title'      => $g['title'] ?? '',
					'active'     => (bool) ( $g['active'] ?? true ),
					'menu_order' => (int) ( $g['menu_order'] ?? 0 ),
				], $groups ),
			];

		// ── Get one field group with all its fields ──────────────────────────
		case 'get':
			$group = e2m_engine_acf_get_group( $input['group_id'] ?? null );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			$fields = acf_get_fields( $group['key'] );
			return [
				'action' => 'get',
				'group'  => $group,
				'fields' => is_array( $fields ) ? $fields : [],
			];

		// ── List fields in a group ───────────────────────────────────────────
		case 'list_fields':
			$group = e2m_engine_acf_get_group( $input['group_id'] ?? null );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			$fields = acf_get_fields( $group['key'] );
			return [
				'action' => 'list_fields',
				'group'  => [ 'id' => $group['ID'] ?? 0, 'key' => $group['key'], 'title' => $group['title'] ],
				'fields' => is_array( $fields ) ? $fields : [],
			];

		// ── Create a new field group ─────────────────────────────────────────
		case 'create':
			if ( empty( $input['title'] ) ) {
				return new WP_Error( 'missing_title', __( 'A title is required to create a field group.', 'e2mconnect' ) );
			}

			$group_key = 'group_' . uniqid();
			$group_def = [
				'key'        => $group_key,
				'title'      => sanitize_text_field( $input['title'] ),
				'fields'     => [],
				'location'   => is_array( $input['location'] ?? null ) ? $input['location'] : [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
				'menu_order' => (int) ( $input['menu_order'] ?? 0 ),
				'active'     => isset( $input['active'] ) ? (bool) $input['active'] : true,
			];

			// Add inline fields if provided.
			if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
				$order = 0;
				foreach ( $input['fields'] as $field_def ) {
					if ( ! is_array( $field_def ) ) {
						continue;
					}
					$group_def['fields'][] = e2m_engine_acf_prepare_field( $field_def, $group_key, $order++ );
				}
			}

			$saved = acf_update_field_group( $group_def );
			return [
				'action' => 'create',
				'group'  => [
					'id'    => $saved['ID'] ?? 0,
					'key'   => $group_key,
					'title' => $group_def['title'],
				],
			];

		// ── Update an existing field group ───────────────────────────────────
		case 'update':
			$group = e2m_engine_acf_get_group( $input['group_id'] ?? null );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			if ( isset( $input['title'] ) ) {
				$group['title'] = sanitize_text_field( $input['title'] );
			}
			if ( isset( $input['location'] ) && is_array( $input['location'] ) ) {
				$group['location'] = $input['location'];
			}
			if ( isset( $input['menu_order'] ) ) {
				$group['menu_order'] = (int) $input['menu_order'];
			}
			if ( isset( $input['active'] ) ) {
				$group['active'] = (bool) $input['active'];
			}
			$saved = acf_update_field_group( $group );
			return [
				'action' => 'update',
				'group'  => [ 'id' => $saved['ID'] ?? 0, 'key' => $group['key'], 'title' => $group['title'] ],
			];

		// ── Delete a field group ─────────────────────────────────────────────
		case 'delete':
			$group = e2m_engine_acf_get_group( $input['group_id'] ?? null );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			$result = acf_delete_field_group( $group['ID'] ?? $group['key'] );
			return [ 'action' => 'delete', 'deleted' => (bool) $result ];

		// ── Add a field to an existing group ─────────────────────────────────
		case 'add_field':
			$group = e2m_engine_acf_get_group( $input['group_id'] ?? null );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			if ( empty( $input['field'] ) || ! is_array( $input['field'] ) ) {
				return new WP_Error( 'missing_field', __( 'A field definition object is required.', 'e2mconnect' ) );
			}
			$existing_fields = acf_get_fields( $group['key'] );
			$order           = is_array( $existing_fields ) ? count( $existing_fields ) : 0;
			$field_def       = e2m_engine_acf_prepare_field( $input['field'], $group['key'], $order );
			$saved_field     = acf_update_field( $field_def );
			return [
				'action' => 'add_field',
				'field'  => [
					'id'   => $saved_field['ID'] ?? 0,
					'key'  => $field_def['key'],
					'name' => $field_def['name'],
					'type' => $field_def['type'],
				],
			];

		// ── Update a field ───────────────────────────────────────────────────
		case 'update_field':
			if ( empty( $input['field_id'] ) ) {
				return new WP_Error( 'missing_field_id', __( 'field_id is required to update a field.', 'e2mconnect' ) );
			}
			$field_id = $input['field_id'];
			$field    = is_numeric( $field_id ) ? acf_get_field( (int) $field_id ) : acf_get_field( $field_id );
			if ( ! $field ) {
				return new WP_Error( 'field_not_found', __( 'ACF field not found.', 'e2mconnect' ) );
			}
			$updates = is_array( $input['field'] ?? null ) ? $input['field'] : [];
			foreach ( $updates as $k => $v ) {
				$field[ sanitize_key( $k ) ] = $v;
			}
			$saved = acf_update_field( $field );
			return [ 'action' => 'update_field', 'field' => [ 'id' => $saved['ID'] ?? 0, 'key' => $field['key'], 'name' => $field['name'] ] ];

		// ── Delete a field ───────────────────────────────────────────────────
		case 'delete_field':
			if ( empty( $input['field_id'] ) ) {
				return new WP_Error( 'missing_field_id', __( 'field_id is required to delete a field.', 'e2mconnect' ) );
			}
			$field_id = $input['field_id'];
			$result   = acf_delete_field( is_numeric( $field_id ) ? (int) $field_id : $field_id );
			return [ 'action' => 'delete_field', 'deleted' => (bool) $result ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}

/**
 * Resolve a group_id (integer ID or string key) to a full group array.
 *
 * @param mixed $raw
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_acf_get_group( $raw ) {
	if ( empty( $raw ) ) {
		return new WP_Error( 'missing_group_id', __( 'group_id is required.', 'e2mconnect' ) );
	}
	$group = is_numeric( $raw ) ? acf_get_field_group( (int) $raw ) : acf_get_field_group( $raw );
	if ( ! $group ) {
		return new WP_Error( 'group_not_found', __( 'ACF field group not found.', 'e2mconnect' ) );
	}
	return $group;
}

/**
 * Build a sanitised ACF field definition array ready for acf_update_field().
 *
 * @param array<string, mixed> $def
 * @param string               $parent_key
 * @param int                  $order
 * @return array<string, mixed>
 */
function e2m_engine_acf_prepare_field( array $def, string $parent_key, int $order ): array {
	$field_name = sanitize_key( $def['name'] ?? ( 'field_' . $order ) );
	$field_type = sanitize_key( $def['type'] ?? 'text' );
	$field_key  = isset( $def['key'] ) ? sanitize_key( $def['key'] ) : 'field_' . uniqid();

	$field = [
		'key'           => $field_key,
		'label'         => sanitize_text_field( $def['label'] ?? ucfirst( str_replace( '_', ' ', $field_name ) ) ),
		'name'          => $field_name,
		'type'          => $field_type,
		'parent'        => $parent_key,
		'menu_order'    => $order,
		'required'      => (bool) ( $def['required'] ?? false ),
		'instructions'  => sanitize_text_field( $def['instructions'] ?? '' ),
		'default_value' => $def['default_value'] ?? '',
		'placeholder'   => sanitize_text_field( $def['placeholder'] ?? '' ),
	];

	// Merge any additional type-specific settings (choices, min, max, etc.).
	$extra_keys = [ 'choices', 'min', 'max', 'step', 'rows', 'maxlength', 'post_type', 'taxonomy',
		'allow_null', 'multiple', 'ui', 'return_format', 'preview_size', 'library',
		'min_width', 'min_height', 'min_size', 'max_width', 'max_height', 'max_size', 'mime_types',
		'sub_fields', 'button_label', 'collapsed', 'min', 'max', 'layout', 'button_label' ];

	foreach ( $extra_keys as $k ) {
		if ( array_key_exists( $k, $def ) ) {
			$field[ $k ] = $def[ $k ];
		}
	}

	return $field;
}
