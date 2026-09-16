<?php
/**
 * E2M Connect MCP - Pods Full CRUD
 *
 * Full CRUD for the Pods Framework — manage pod definitions, field
 * schemas, and pod item records via the official PodsAPI class:
 *
 *   pods_api()              — returns PodsAPI instance
 *   pods( $type, $id )      — returns a Pods object for reading items
 *
 * Three resource types are covered:
 *
 *   PODS (schema)
 *     list_pods      — list all registered pods
 *     get_pod        — full pod definition including field groups
 *     create_pod     — create a new pod (post_type, taxonomy, user, etc.)
 *     update_pod     — update pod label, description, or settings
 *     delete_pod     — permanently delete a pod and optionally its data
 *
 *   FIELDS (schema)
 *     list_fields    — list all fields in a pod
 *     get_field      — get a single field definition
 *     create_field   — add a new field to a pod
 *     update_field   — update a field's settings
 *     delete_field   — remove a field from a pod
 *
 *   ITEMS (records)
 *     list_items     — query pod items with filters, limit, offset, order
 *     get_item       — get a single item with its field values
 *     create_item    — insert a new item into a pod
 *     update_item    — update field values on an existing item
 *     delete_item    — delete an item from a pod
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/pods-full-crud', [
	'label'       => __( '[Pods] Full CRUD', 'e2mconnect' ),
	'description' => 'Full CRUD for Pods Framework — manage pod definitions, field schemas, and pod item records.',
	'category'    => 'e2m-pods',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [
					'list_pods', 'get_pod', 'create_pod', 'update_pod', 'delete_pod',
					'list_fields', 'get_field', 'create_field', 'update_field', 'delete_field',
					'list_items', 'get_item', 'create_item', 'update_item', 'delete_item',
				],
				'description' => 'Operation to perform.',
			],

			// ── Pod / field identifiers ────────────────────────────────────
			'pod_name' => [
				'type'        => 'string',
				'description' => 'Pod slug/name. Required for most operations.',
			],
			'field_name' => [
				'type'        => 'string',
				'description' => 'Field slug/name. Required for get_field, create_field, update_field, delete_field.',
			],
			'item_id' => [
				'type'        => 'integer',
				'description' => 'Item ID (post ID, term ID, etc.). Required for get_item, update_item, delete_item.',
			],

			// ── Pod settings ───────────────────────────────────────────────
			'label' => [
				'type'        => 'string',
				'description' => 'Singular label for the pod or field.',
			],
			'label_plural' => [
				'type'        => 'string',
				'description' => 'Plural label for the pod.',
			],
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'post_type', 'taxonomy', 'user', 'comment', 'settings', 'pod' ],
				'description' => 'Pod storage type (required for create_pod). "pod" = custom table.',
			],
			'description' => [
				'type'        => 'string',
				'description' => 'Pod or field description.',
			],

			// ── Field settings ─────────────────────────────────────────────
			'field_type' => [
				'type'        => 'string',
				'description' => 'Field type for create_field, e.g. text, textarea, number, date, image, file, pick, boolean, email, phone, website, currency, wysiwyg, code, paragraph, heading, html, separator, avatar, slug.',
				'default'     => 'text',
			],
			'field_settings' => [
				'type'                 => 'object',
				'description'          => 'Additional field settings to pass to save_field (e.g. pick_object, required, default_value, options).',
				'additionalProperties' => true,
			],

			// ── Item data ──────────────────────────────────────────────────
			'data' => [
				'type'                 => 'object',
				'description'          => 'Field values for create_item or update_item. Keys = field names, values = field values.',
				'additionalProperties' => true,
			],

			// ── Query params (list_items) ──────────────────────────────────
			'limit' => [
				'type'        => 'integer',
				'description' => 'Max items to return in list_items. Default 20, max 200.',
				'default'     => 20,
			],
			'offset' => [
				'type'        => 'integer',
				'description' => 'Offset for pagination in list_items. Default 0.',
				'default'     => 0,
			],
			'order_by' => [
				'type'        => 'string',
				'description' => 'Field name to order list_items results by. Default: t.ID.',
			],
			'order' => [
				'type'        => 'string',
				'enum'        => [ 'ASC', 'DESC' ],
				'description' => 'Sort direction for list_items. Default: ASC.',
				'default'     => 'ASC',
			],
			'where' => [
				'type'        => 'string',
				'description' => 'Raw WHERE clause for list_items (Pods SQL syntax). Use with care.',
			],

			// ── Misc ───────────────────────────────────────────────────────
			'delete_all' => [
				'type'        => 'boolean',
				'description' => 'For delete_pod: also delete all items/data belonging to the pod. Default false.',
				'default'     => false,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'pods'     => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'pod'      => [ 'type' => 'object', 'additionalProperties' => true ],
			'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'field'    => [ 'type' => 'object', 'additionalProperties' => true ],
			'items'    => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'item'     => [ 'type' => 'object', 'additionalProperties' => true ],
			'total'    => [ 'type' => 'integer' ],
			'id'       => [ 'type' => 'integer' ],
			'deleted'  => [ 'type' => 'boolean' ],
			'pod_name' => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_pods_full_crud_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Pods: Full CRUD',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the pods-full-crud ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_pods_full_crud_ability( array $input ) {
	if ( ! e2m_engine_has_pods() ) {
		return new WP_Error( 'pods_missing', __( 'Pods Framework plugin is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Pods.', 'e2mconnect' ) );
	}

	$api      = pods_api();
	$action   = sanitize_key( $input['action'] ?? '' );
	$pod_name = sanitize_key( $input['pod_name'] ?? '' );

	switch ( $action ) {

		// ════════════════════════════════════════════════════════════════════
		// POD OPERATIONS
		// ════════════════════════════════════════════════════════════════════

		case 'list_pods':
			$pods = $api->load_pods( [ 'fields' => false ] );
			$list = [];
			foreach ( (array) $pods as $pod ) {
				$list[] = [
					'name'        => $pod['name'] ?? '',
					'label'       => $pod['label'] ?? '',
					'type'        => $pod['type'] ?? '',
					'storage'     => $pod['storage'] ?? '',
					'field_count' => isset( $pod['fields'] ) ? count( (array) $pod['fields'] ) : 0,
				];
			}
			return [ 'action' => 'list_pods', 'pods' => $list ];

		case 'get_pod':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$pod = $api->load_pod( [ 'name' => $pod_name ] );
			if ( empty( $pod ) ) {
				return new WP_Error( 'pod_not_found', sprintf( __( 'Pod "%s" not found.', 'e2mconnect' ), $pod_name ) );
			}
			// Summarise fields so the response stays compact.
			$fields = [];
			foreach ( (array) ( $pod['fields'] ?? [] ) as $f ) {
				$fields[] = [
					'name'  => $f['name'] ?? '',
					'label' => $f['label'] ?? '',
					'type'  => $f['type'] ?? '',
				];
			}
			return [
				'action' => 'get_pod',
				'pod'    => [
					'name'        => $pod['name'] ?? '',
					'label'       => $pod['label'] ?? '',
					'type'        => $pod['type'] ?? '',
					'storage'     => $pod['storage'] ?? '',
					'description' => $pod['description'] ?? '',
					'fields'      => $fields,
				],
			];

		case 'create_pod':
			if ( $pod_name === '' || empty( $input['type'] ) ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and type are required for create_pod.', 'e2mconnect' ) );
			}
			$params = [
				'name'        => $pod_name,
				'label'       => sanitize_text_field( $input['label'] ?? ucwords( str_replace( '_', ' ', $pod_name ) ) ),
				'label_plural'=> sanitize_text_field( $input['label_plural'] ?? '' ),
				'type'        => sanitize_key( $input['type'] ),
				'description' => sanitize_textarea_field( $input['description'] ?? '' ),
			];
			$pod_id = $api->save_pod( $params );
			if ( is_wp_error( $pod_id ) ) {
				return $pod_id;
			}
			return [ 'action' => 'create_pod', 'pod_name' => $pod_name, 'id' => (int) $pod_id ];

		case 'update_pod':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$pod = $api->load_pod( [ 'name' => $pod_name ] );
			if ( empty( $pod ) ) {
				return new WP_Error( 'pod_not_found', sprintf( __( 'Pod "%s" not found.', 'e2mconnect' ), $pod_name ) );
			}
			$params = [ 'id' => $pod['id'], 'name' => $pod_name ];
			if ( isset( $input['label'] ) ) {
				$params['label'] = sanitize_text_field( $input['label'] );
			}
			if ( isset( $input['label_plural'] ) ) {
				$params['label_plural'] = sanitize_text_field( $input['label_plural'] );
			}
			if ( isset( $input['description'] ) ) {
				$params['description'] = sanitize_textarea_field( $input['description'] );
			}
			$api->save_pod( $params );
			return [ 'action' => 'update_pod', 'pod_name' => $pod_name, 'updated' => true ];

		case 'delete_pod':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$delete_all = (bool) ( $input['delete_all'] ?? false );
			$api->delete_pod( [ 'name' => $pod_name ], false, $delete_all );
			return [ 'action' => 'delete_pod', 'pod_name' => $pod_name, 'deleted' => true ];

		// ════════════════════════════════════════════════════════════════════
		// FIELD OPERATIONS
		// ════════════════════════════════════════════════════════════════════

		case 'list_fields':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$fields_raw = $api->load_fields( [ 'pod' => $pod_name ] );
			$fields     = [];
			foreach ( (array) $fields_raw as $f ) {
				$fields[] = [
					'name'        => $f['name'] ?? '',
					'label'       => $f['label'] ?? '',
					'type'        => $f['type'] ?? '',
					'description' => $f['description'] ?? '',
					'required'    => (bool) ( $f['required'] ?? false ),
				];
			}
			return [ 'action' => 'list_fields', 'pod_name' => $pod_name, 'fields' => $fields ];

		case 'get_field':
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $pod_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and field_name are required.', 'e2mconnect' ) );
			}
			$field = $api->load_field( [ 'pod' => $pod_name, 'name' => $field_name ] );
			if ( empty( $field ) ) {
				return new WP_Error( 'field_not_found', sprintf( __( 'Field "%s" not found in pod "%s".', 'e2mconnect' ), $field_name, $pod_name ) );
			}
			return [ 'action' => 'get_field', 'pod_name' => $pod_name, 'field' => (array) $field ];

		case 'create_field':
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $pod_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and field_name are required.', 'e2mconnect' ) );
			}
			$params = array_merge(
				is_array( $input['field_settings'] ?? null ) ? $input['field_settings'] : [],
				[
					'pod'         => $pod_name,
					'name'        => $field_name,
					'label'       => sanitize_text_field( $input['label'] ?? ucwords( str_replace( '_', ' ', $field_name ) ) ),
					'type'        => sanitize_key( $input['field_type'] ?? 'text' ),
					'description' => sanitize_textarea_field( $input['description'] ?? '' ),
				]
			);
			$field_id = $api->save_field( $params );
			if ( is_wp_error( $field_id ) ) {
				return $field_id;
			}
			return [ 'action' => 'create_field', 'pod_name' => $pod_name, 'field_name' => $field_name, 'id' => (int) $field_id ];

		case 'update_field':
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $pod_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and field_name are required.', 'e2mconnect' ) );
			}
			$field = $api->load_field( [ 'pod' => $pod_name, 'name' => $field_name ] );
			if ( empty( $field ) ) {
				return new WP_Error( 'field_not_found', sprintf( __( 'Field "%s" not found in pod "%s".', 'e2mconnect' ), $field_name, $pod_name ) );
			}
			$params = array_merge(
				(array) $field,
				is_array( $input['field_settings'] ?? null ) ? $input['field_settings'] : [],
				[ 'pod' => $pod_name, 'name' => $field_name ]
			);
			if ( isset( $input['label'] ) ) {
				$params['label'] = sanitize_text_field( $input['label'] );
			}
			if ( isset( $input['description'] ) ) {
				$params['description'] = sanitize_textarea_field( $input['description'] );
			}
			$api->save_field( $params );
			return [ 'action' => 'update_field', 'pod_name' => $pod_name, 'field_name' => $field_name, 'updated' => true ];

		case 'delete_field':
			$field_name = sanitize_key( $input['field_name'] ?? '' );
			if ( $pod_name === '' || $field_name === '' ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and field_name are required.', 'e2mconnect' ) );
			}
			$api->delete_field( [ 'pod' => $pod_name, 'name' => $field_name ] );
			return [ 'action' => 'delete_field', 'pod_name' => $pod_name, 'field_name' => $field_name, 'deleted' => true ];

		// ════════════════════════════════════════════════════════════════════
		// ITEM OPERATIONS
		// ════════════════════════════════════════════════════════════════════

		case 'list_items':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$limit    = min( max( 1, (int) ( $input['limit'] ?? 20 ) ), 200 );
			$offset   = max( 0, (int) ( $input['offset'] ?? 0 ) );
			$order_by = sanitize_text_field( $input['order_by'] ?? 't.ID' );
			$order    = strtoupper( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
			$where    = isset( $input['where'] ) ? sanitize_text_field( $input['where'] ) : '';

			$pod_obj = pods( $pod_name );
			if ( ! $pod_obj || ! $pod_obj->valid() ) {
				return new WP_Error( 'pod_not_found', sprintf( __( 'Pod "%s" not found.', 'e2mconnect' ), $pod_name ) );
			}

			$find_params = [
				'limit'   => $limit,
				'offset'  => $offset,
				'orderby' => $order_by . ' ' . $order,
			];
			if ( $where !== '' ) {
				$find_params['where'] = $where;
			}

			$pod_obj->find( $find_params );
			$items = [];
			while ( $pod_obj->fetch() ) {
				$items[] = $pod_obj->export();
			}

			return [
				'action'   => 'list_items',
				'pod_name' => $pod_name,
				'items'    => $items,
				'total'    => (int) $pod_obj->total_found(),
			];

		case 'get_item':
			$item_id = (int) ( $input['item_id'] ?? 0 );
			if ( $pod_name === '' || $item_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and item_id are required.', 'e2mconnect' ) );
			}
			$pod_obj = pods( $pod_name, $item_id );
			if ( ! $pod_obj || ! $pod_obj->valid() ) {
				return new WP_Error( 'item_not_found', __( 'Item not found.', 'e2mconnect' ) );
			}
			return [
				'action'   => 'get_item',
				'pod_name' => $pod_name,
				'item'     => $pod_obj->export(),
			];

		case 'create_item':
			if ( $pod_name === '' ) {
				return new WP_Error( 'missing_pod_name', __( 'pod_name is required.', 'e2mconnect' ) );
			}
			$data = is_array( $input['data'] ?? null ) ? $input['data'] : [];
			$id   = $api->save_pod_item( (object) array_merge( $data, [ 'pod' => $pod_name, 'id' => 0 ] ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			return [ 'action' => 'create_item', 'pod_name' => $pod_name, 'id' => (int) $id ];

		case 'update_item':
			$item_id = (int) ( $input['item_id'] ?? 0 );
			if ( $pod_name === '' || $item_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and item_id are required.', 'e2mconnect' ) );
			}
			$data = is_array( $input['data'] ?? null ) ? $input['data'] : [];
			$api->save_pod_item( (object) array_merge( $data, [ 'pod' => $pod_name, 'id' => $item_id ] ) );
			return [ 'action' => 'update_item', 'pod_name' => $pod_name, 'item_id' => $item_id, 'updated' => true ];

		case 'delete_item':
			$item_id = (int) ( $input['item_id'] ?? 0 );
			if ( $pod_name === '' || $item_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'pod_name and item_id are required.', 'e2mconnect' ) );
			}
			$api->delete_pod_item( [ 'pod' => $pod_name, 'id' => $item_id ] );
			return [ 'action' => 'delete_item', 'pod_name' => $pod_name, 'item_id' => $item_id, 'deleted' => true ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}
