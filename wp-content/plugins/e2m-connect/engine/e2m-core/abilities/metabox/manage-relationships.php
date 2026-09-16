<?php
/**
 * E2M Connect MCP - Meta Box Manage Relationships
 *
 * Manage Meta Box object-to-object relationships defined via
 * MB Relationships (bundled in Meta Box AIO or installed as a
 * separate add-on). Uses the MB_Relationships_API::get_connected()
 * and MB_Relationships_API::add() / remove() methods.
 *
 * Supported operations:
 *   list_relationships — list all registered relationship IDs
 *   get_connected      — get objects connected to a given object
 *   add                — add a connection between two objects
 *   remove             — remove a connection between two objects
 *   get_siblings       — get all objects connected to the same "from" object
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/metabox-manage-relationships', [
	'label'       => __( '[Meta Box] Manage Relationships', 'e2mconnect' ),
	'description' => 'Manage Meta Box object-to-object relationships — list defined relationships, query connected objects, and add or remove connections.',
	'category'    => 'e2m-metabox',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list_relationships', 'get_connected', 'add', 'remove', 'get_siblings' ],
				'description' => 'Operation to perform.',
			],
			'relationship_id' => [
				'type'        => 'string',
				'description' => 'The relationship ID as registered with MB Relationships. Required for get_connected, add, remove, get_siblings.',
			],
			'from_id' => [
				'type'        => 'integer',
				'description' => '"From" object ID (post, term, or user). Used by get_connected, add, remove.',
			],
			'to_id' => [
				'type'        => 'integer',
				'description' => '"To" object ID. Required for add and remove.',
			],
			'direction' => [
				'type'        => 'string',
				'enum'        => [ 'from', 'to' ],
				'description' => 'For get_connected: whether to query objects connected FROM or TO the given ID. Default: from.',
				'default'     => 'from',
			],
			'object_type' => [
				'type'        => 'string',
				'enum'        => [ 'post', 'term', 'user' ],
				'description' => 'Type of the queried object for get_connected. Default: post.',
				'default'     => 'post',
			],
			'limit' => [
				'type'        => 'integer',
				'description' => 'Max number of connected objects to return. Default 50.',
				'default'     => 50,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'           => [ 'type' => 'string' ],
			'relationships'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'connected'        => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'relationship_id'  => [ 'type' => 'string' ],
			'from_id'          => [ 'type' => 'integer' ],
			'to_id'            => [ 'type' => 'integer' ],
			'added'            => [ 'type' => 'boolean' ],
			'removed'          => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_metabox_manage_relationships_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Meta Box: Manage Relationships',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the metabox-manage-relationships ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_metabox_manage_relationships_ability( array $input ) {
	if ( ! e2m_engine_has_metabox() ) {
		return new WP_Error( 'metabox_missing', __( 'Meta Box plugin is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! e2m_engine_has_metabox_relationships() ) {
		return new WP_Error( 'mb_relationships_missing', __( 'MB Relationships add-on is required but not active. Install "MB Relationships" or Meta Box AIO.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Meta Box relationships.', 'e2mconnect' ) );
	}

	$action          = sanitize_key( $input['action'] ?? 'list_relationships' );
	$relationship_id = sanitize_text_field( $input['relationship_id'] ?? '' );
	$from_id         = (int) ( $input['from_id'] ?? 0 );
	$to_id           = (int) ( $input['to_id'] ?? 0 );
	$direction       = in_array( $input['direction'] ?? 'from', [ 'from', 'to' ], true )
		? ( $input['direction'] ?? 'from' )
		: 'from';
	$object_type     = sanitize_key( $input['object_type'] ?? 'post' );
	$limit           = min( max( 1, (int) ( $input['limit'] ?? 50 ) ), 500 );

	switch ( $action ) {

		// ── List all relationship IDs ─────────────────────────────────────────
		case 'list_relationships':
			$ids = e2m_engine_mb_list_relationships();
			return [ 'action' => 'list_relationships', 'relationships' => $ids ];

		// ── Get connected objects ─────────────────────────────────────────────
		case 'get_connected':
			if ( $relationship_id === '' || $from_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'relationship_id and from_id are required.', 'e2mconnect' ) );
			}

			$query_args = [
				'relationship' => [
					'id'   => $relationship_id,
					$direction => $from_id,
				],
				'posts_per_page' => $limit,
			];

			$connected = e2m_engine_mb_get_connected( $relationship_id, $from_id, $direction, $object_type, $limit );
			if ( is_wp_error( $connected ) ) {
				return $connected;
			}

			return [
				'action'          => 'get_connected',
				'relationship_id' => $relationship_id,
				'from_id'         => $from_id,
				'direction'       => $direction,
				'connected'       => $connected,
			];

		// ── Add a connection ──────────────────────────────────────────────────
		case 'add':
			if ( $relationship_id === '' || $from_id <= 0 || $to_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'relationship_id, from_id, and to_id are required.', 'e2mconnect' ) );
			}
			MB_Relationships_API::add( $from_id, $to_id, $relationship_id );
			return [
				'action'          => 'add',
				'relationship_id' => $relationship_id,
				'from_id'         => $from_id,
				'to_id'           => $to_id,
				'added'           => true,
			];

		// ── Remove a connection ───────────────────────────────────────────────
		case 'remove':
			if ( $relationship_id === '' || $from_id <= 0 || $to_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'relationship_id, from_id, and to_id are required.', 'e2mconnect' ) );
			}
			MB_Relationships_API::remove( $from_id, $to_id, $relationship_id );
			return [
				'action'          => 'remove',
				'relationship_id' => $relationship_id,
				'from_id'         => $from_id,
				'to_id'           => $to_id,
				'removed'         => true,
			];

		// ── Get siblings ──────────────────────────────────────────────────────
		case 'get_siblings':
			if ( $relationship_id === '' || $from_id <= 0 ) {
				return new WP_Error( 'missing_fields', __( 'relationship_id and from_id are required.', 'e2mconnect' ) );
			}
			$siblings = e2m_engine_mb_get_siblings( $relationship_id, $from_id, $object_type, $limit );
			if ( is_wp_error( $siblings ) ) {
				return $siblings;
			}
			return [
				'action'          => 'get_siblings',
				'relationship_id' => $relationship_id,
				'from_id'         => $from_id,
				'connected'       => $siblings,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action.', 'e2mconnect' ) );
	}
}

/**
 * Return all registered MB Relationship IDs.
 *
 * @return list<string>
 */
function e2m_engine_mb_list_relationships(): array {
	// MB_Relationships_Registry stores registered relationships.
	if ( class_exists( 'MB_Relationships_Registry' ) ) {
		$registry = MB_Relationships_Registry::instance();
		if ( method_exists( $registry, 'get_all' ) ) {
			return array_keys( (array) $registry->get_all() );
		}
	}

	// Fallback: try the global MB_Relationships_API.
	if ( class_exists( 'MB_Relationships_API' ) && method_exists( 'MB_Relationships_API', 'get_all_relationships' ) ) {
		return array_keys( (array) MB_Relationships_API::get_all_relationships() );
	}

	return [];
}

/**
 * Query objects connected in a relationship using the MB Relationships API.
 *
 * @return list<array<string,mixed>>|WP_Error
 */
function e2m_engine_mb_get_connected( string $rel_id, int $from_id, string $direction, string $object_type, int $limit ) {
	$rel_query = [
		'id'       => $rel_id,
		$direction => $from_id,
	];

	switch ( $object_type ) {
		case 'term':
			$items = get_terms( [
				'relationship' => $rel_query,
				'number'       => $limit,
				'hide_empty'   => false,
			] );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			return array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], (array) $items );

		case 'user':
			$users = get_users( [
				'relationship' => $rel_query,
				'number'       => $limit,
			] );
			return array_map( fn( $u ) => [ 'id' => $u->ID, 'display_name' => $u->display_name, 'email' => $u->user_email ], (array) $users );

		default:
			$posts = get_posts( [
				'relationship'   => $rel_query,
				'posts_per_page' => $limit,
				'post_status'    => 'any',
				'post_type'      => 'any',
			] );
			return array_map( fn( $p ) => [ 'id' => $p->ID, 'title' => $p->post_title, 'type' => $p->post_type, 'status' => $p->post_status ], (array) $posts );
	}
}

/**
 * Get sibling objects (others connected to the same "from" object).
 *
 * @return list<array<string,mixed>>|WP_Error
 */
function e2m_engine_mb_get_siblings( string $rel_id, int $object_id, string $object_type, int $limit ) {
	// Siblings: query objects connected FROM the same parent as $object_id.
	// First find what $object_id is connected TO, then get others with the same "to".
	$parents = e2m_engine_mb_get_connected( $rel_id, $object_id, 'from', $object_type, 50 );
	if ( is_wp_error( $parents ) || empty( $parents ) ) {
		return is_wp_error( $parents ) ? $parents : [];
	}

	// Get all objects connected FROM the first parent (siblings).
	$parent_id = (int) ( $parents[0]['id'] ?? 0 );
	if ( $parent_id <= 0 ) {
		return [];
	}

	return e2m_engine_mb_get_connected( $rel_id, $parent_id, 'from', $object_type, $limit );
}
