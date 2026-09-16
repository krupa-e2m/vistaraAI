<?php
/**
 * E2M Connect — Memory REST routes.
 *
 * REST surface consumed by the React admin app shipped in M5. AI agents
 * use the Abilities API; humans use REST. The endpoints here are
 * intentionally minimal in v0.1.0 -- list, get, restore, hard-delete --
 * and will grow when the React UI lands.
 *
 * All routes namespaced under e2m-os/v1. All write routes require
 * either nonce verification or REST cookie auth -- never both bypassed.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_REST {

	public const NAMESPACE        = 'e2m-os/v1';
	public const DELETE_NONCE_KEY = 'e2m_memory_delete';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		// Settings GET / POST — admin-only.
		register_rest_route(
			self::NAMESPACE,
			'/memory/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'route_get_settings' ],
					'permission_callback' => [ self::class, 'permission_settings_read' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'route_update_settings' ],
					'permission_callback' => [ self::class, 'permission_settings_write' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/(?P<id>\d+)/hard-delete',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'route_hard_delete' ],
				'permission_callback' => [ self::class, 'permission_hard_delete' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Memory list / get / write — consumed by React admin.
		register_rest_route(
			self::NAMESPACE,
			'/memories',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'route_list_memories' ],
					'permission_callback' => [ self::class, 'permission_react_read' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'route_create_memory' ],
					'permission_callback' => [ self::class, 'permission_react_write' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memories/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'route_get_memory' ],
					'permission_callback' => [ self::class, 'permission_react_read' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'route_update_memory' ],
					'permission_callback' => [ self::class, 'permission_react_write' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memories/(?P<id>\d+)/archive',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_archive_memory' ],
				'permission_callback' => [ self::class, 'permission_react_write' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memories/bulk/archive',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_bulk_archive' ],
				'permission_callback' => [ self::class, 'permission_react_write' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memories/bulk/set-status',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_bulk_set_status' ],
				'permission_callback' => [ self::class, 'permission_react_write' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'route_stats' ],
				'permission_callback' => [ self::class, 'permission_react_read' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/pending',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'route_list_pending' ],
				'permission_callback' => [ self::class, 'permission_react_read' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/(?P<id>\d+)/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_approve_pending' ],
				'permission_callback' => [ self::class, 'permission_react_write' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/(?P<id>\d+)/reject',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_reject_pending' ],
				'permission_callback' => [ self::class, 'permission_react_write' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/(?P<id>\d+)/receipt',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'route_get_receipt' ],
				'permission_callback' => [ self::class, 'permission_react_read' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/memory/(?P<id>\d+)/restore',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'route_restore' ],
				'permission_callback' => [ self::class, 'permission_restore' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Hard delete requires manage_options AND a valid nonce. The React
	 * admin app obtains the nonce via wp_localize_script using the
	 * E2M_MEMORY_DELETE_NONCE_KEY action name.
	 */
	public static function permission_hard_delete( WP_REST_Request $request ): bool|WP_Error {
		$cap = e2m_memory_can_hard_delete();
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Invalid nonce.', 'e2mconnect' ), [ 'status' => 403 ] );
		}

		$delete_nonce = $request->get_param( 'delete_nonce' );
		if ( ! is_string( $delete_nonce ) || ! wp_verify_nonce( $delete_nonce, self::DELETE_NONCE_KEY ) ) {
			return new WP_Error(
				'invalid_delete_nonce',
				__( 'A valid delete nonce is required to hard-delete memories.', 'e2mconnect' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public static function route_hard_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		$result = E2M_Memory_Repository::delete_hard( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'id'      => $id,
				'deleted' => true,
			],
			200
		);
	}

	public static function permission_restore( WP_REST_Request $request ): bool|WP_Error {
		$cap = e2m_memory_can_write();
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Invalid nonce.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function route_restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		if ( ! e2m_memory_user_can_edit( $memory ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot restore this memory.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		$result = E2M_Memory_Repository::set_status( $id, 'active' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'id'     => $id,
				'status' => 'active',
			],
			200
		);
	}

	// -------- React-app endpoints --------

	public static function permission_react_read( WP_REST_Request $request ): bool|WP_Error {
		$cap = e2m_memory_can_read();
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Invalid nonce.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function permission_react_write( WP_REST_Request $request ): bool|WP_Error {
		$cap = e2m_memory_can_write();
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Invalid nonce.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function route_list_memories( WP_REST_Request $request ): WP_REST_Response {
		$filters = [];
		foreach ( [ 'type', 'status', 'visibility' ] as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && $value !== '' ) {
				$filters[ $key ] = $value;
			}
		}
		if ( $request->get_param( 'include_archived' ) ) {
			$filters['include_archived'] = true;
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: E2M_Memory_Repository::LIST_DEFAULT_PER_PAGE;
		$per_page = max( 1, min( E2M_Memory_Repository::LIST_MAX_PER_PAGE, $per_page ) );

		$result    = E2M_Memory_Repository::list( $filters, $page, $per_page );
		$memories  = [];
		foreach ( $result['memories'] as $memory ) {
			if ( ! e2m_memory_user_can_see( $memory ) ) {
				continue;
			}
			$memories[] = $memory;
		}

		$conflicts = class_exists( 'E2M_Memory_Conflicts' )
			? E2M_Memory_Conflicts::detect_in_set( $memories )
			: [];

		return new WP_REST_Response(
			[
				'memories'  => $memories,
				'conflicts' => $conflicts,
				'total'     => count( $memories ),
				'page'      => $result['page'],
				'per_page'  => $result['per_page'],
			],
			200
		);
	}

	public static function route_get_memory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null || ! e2m_memory_user_can_see( $memory ) ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $memory, 200 );
	}

	public static function route_create_memory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = self::request_body( $request );
		$type = (string) ( $body['type'] ?? 'context' );
		if ( ( $body['visibility'] ?? '' ) === 'system' && ! e2m_memory_can_escalate_to_system() ) {
			return new WP_Error(
				'system_visibility_requires_admin',
				__( 'Only administrators can create system-visibility memories.', 'e2mconnect' ),
				[ 'status' => 403 ]
			);
		}
		$body['trigger_type'] = $body['trigger_type'] ?? 'explicit_save';
		$body['visibility']   = $body['visibility'] ?? E2M_Memory_CPT::default_visibility_for_type( $type );

		$id = E2M_Memory_Repository::create( $body, get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( E2M_Memory_Repository::get( (int) $id ), 201 );
	}

	public static function route_update_memory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		if ( ! e2m_memory_user_can_edit( $memory ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot edit this memory.', 'e2mconnect' ), [ 'status' => 403 ] );
		}

		$body = self::request_body( $request );
		if ( ( $body['visibility'] ?? '' ) === 'system' && ! e2m_memory_can_escalate_to_system() ) {
			return new WP_Error(
				'system_visibility_requires_admin',
				__( 'Editing system memories requires administrator privileges.', 'e2mconnect' ),
				[ 'status' => 403 ]
			);
		}

		$result = E2M_Memory_Repository::update( $id, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( E2M_Memory_Repository::get( (int) $result ), 200 );
	}

	public static function route_archive_memory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		if ( ! e2m_memory_user_can_edit( $memory ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot archive this memory.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		$result = E2M_Memory_Repository::archive( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $id, 'status' => 'archived' ], 200 );
	}

	public static function route_bulk_archive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = self::request_body( $request );
		$ids  = array_map( 'absint', (array) ( $body['ids'] ?? [] ) );
		$ids  = array_values( array_filter( $ids, static fn ( $i ) => $i > 0 ) );
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'bulk_too_large', __( 'Bulk operations are capped at 100 ids per call.', 'e2mconnect' ), [ 'status' => 400 ] );
		}
		$archived = [];
		$skipped  = [];
		foreach ( $ids as $id ) {
			$memory = E2M_Memory_Repository::get( (int) $id );
			if ( $memory === null || ! e2m_memory_user_can_edit( $memory ) ) {
				$skipped[] = (int) $id;
				continue;
			}
			$result = E2M_Memory_Repository::archive( (int) $id );
			if ( is_wp_error( $result ) ) {
				$skipped[] = (int) $id;
				continue;
			}
			$archived[] = (int) $id;
		}
		return new WP_REST_Response( [ 'archived' => $archived, 'skipped' => $skipped ], 200 );
	}

	public static function route_bulk_set_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body   = self::request_body( $request );
		$ids    = array_map( 'absint', (array) ( $body['ids'] ?? [] ) );
		$ids    = array_values( array_filter( $ids, static fn ( $i ) => $i > 0 ) );
		$status = (string) ( $body['status'] ?? '' );
		if ( ! in_array( $status, E2M_Memory_CPT::get_valid_statuses(), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown status.', 'e2mconnect' ), [ 'status' => 400 ] );
		}
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'bulk_too_large', __( 'Bulk operations are capped at 100 ids per call.', 'e2mconnect' ), [ 'status' => 400 ] );
		}
		$updated = [];
		$skipped = [];
		foreach ( $ids as $id ) {
			$memory = E2M_Memory_Repository::get( (int) $id );
			if ( $memory === null || ! e2m_memory_user_can_edit( $memory ) ) {
				$skipped[] = (int) $id;
				continue;
			}
			$result = E2M_Memory_Repository::set_status( (int) $id, $status );
			if ( is_wp_error( $result ) ) {
				$skipped[] = (int) $id;
				continue;
			}
			$updated[] = (int) $id;
		}
		return new WP_REST_Response( [ 'updated' => $updated, 'skipped' => $skipped, 'status' => $status ], 200 );
	}

	public static function route_stats( WP_REST_Request $request ): WP_REST_Response {
		$by_type = [];
		foreach ( E2M_Memory_CPT::get_valid_types() as $type ) {
			$by_type[ $type ] = E2M_Memory_Repository::count( [ 'type' => $type ] );
		}
		$by_status = [];
		foreach ( E2M_Memory_CPT::get_valid_statuses() as $status ) {
			$by_status[ $status ] = E2M_Memory_Repository::count( [ 'status' => $status, 'include_archived' => true ] );
		}
		$total = E2M_Memory_Repository::count( [] );

		$settings = class_exists( 'E2M_Memory_Settings' )
			? E2M_Memory_Settings::get()
			: [ 'limits' => [ 'soft_cap' => 500, 'hard_cap' => 1000 ] ];

		return new WP_REST_Response(
			[
				'total'     => $total,
				'by_type'   => $by_type,
				'by_status' => $by_status,
				'caps'      => $settings['limits'],
			],
			200
		);
	}

	public static function route_list_pending( WP_REST_Request $request ): WP_REST_Response {
		$memories = E2M_Memory_Pending_Review::list_pending( 0, 50 );
		$visible  = [];
		foreach ( $memories as $memory ) {
			if ( e2m_memory_user_can_see( $memory ) ) {
				$visible[] = $memory;
			}
		}
		return new WP_REST_Response(
			[ 'memories' => $visible, 'total' => count( $visible ) ],
			200
		);
	}

	public static function route_approve_pending( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$result = E2M_Memory_Pending_Review::promote_one( $id, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $id, 'status' => 'active' ], 200 );
	}

	public static function route_reject_pending( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$result = E2M_Memory_Pending_Review::promote_one( $id, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $id, 'status' => 'archived' ], 200 );
	}

	public static function route_get_receipt( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$memory = E2M_Memory_Repository::get( $id );
		if ( $memory === null || ! e2m_memory_user_can_see( $memory ) ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		$audit_id = (int) ( $memory['source_audit_id'] ?? 0 );
		if ( $audit_id <= 0 ) {
			return new WP_REST_Response(
				[
					'is_pruned'        => true,
					'graceful_message' => __( 'No source audit entry was recorded for this memory.', 'e2mconnect' ),
				],
				200
			);
		}

		global $wpdb;
		$table = E2M_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $audit_id ) );

		if ( $row === null ) {
			return new WP_REST_Response(
				[
					'is_pruned'        => true,
					'graceful_message' => __( 'Source conversation no longer available — entry pruned beyond the retention window.', 'e2mconnect' ),
				],
				200
			);
		}

		$metadata = $row->metadata ? json_decode( (string) $row->metadata, true ) : [];

		$show_ip = current_user_can( 'manage_options' );

		return new WP_REST_Response(
			[
				'is_pruned'        => false,
				'audit_id'         => (int) $row->id,
				'timestamp_gmt'    => $row->timestamp_gmt,
				'user_login'       => $row->user_login,
				'ip'               => $show_ip ? $row->ip : null,
				'ability_name'     => $row->ability_name,
				'http_method'      => $row->http_method,
				'input_hash'       => $row->input_hash,
				'metadata'         => $metadata,
			],
			200
		);
	}

	private static function request_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return is_array( $body ) ? $body : [];
	}

	// -------- Settings endpoints --------

	public static function permission_settings_read( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Administrator privileges required.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function permission_settings_write( WP_REST_Request $request ): bool|WP_Error {
		$cap = self::permission_settings_read( $request );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Invalid nonce.', 'e2mconnect' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function route_get_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( E2M_Memory_Settings::get(), 200 );
	}

	public static function route_update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', __( 'Expected a JSON object.', 'e2mconnect' ), [ 'status' => 400 ] );
		}

		$next = E2M_Memory_Settings::update( $body );
		return new WP_REST_Response( $next, 200 );
	}
}
