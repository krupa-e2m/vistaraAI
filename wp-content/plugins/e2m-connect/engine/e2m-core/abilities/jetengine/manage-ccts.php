<?php
/**
 * E2M Connect MCP - JetEngine Manage Custom Content Types & Records
 *
 * Manage JetEngine Custom Content Types (CCTs) and their records.
 * CCTs are JetEngine's alternative to WordPress post types — they store
 * definitions in JetEngine's own DB table (status='content-type') and
 * records in dedicated tables: {prefix}jet_cct_{slug}.
 *
 * Access path: jet_engine()->modules->get_module('custom-content-types')->manager
 *
 * Supported operations:
 *   list_types    — list all registered CCTs
 *   get_type      — full CCT definition including its fields
 *   create_type   — create a new CCT with fields
 *   update_type   — update a CCT's label or field list
 *   delete_type   — permanently delete a CCT and all its records
 *   list_records  — query records from a CCT with optional filters
 *   get_record    — fetch a single CCT record by ID
 *   create_record — insert a new record into a CCT
 *   update_record — update fields on an existing record
 *   delete_record — delete a record from a CCT
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/jetengine-manage-ccts', [
	'label'       => __( '[JetEngine] Manage Custom Content Types & Records', 'e2mconnect' ),
	'description' => 'Manage JetEngine Custom Content Types (CCTs) — create/update/delete CCT schemas and perform full CRUD on their records.',
	'category'    => 'e2m-jetengine',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list_types', 'get_type', 'create_type', 'update_type', 'delete_type',
					'list_records', 'get_record', 'create_record', 'update_record', 'delete_record' ],
				'description' => 'Operation to perform.',
			],
			'cct_slug' => [
				'type'        => 'string',
				'description' => 'CCT slug — identifies the content type. Required for type and record operations.',
			],
			'label' => [
				'type'        => 'string',
				'description' => 'Human-readable singular label for the CCT (required for create_type).',
			],
			'plural_label' => [
				'type'        => 'string',
				'description' => 'Plural label for the CCT (e.g. "Products"). Defaults to label + "s".',
			],
			'fields' => [
				'type'        => 'array',
				'description' => 'Array of field definitions for the CCT schema. Each: {name, title, type}.',
				'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'record_id' => [
				'type'        => 'integer',
				'description' => 'Record primary key (cct_id) — required for get_record, update_record, delete_record.',
			],
			'record_data' => [
				'type'                 => 'object',
				'description'          => 'Field values for create_record or update_record. Keys = field names.',
				'additionalProperties' => true,
			],
			'filters' => [
				'type'                 => 'object',
				'description'          => 'Query filters for list_records. Keys = field names, values = filter values.',
				'additionalProperties' => true,
			],
			'limit' => [
				'type'        => 'integer',
				'description' => 'Max number of records to return in list_records. Default 20, max 200.',
				'default'     => 20,
			],
			'offset' => [
				'type'        => 'integer',
				'description' => 'Number of records to skip for pagination. Default 0.',
				'default'     => 0,
			],
			'order_by' => [
				'type'        => 'string',
				'description' => 'Column name to order results by. Default: cct_id.',
			],
			'order' => [
				'type'        => 'string',
				'enum'        => [ 'ASC', 'DESC' ],
				'description' => 'Sort direction. Default: ASC.',
				'default'     => 'ASC',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'      => [ 'type' => 'string' ],
			'types'       => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'type'        => [ 'type' => 'object', 'additionalProperties' => true ],
			'records'     => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'record'      => [ 'type' => 'object', 'additionalProperties' => true ],
			'total'       => [ 'type' => 'integer' ],
			'deleted'     => [ 'type' => 'boolean' ],
			'inserted_id' => [ 'type' => 'integer' ],
			'cct_slug'    => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_jetengine_manage_ccts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'JetEngine: Manage CCTs & Records',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the jetengine-manage-ccts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_jetengine_manage_ccts_ability( array $input ) {
	if ( ! e2m_engine_has_jetengine() ) {
		return new WP_Error( 'jetengine_missing', __( 'JetEngine is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage JetEngine Custom Content Types.', 'e2mconnect' ) );
	}

	// CCT is a JetEngine module — access via modules manager.
	$cct_module = jet_engine()->modules->get_module( 'custom-content-types' );
	if ( ! $cct_module ) {
		return new WP_Error( 'cct_unavailable', __( 'JetEngine Custom Content Types module is not active or available.', 'e2mconnect' ) );
	}

	// Access path: module -> instance (Jet_Engine\Modules\Custom_Content_Types\Module) -> manager (Manager)
	$manager = $cct_module->instance->manager ?? null;

	$action = sanitize_key( $input['action'] ?? 'list_types' );

	switch ( $action ) {

		// ── List all CCTs ────────────────────────────────────────────────────
		case 'list_types':
			$rows   = e2m_engine_je_cct_get_all_types( $manager );
			$result = [];
			foreach ( $rows as $row ) {
				$args   = e2m_engine_je_cct_maybe_unserialize( $row['args'] ?? [] );
				$fields = e2m_engine_je_cct_maybe_unserialize( $row['meta_fields'] ?? [] );
				$result[] = [
					'id'          => (int) ( $row['id'] ?? 0 ),
					'slug'        => $args['slug'] ?? ( $row['slug'] ?? '' ),
					'name'        => $args['name'] ?? '',
					'field_count' => count( (array) $fields ),
				];
			}
			return [ 'action' => 'list_types', 'types' => $result ];

		// ── Get one CCT definition ───────────────────────────────────────────
		case 'get_type':
			$slug = sanitize_key( $input['cct_slug'] ?? '' );
			if ( $slug === '' ) {
				return new WP_Error( 'missing_slug', __( 'cct_slug is required.', 'e2mconnect' ) );
			}
			$row = e2m_engine_je_cct_find_by_slug( $manager, $slug );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			$args   = e2m_engine_je_cct_maybe_unserialize( $row['args'] ?? [] );
			$fields = e2m_engine_je_cct_maybe_unserialize( $row['meta_fields'] ?? [] );
			return [
				'action' => 'get_type',
				'type'   => [
					'id'     => (int) ( $row['id'] ?? 0 ),
					'slug'   => $args['slug'] ?? $slug,
					'name'   => $args['name'] ?? '',
					'args'   => $args,
					'fields' => array_values( (array) $fields ),
				],
			];

		// ── Create a CCT ─────────────────────────────────────────────────────
		case 'create_type':
			if ( empty( $input['cct_slug'] ) || empty( $input['label'] ) ) {
				return new WP_Error( 'missing_fields', __( 'cct_slug and label are required to create a CCT.', 'e2mconnect' ) );
			}
			$slug         = str_replace( '-', '_', sanitize_key( $input['cct_slug'] ) );
			$label        = sanitize_text_field( $input['label'] );
			$plural_label = sanitize_text_field( $input['plural_label'] ?? $label . 's' );
			$fields       = [];
			foreach ( (array) ( $input['fields'] ?? [] ) as $idx => $fdef ) {
				if ( is_array( $fdef ) ) {
					$fields[] = e2m_engine_je_prepare_cct_field( $fdef, $idx );
				}
			}

			$args = [
				'slug'                  => $slug,
				'name'                  => $label,
				'singular_name'         => $label,
				'plural_name'           => $plural_label,
				'has_single'            => false,
				'rest_get_enabled'      => false,
				'rest_put_enabled'      => false,
				'rest_post_enabled'     => false,
				'rest_delete_enabled'   => false,
				'create_index'          => false,
				'position'              => '-1',
				'capability'            => 'manage_options',
			];

			if ( $manager && isset( $manager->data ) ) {
				$manager->data->update_item_in_db( [
					'slug'        => $slug,
					'status'      => 'content-type',
					'labels'      => null,
					'args'        => $args,
					'meta_fields' => $fields,
				] );
			}

			return [ 'action' => 'create_type', 'cct_slug' => $slug, 'label' => $label ];

		// ── Update a CCT definition ──────────────────────────────────────────
		case 'update_type':
			$slug = sanitize_key( $input['cct_slug'] ?? '' );
			if ( $slug === '' ) {
				return new WP_Error( 'missing_slug', __( 'cct_slug is required.', 'e2mconnect' ) );
			}
			$row = e2m_engine_je_cct_find_by_slug( $manager, $slug );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			$args = e2m_engine_je_cct_maybe_unserialize( $row['args'] ?? [] );
			if ( isset( $input['label'] ) ) {
				$args['name']          = sanitize_text_field( $input['label'] );
				$args['singular_name'] = $args['name'];
			}
			if ( isset( $input['plural_label'] ) ) {
				$args['plural_name'] = sanitize_text_field( $input['plural_label'] );
			}
			$fields = e2m_engine_je_cct_maybe_unserialize( $row['meta_fields'] ?? [] );
			if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
				$fields = [];
				foreach ( $input['fields'] as $idx => $fdef ) {
					$fields[] = e2m_engine_je_prepare_cct_field( (array) $fdef, $idx );
				}
			}

			if ( $manager && isset( $manager->data ) ) {
				$manager->data->update_item_in_db( array_merge( $row, [
					'args'        => $args,
					'meta_fields' => $fields,
				] ) );
			}

			return [ 'action' => 'update_type', 'cct_slug' => $slug ];

		// ── Delete a CCT ─────────────────────────────────────────────────────
		case 'delete_type':
			$slug = sanitize_key( $input['cct_slug'] ?? '' );
			if ( $slug === '' ) {
				return new WP_Error( 'missing_slug', __( 'cct_slug is required.', 'e2mconnect' ) );
			}
			$row = e2m_engine_je_cct_find_by_slug( $manager, $slug );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			if ( $manager && isset( $manager->data ) ) {
				// Use JetEngine's own DB layer to delete the CCT definition row.
				$manager->data->db->delete( $manager->data->table, [ 'id' => (int) $row['id'] ], [ '%d' ] );
				$manager->data->reset_raw_cache();
			}
			// Drop the CCT's data table.
			global $wpdb;
			$table = $wpdb->prefix . 'jet_cct_' . $slug;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			return [ 'action' => 'delete_type', 'cct_slug' => $slug, 'deleted' => true ];

		// ── List records ─────────────────────────────────────────────────────
		case 'list_records':
			$slug = sanitize_key( $input['cct_slug'] ?? '' );
			if ( $slug === '' ) {
				return new WP_Error( 'missing_slug', __( 'cct_slug is required.', 'e2mconnect' ) );
			}
			$limit    = min( max( 1, (int) ( $input['limit'] ?? 20 ) ), 200 );
			$offset   = max( 0, (int) ( $input['offset'] ?? 0 ) );
			$order_by = sanitize_key( $input['order_by'] ?? 'cct_id' );
			$order    = strtoupper( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
			$filters  = is_array( $input['filters'] ?? null ) ? $input['filters'] : [];
			$records  = e2m_engine_je_query_records( $slug, $limit, $offset, $order_by, $order, $filters );
			if ( is_wp_error( $records ) ) {
				return $records;
			}
			return [
				'action'   => 'list_records',
				'cct_slug' => $slug,
				'records'  => $records['rows'],
				'total'    => $records['total'],
			];

		// ── Get a single record ───────────────────────────────────────────────
		case 'get_record':
			$slug      = sanitize_key( $input['cct_slug'] ?? '' );
			$record_id = (int) ( $input['record_id'] ?? 0 );
			if ( $slug === '' || $record_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'cct_slug and record_id are required.', 'e2mconnect' ) );
			}
			$record = e2m_engine_je_get_record( $slug, $record_id );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			return [ 'action' => 'get_record', 'cct_slug' => $slug, 'record' => $record ];

		// ── Create a record ───────────────────────────────────────────────────
		case 'create_record':
			$slug        = sanitize_key( $input['cct_slug'] ?? '' );
			$record_data = is_array( $input['record_data'] ?? null ) ? $input['record_data'] : [];
			if ( $slug === '' ) {
				return new WP_Error( 'missing_slug', __( 'cct_slug is required.', 'e2mconnect' ) );
			}
			$inserted_id = e2m_engine_je_insert_record( $slug, $record_data );
			if ( is_wp_error( $inserted_id ) ) {
				return $inserted_id;
			}
			return [ 'action' => 'create_record', 'cct_slug' => $slug, 'inserted_id' => $inserted_id ];

		// ── Update a record ───────────────────────────────────────────────────
		case 'update_record':
			$slug        = sanitize_key( $input['cct_slug'] ?? '' );
			$record_id   = (int) ( $input['record_id'] ?? 0 );
			$record_data = is_array( $input['record_data'] ?? null ) ? $input['record_data'] : [];
			if ( $slug === '' || $record_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'cct_slug and record_id are required.', 'e2mconnect' ) );
			}
			$result = e2m_engine_je_update_record( $slug, $record_id, $record_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return [ 'action' => 'update_record', 'cct_slug' => $slug, 'record_id' => $record_id, 'updated' => true ];

		// ── Delete a record ───────────────────────────────────────────────────
		case 'delete_record':
			$slug      = sanitize_key( $input['cct_slug'] ?? '' );
			$record_id = (int) ( $input['record_id'] ?? 0 );
			if ( $slug === '' || $record_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'cct_slug and record_id are required.', 'e2mconnect' ) );
			}
			$result = e2m_engine_je_delete_record( $slug, $record_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return [ 'action' => 'delete_record', 'cct_slug' => $slug, 'record_id' => $record_id, 'deleted' => true ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}

/**
 * Get all CCT type rows from JetEngine's DB.
 *
 * @param mixed $manager
 * @return array<int, array<string,mixed>>
 */
function e2m_engine_je_cct_get_all_types( $manager ): array {
	if ( $manager && isset( $manager->data ) && method_exists( $manager->data, 'get_items' ) ) {
		$rows = $manager->data->get_items();
		return is_array( $rows ) ? $rows : [];
	}
	return [];
}

/**
 * Find a CCT row by slug.
 *
 * @param mixed  $manager
 * @param string $slug
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_je_cct_find_by_slug( $manager, string $slug ) {
	$rows = e2m_engine_je_cct_get_all_types( $manager );
	foreach ( $rows as $row ) {
		$args = e2m_engine_je_cct_maybe_unserialize( $row['args'] ?? [] );
		if ( ( $args['slug'] ?? '' ) === $slug || ( $row['slug'] ?? '' ) === $slug ) {
			return $row;
		}
	}
	return new WP_Error( 'cct_not_found', sprintf(
		/* translators: %s: CCT slug */
		__( 'Custom Content Type "%s" was not found.', 'e2mconnect' ),
		$slug
	) );
}

/**
 * Safely unserialize a value that may be a serialized string or already an array.
 *
 * @param mixed $value
 * @return array<string,mixed>
 */
function e2m_engine_je_cct_maybe_unserialize( $value ): array {
	if ( is_array( $value ) ) {
		return $value;
	}
	if ( is_string( $value ) ) {
		$unserialized = maybe_unserialize( $value );
		return is_array( $unserialized ) ? $unserialized : [];
	}
	return [];
}

/**
 * Get the DB table name for CCT records.
 *
 * @param string $slug
 * @return string
 */
function e2m_engine_je_cct_table( string $slug ): string {
	global $wpdb;
	return $wpdb->prefix . 'jet_cct_' . $slug;
}

/**
 * Query records from a CCT table.
 *
 * @return array{rows: list<array>, total: int}|WP_Error
 */
function e2m_engine_je_query_records( string $slug, int $limit, int $offset, string $order_by, string $order, array $filters ) {
	global $wpdb;

	$table         = e2m_engine_je_cct_table( $slug );
	$order_by_safe = preg_replace( '/[^a-z0-9_]/', '', $order_by );
	$order_safe    = $order === 'DESC' ? 'DESC' : 'ASC';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

	$where_parts = [];
	$where_vals  = [];
	foreach ( $filters as $col => $val ) {
		$col_safe      = preg_replace( '/[^a-z0-9_]/', '', (string) $col );
		$where_parts[] = "`{$col_safe}` = %s";
		$where_vals[]  = $val;
	}
	$where_sql = $where_parts ? ( 'WHERE ' . implode( ' AND ', $where_parts ) ) : '';

	$sql  = "SELECT * FROM `{$table}` {$where_sql} ORDER BY `{$order_by_safe}` {$order_safe} LIMIT %d OFFSET %d";
	$args = array_merge( $where_vals, [ $limit, $offset ] );
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	// phpcs:enable

	return [
		'rows'  => is_array( $rows ) ? $rows : [],
		'total' => $total,
	];
}

/**
 * Get a single CCT record by ID.
 *
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_je_get_record( string $slug, int $record_id ) {
	global $wpdb;
	$table  = e2m_engine_je_cct_table( $slug );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `cct_id` = %d", $record_id ), ARRAY_A );
	if ( ! $record ) {
		return new WP_Error( 'record_not_found', __( 'CCT record not found.', 'e2mconnect' ) );
	}
	return $record;
}

/**
 * Insert a new record into a CCT table.
 *
 * @return int|WP_Error
 */
function e2m_engine_je_insert_record( string $slug, array $data ) {
	global $wpdb;
	if ( ! isset( $data['cct_created'] ) ) {
		$data['cct_created'] = current_time( 'mysql' );
	}
	if ( ! isset( $data['cct_modified'] ) ) {
		$data['cct_modified'] = current_time( 'mysql' );
	}
	if ( ! isset( $data['cct_status'] ) ) {
		$data['cct_status'] = 'publish';
	}
	$table  = e2m_engine_je_cct_table( $slug );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$result = $wpdb->insert( $table, $data );
	if ( $result === false ) {
		return new WP_Error( 'db_error', __( 'Failed to insert CCT record.', 'e2mconnect' ) );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Update an existing CCT record.
 *
 * @return true|WP_Error
 */
function e2m_engine_je_update_record( string $slug, int $record_id, array $data ) {
	global $wpdb;
	$data['cct_modified'] = current_time( 'mysql' );
	$table  = e2m_engine_je_cct_table( $slug );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$result = $wpdb->update( $table, $data, [ 'cct_id' => $record_id ] );
	if ( $result === false ) {
		return new WP_Error( 'db_error', __( 'Failed to update CCT record.', 'e2mconnect' ) );
	}
	return true;
}

/**
 * Delete a CCT record.
 *
 * @return true|WP_Error
 */
function e2m_engine_je_delete_record( string $slug, int $record_id ) {
	global $wpdb;
	$table  = e2m_engine_je_cct_table( $slug );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$result = $wpdb->delete( $table, [ 'cct_id' => $record_id ] );
	if ( $result === false ) {
		return new WP_Error( 'db_error', __( 'Failed to delete CCT record.', 'e2mconnect' ) );
	}
	return true;
}

/**
 * Build a sanitised JetEngine CCT field definition.
 *
 * @param array<string, mixed> $def
 * @param int                  $order
 * @return array<string, mixed>
 */
function e2m_engine_je_prepare_cct_field( array $def, int $order ): array {
	$name  = sanitize_key( $def['name'] ?? ( 'field_' . $order ) );
	$type  = sanitize_key( $def['type'] ?? 'text' );
	$field = [
		'name'          => $name,
		'title'         => sanitize_text_field( $def['title'] ?? ucfirst( str_replace( '_', ' ', $name ) ) ),
		'type'          => $type,
		'object_type'   => 'field',
		'order'         => $order,
		'is_required'   => (bool) ( $def['is_required'] ?? false ),
		'default_value' => $def['default_value'] ?? '',
		'options'       => [],
	];

	$extra = [ 'options', 'is_array', 'allowed_mime_types', 'repeater-fields', 'width',
		'min_value', 'max_value', 'step', 'placeholder', 'val_format' ];
	foreach ( $extra as $k ) {
		if ( array_key_exists( $k, $def ) ) {
			$field[ $k ] = $def[ $k ];
		}
	}

	return $field;
}
