<?php
/**
 * E2M Connect — Memory Repository.
 *
 * The single code path that reads or writes memory data. REST handlers,
 * abilities, the React admin app — everything goes through this class.
 *
 * Why a repository: WordPress's meta_query is slow at scale (each filter
 * adds a JOIN against postmeta). The denormalized index table created in
 * E2M_Memory_Index_Table keeps filtering fast. Mixing direct meta
 * access with index-based reads is how stale indexes happen. One
 * repository enforces consistency — index stays in sync because every
 * mutation goes through here.
 *
 * Capability checks are the caller's responsibility (the ability or REST
 * layer). The repository validates shape, sanitizes input, and persists.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-e2m-memory-cpt.php';
require_once __DIR__ . '/class-e2m-memory-index-table.php';
require_once __DIR__ . '/class-e2m-memory-audit-bridge.php';

final class E2M_Memory_Repository {

	public const CONTENT_MIN_LENGTH = 30;
	public const CONTENT_MAX_LENGTH = 2048;
	public const NAME_MAX_LENGTH    = 200;
	public const DESCRIPTION_MIN    = 10;
	public const DESCRIPTION_MAX    = 300;
	public const TAGS_MAX_COUNT     = 10;
	public const LIST_DEFAULT_PER_PAGE = 50;
	public const LIST_MAX_PER_PAGE     = 200;

	// ------------------------------------------------------------------
	// CRUD
	// ------------------------------------------------------------------

	/**
	 * Create a memory.
	 *
	 * @param array<string,mixed> $data Sanitized but not yet validated input.
	 * @param int $author_id User id to attribute the memory to.
	 * @return int|WP_Error Memory id on success.
	 */
	public static function create( array $data, int $author_id ): int|WP_Error {
		$shaped = self::validate_input_shape( $data );
		if ( is_wp_error( $shaped ) ) {
			return $shaped;
		}

		// Slug uniqueness check is at the DB level (UNIQUE KEY) but we
		// pre-check to return a friendlier error and avoid the post being
		// created before the index insert fails.
		if ( ! empty( $shaped['slug'] ) ) {
			$existing = self::get_by_slug( $shaped['slug'] );
			if ( $existing !== null ) {
				return new WP_Error(
					'slug_taken',
					sprintf(
						/* translators: %s: slug */
						__( 'A memory with slug "%s" already exists.', 'e2mconnect' ),
						$shaped['slug']
					),
					[ 'status' => 409 ]
				);
			}
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => E2M_Memory_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $shaped['name'],
				'post_excerpt' => $shaped['description'],
				'post_content' => $shaped['content'],
				'post_author'  => $author_id > 0 ? $author_id : get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::write_meta( (int) $post_id, $shaped );

		// Audit log + receipt linkage. Record the save, capture the audit
		// entry id, persist it on the memory, then sync the index so the
		// source_audit_id is mirrored too.
		$audit_id = E2M_Memory_Audit_Bridge::record_save( (int) $post_id, $shaped, true );
		if ( $audit_id > 0 ) {
			update_post_meta( (int) $post_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, $audit_id );
		}

		self::sync_index( (int) $post_id );

		// Pin state already correct because record_save() included the
		// referenced flag inline; only call reconcile_pin() on later
		// status changes (set_status path).

		return (int) $post_id;
	}

	/**
	 * Update an existing memory. Caller must verify the memory exists and
	 * the user has permission to edit it.
	 *
	 * @param array<string,mixed> $data Fields to update. Omitted fields are left untouched.
	 */
	public static function update( int $memory_id, array $data ): int|WP_Error {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		// Merge incoming data with current state so partial updates work.
		$current = self::get( $memory_id );
		if ( $current === null ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		$merged = array_merge( $current, $data );
		$shaped = self::validate_input_shape( $merged );
		if ( is_wp_error( $shaped ) ) {
			return $shaped;
		}

		// If slug changed, check uniqueness against other memories.
		if ( ! empty( $shaped['slug'] ) && $shaped['slug'] !== ( $current['slug'] ?? '' ) ) {
			$existing = self::get_by_slug( $shaped['slug'] );
			if ( $existing !== null && (int) $existing['id'] !== $memory_id ) {
				return new WP_Error(
					'slug_taken',
					sprintf(
						/* translators: %s: slug */
						__( 'A memory with slug "%s" already exists.', 'e2mconnect' ),
						$shaped['slug']
					),
					[ 'status' => 409 ]
				);
			}
		}

		$result = wp_update_post(
			[
				'ID'           => $memory_id,
				'post_title'   => $shaped['name'],
				'post_excerpt' => $shaped['description'],
				'post_content' => $shaped['content'],
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::write_meta( $memory_id, $shaped );

		// Track who most recently touched this memory. post_author stays
		// fixed on the original creator; META_LAST_EDITED_BY flips to the
		// current user on every successful update. Used by the admin UI
		// to surface provenance ("Created by X, last edited by Y").
		$editor_id = get_current_user_id();
		if ( $editor_id > 0 ) {
			update_post_meta( $memory_id, E2M_Memory_CPT::META_LAST_EDITED_BY, $editor_id );
		}

		// Record the update. We preserve the original source_audit_id —
		// the receipt continues to point at the conversation that birthed
		// the memory, not at every edit. Edits show up in CPT revisions
		// (the post type has 'revisions' support) and the audit log keeps
		// its own row, but the "click to see source" stays anchored.
		E2M_Memory_Audit_Bridge::record_save( $memory_id, $shaped, false );

		self::sync_index( $memory_id );

		return $memory_id;
	}

	/**
	 * Fetch a single memory by id. Returns null if not found.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( int $memory_id ): ?array {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return null;
		}
		return self::shape_from_post( $post );
	}

	/**
	 * Fetch a memory by its slug-callable identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		global $wpdb;

		if ( ! E2M_Memory_Index_Table::exists() ) {
			return null;
		}

		$table = E2M_Memory_Index_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$memory_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT memory_id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );

		return $memory_id > 0 ? self::get( $memory_id ) : null;
	}

	/**
	 * Soft-delete by setting status to archived. The memory and its content
	 * are preserved; reads excluding archived will hide it.
	 */
	public static function archive( int $memory_id ): bool|WP_Error {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		$result = self::set_status( $memory_id, 'archived' );
		if ( ! is_wp_error( $result ) ) {
			E2M_Memory_Audit_Bridge::record_archive( $memory_id );
		}
		return $result;
	}

	/**
	 * Hard delete. Only callable from admin UI paths with manage_options.
	 * Removes the post, all meta, all revisions, and the index row.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_hard( int $memory_id ): bool|WP_Error {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}

		// Capture a small snapshot for the audit log so a deletion record
		// has something useful to look at even though the memory itself
		// is gone. We don't store full content — keep parity with the
		// audit-log's no-secrets-in-the-clear policy.
		$snapshot = [
			'memory_name'       => $post->post_title,
			'memory_type'       => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_TYPE, true ),
			'memory_status'     => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_STATUS, true ),
			'memory_visibility' => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_VISIBILITY, true ),
		];

		// Unpin the linked audit entry before the memory disappears so it
		// follows normal pruning. The record_hard_delete row itself
		// remains for forensic visibility (not flagged as referenced).
		$source_audit_id = (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, true );
		if ( $source_audit_id > 0 ) {
			E2M_Audit_Log::unpin_entry( $source_audit_id );
		}

		E2M_Memory_Audit_Bridge::record_hard_delete( $memory_id, $snapshot );

		// wp_delete_post(force=true) cascades to postmeta and revisions.
		// `before_delete_post` action (wired in bootstrap.php) handles the
		// index-row cleanup so callers don't need to do it manually.
		$result = wp_delete_post( $memory_id, true );
		if ( $result === false || $result === null ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete memory.', 'e2mconnect' ), [ 'status' => 500 ] );
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Read queries — index-driven for speed
	// ------------------------------------------------------------------

	/**
	 * List memories matching filters. Index-driven; returns at most
	 * LIST_MAX_PER_PAGE rows. Caller must apply visibility filtering by
	 * passing the appropriate `visibility` and `author_id` constraints.
	 *
	 * @param array<string,mixed> $filters Supported: type, status (string or array), visibility (string or array),
	 *                                     author_id, has_slug (bool), slug (string), min_confidence (float),
	 *                                     include_archived (bool, default false).
	 * @return array{memories: list<array<string,mixed>>, total: int, page: int, per_page: int}
	 */
	public static function list( array $filters = [], int $page = 1, int $per_page = self::LIST_DEFAULT_PER_PAGE ): array {
		$per_page = max( 1, min( self::LIST_MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		[ $where, $params ] = self::build_where( $filters );
		global $wpdb;
		$table = E2M_Memory_Index_Table::table_name();

		if ( ! E2M_Memory_Index_Table::exists() ) {
			return [ 'memories' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];
		}

		// Count first (cheap on indexed columns).
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $params === [] ? $count_sql : $wpdb->prepare( $count_sql, ...$params ) );

		// Fetch ids ordered by updated_at desc (most recent first).
		$list_sql = "SELECT memory_id FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( $list_sql, ...$list_params ) );

		$memories = [];
		foreach ( $ids as $id ) {
			$shaped = self::get( (int) $id );
			if ( $shaped !== null ) {
				$memories[] = $shaped;
			}
		}

		return [
			'memories' => $memories,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Simple LIKE search. The blueprint M3 milestone replaces this with
	 * embedding-based search; for v1 this is sufficient.
	 *
	 * @param array<string,mixed> $filters Same shape as list() filters.
	 * @return array{matches: list<array<string,mixed>>, query: string}
	 */
	public static function search( string $query, array $filters = [], int $limit = 5 ): array {
		$limit = max( 1, min( 20, $limit ) );
		$q     = trim( $query );

		if ( $q === '' ) {
			return [ 'matches' => [], 'query' => '' ];
		}

		// Combine an index-based filter with a LIKE join into wp_posts.
		global $wpdb;
		$table       = E2M_Memory_Index_Table::table_name();
		$posts_table = $wpdb->posts;

		if ( ! E2M_Memory_Index_Table::exists() ) {
			return [ 'matches' => [], 'query' => $q ];
		}

		[ $where, $params ] = self::build_where( $filters );
		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$sql = "SELECT i.memory_id,
			(CASE WHEN p.post_title LIKE %s THEN 3
				  WHEN p.post_excerpt LIKE %s THEN 2
				  WHEN p.post_content LIKE %s THEN 1 ELSE 0 END) AS score
			FROM {$table} i
			INNER JOIN {$posts_table} p ON p.ID = i.memory_id
			WHERE {$where}
			  AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)
			ORDER BY score DESC, i.updated_at DESC
			LIMIT %d";

		$sql_params = array_merge(
			[ $like, $like, $like ],
			$params,
			[ $like, $like, $like, $limit ]
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$sql_params ) );

		$matches = [];
		foreach ( $rows as $row ) {
			$shaped = self::get( (int) $row->memory_id );
			if ( $shaped !== null ) {
				$shaped['relevance_score'] = (int) $row->score;
				$matches[] = $shaped;
			}
		}

		return [ 'matches' => $matches, 'query' => $q ];
	}

	public static function count( array $filters = [] ): int {
		[ $where, $params ] = self::build_where( $filters );
		global $wpdb;
		$table = E2M_Memory_Index_Table::table_name();

		if ( ! E2M_Memory_Index_Table::exists() ) {
			return 0;
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $params === [] ? $sql : $wpdb->prepare( $sql, ...$params ) );
	}

	// ------------------------------------------------------------------
	// Index maintenance — wired via hooks in bootstrap.php
	// ------------------------------------------------------------------

	/**
	 * Mirror a memory's hot fields into the index table. Called on
	 * save_post_e2m_memory.
	 */
	public static function sync_index( int $memory_id ): void {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return;
		}
		if ( ! E2M_Memory_Index_Table::exists() ) {
			E2M_Memory_Index_Table::maybe_create_table();
		}

		global $wpdb;
		$table = E2M_Memory_Index_Table::table_name();

		$slug = (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_SLUG, true );

		$data = [
			'memory_id'       => $memory_id,
			'type'            => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_TYPE, true ) ?: 'context',
			'status'          => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_STATUS, true ) ?: 'active',
			'visibility'      => (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_VISIBILITY, true ) ?: 'team',
			'author_id'       => (int) $post->post_author,
			'confidence'      => (float) get_post_meta( $memory_id, E2M_Memory_CPT::META_CONFIDENCE, true ),
			'confirmations'   => (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_CONFIRMATIONS, true ),
			'contradictions'  => (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_CONTRADICTIONS, true ),
			'last_fetched_at' => self::sanitize_datetime( (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_LAST_FETCHED_AT, true ) ),
			'expires_at'      => self::sanitize_datetime( (string) get_post_meta( $memory_id, E2M_Memory_CPT::META_EXPIRES_AT, true ) ),
			'slug'            => $slug !== '' ? $slug : null,
			'source_audit_id' => ( (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, true ) ) ?: null,
			'updated_at'      => current_time( 'mysql', true ),
		];

		$formats = [ '%d', '%s', '%s', '%s', '%d', '%f', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ];

		// REPLACE INTO is cleaner than INSERT ... ON DUPLICATE KEY UPDATE
		// since we always write all columns. Atomic per memory_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$wpdb->replace( $table, $data, $formats );
	}

	/**
	 * Remove the index row when a memory is hard-deleted. Wired on
	 * before_delete_post in bootstrap.php.
	 */
	public static function delete_index_row( int $memory_id ): void {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return; // not ours
		}
		if ( ! E2M_Memory_Index_Table::exists() ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( E2M_Memory_Index_Table::table_name(), [ 'memory_id' => $memory_id ], [ '%d' ] );
	}

	// ------------------------------------------------------------------
	// Decay / lifecycle counters
	// ------------------------------------------------------------------

	/**
	 * Update last_fetched_at and bump the 30-day fetch counter. The cron
	 * in Step 10 will reset the counter to the actual count nightly.
	 */
	public static function bump_fetch( int $memory_id ): void {
		$post = get_post( $memory_id );
		if ( ! $post || $post->post_type !== E2M_Memory_CPT::POST_TYPE ) {
			return;
		}

		$now = current_time( 'mysql', true );
		update_post_meta( $memory_id, E2M_Memory_CPT::META_LAST_FETCHED_AT, $now );

		if ( E2M_Memory_Index_Table::exists() ) {
			global $wpdb;
			$table = E2M_Memory_Index_Table::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET last_fetched_at = %s, fetch_count_30d = fetch_count_30d + 1 WHERE memory_id = %d",
					$now,
					$memory_id
				)
			);
		}
	}

	public static function bump_confirmations( int $memory_id ): void {
		$current = (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_CONFIRMATIONS, true );
		update_post_meta( $memory_id, E2M_Memory_CPT::META_CONFIRMATIONS, $current + 1 );
		self::sync_index( $memory_id );
	}

	public static function bump_contradictions( int $memory_id ): void {
		$current = (int) get_post_meta( $memory_id, E2M_Memory_CPT::META_CONTRADICTIONS, true );
		update_post_meta( $memory_id, E2M_Memory_CPT::META_CONTRADICTIONS, $current + 1 );
		self::sync_index( $memory_id );
	}

	public static function set_status( int $memory_id, string $status ): bool|WP_Error {
		if ( ! in_array( $status, E2M_Memory_CPT::get_valid_statuses(), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown status.', 'e2mconnect' ), [ 'status' => 400 ] );
		}
		update_post_meta( $memory_id, E2M_Memory_CPT::META_STATUS, $status );
		// Reconcile the source audit entry's pin: active/always/pinned
		// memories keep their receipts; archived/stale/disputed/pending
		// release them back to normal pruning.
		E2M_Memory_Audit_Bridge::reconcile_pin( $memory_id );
		self::sync_index( $memory_id );
		return true;
	}

	// ------------------------------------------------------------------
	// Shape helpers
	// ------------------------------------------------------------------

	/**
	 * Shape a memory post + its meta into the wire format used by
	 * abilities and REST responses.
	 *
	 * @return array<string,mixed>
	 */
	public static function shape_from_post( WP_Post $post ): array {
		$id = (int) $post->ID;

		// Decode the metadata blob safely.
		$extra_raw = (string) get_post_meta( $id, E2M_Memory_CPT::META_EXTRA, true );
		$extra     = $extra_raw === '' ? [] : (array) json_decode( $extra_raw, true );

		$author_id          = (int) $post->post_author;
		$last_edited_by_id  = (int) get_post_meta( $id, E2M_Memory_CPT::META_LAST_EDITED_BY, true );
		// Memories that have never been edited show post_modified == post_date.
		// Fall back to the author so the UI always has a value to display.
		if ( $last_edited_by_id <= 0 ) {
			$last_edited_by_id = $author_id;
		}

		return [
			'id'                  => $id,
			'name'                => $post->post_title,
			'description'         => $post->post_excerpt,
			'content'             => $post->post_content,
			'author_id'           => $author_id,
			'author'              => self::resolve_user_display( $author_id ),
			'last_edited_by_id'   => $last_edited_by_id,
			'last_edited_by'      => self::resolve_user_display( $last_edited_by_id ),
			'type'                => (string) get_post_meta( $id, E2M_Memory_CPT::META_TYPE, true ) ?: 'context',
			'status'              => (string) get_post_meta( $id, E2M_Memory_CPT::META_STATUS, true ) ?: 'active',
			'visibility'          => (string) get_post_meta( $id, E2M_Memory_CPT::META_VISIBILITY, true ) ?: 'team',
			'confidence'          => (float) get_post_meta( $id, E2M_Memory_CPT::META_CONFIDENCE, true ),
			'confirmations'       => (int) get_post_meta( $id, E2M_Memory_CPT::META_CONFIRMATIONS, true ),
			'contradictions'      => (int) get_post_meta( $id, E2M_Memory_CPT::META_CONTRADICTIONS, true ),
			'last_fetched_at'     => (string) get_post_meta( $id, E2M_Memory_CPT::META_LAST_FETCHED_AT, true ),
			'expires_at'          => (string) get_post_meta( $id, E2M_Memory_CPT::META_EXPIRES_AT, true ),
			'tags'                => (array) get_post_meta( $id, E2M_Memory_CPT::META_TAGS, true ) ?: [],
			'slug'                => (string) get_post_meta( $id, E2M_Memory_CPT::META_SLUG, true ),
			'source_audit_id'     => (int) get_post_meta( $id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, true ),
			'metadata'            => $extra,
			'updated_at'          => $post->post_modified_gmt,
			'created_at'          => $post->post_date_gmt,
		];
	}

	/**
	 * Resolve a user id into a small display payload for the admin UI.
	 * Returns a deterministic shape even for missing / deleted users so
	 * callers never have to null-check.
	 *
	 * @return array{id:int, name:string, avatar_url:string, exists:bool}
	 */
	public static function resolve_user_display( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [
				'id'         => 0,
				'name'       => __( 'System', 'e2mconnect' ),
				'avatar_url' => '',
				'exists'     => false,
			];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			// User was deleted but we still have the id reference; show
			// a stable placeholder so the UI doesn't show "User #0".
			return [
				'id'         => $user_id,
				'name'       => sprintf( /* translators: %d: user id */ __( 'Deleted user (#%d)', 'e2mconnect' ), $user_id ),
				'avatar_url' => '',
				'exists'     => false,
			];
		}

		// Prefer display_name (set by user profile) over user_login.
		$name = (string) ( $user->display_name !== '' ? $user->display_name : $user->user_login );

		return [
			'id'         => $user_id,
			'name'       => $name,
			'avatar_url' => (string) get_avatar_url( $user_id, [ 'size' => 24 ] ),
			'exists'     => true,
		];
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Validate and sanitize input fields. Returns a normalized payload
	 * ready for persistence, or a WP_Error describing the failure.
	 *
	 * Note: this is shape-level only. The richer schema rules (`**Why:**`
	 * for rules-type memories, no relative dates for context, etc.) live
	 * in E2M_Memory_Validator (Step 4).
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_input_shape( array $data ): array|WP_Error {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		if ( $name === '' || mb_strlen( $name ) > self::NAME_MAX_LENGTH ) {
			return new WP_Error( 'invalid_name', __( 'Name is required and must be 1–200 characters.', 'e2mconnect' ), [ 'status' => 400 ] );
		}

		$description = isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : '';
		if ( mb_strlen( $description ) < self::DESCRIPTION_MIN || mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			return new WP_Error(
				'invalid_description',
				/* translators: 1: minimum length, 2: maximum length */
				sprintf( __( 'Description must be %1$d–%2$d characters.', 'e2mconnect' ), self::DESCRIPTION_MIN, self::DESCRIPTION_MAX ),
				[ 'status' => 400 ]
			);
		}

		$content = isset( $data['content'] ) ? wp_kses_post( (string) $data['content'] ) : '';
		$content_len = mb_strlen( wp_strip_all_tags( $content ) );
		if ( $content_len < self::CONTENT_MIN_LENGTH || $content_len > self::CONTENT_MAX_LENGTH ) {
			return new WP_Error(
				'invalid_content_length',
				/* translators: 1: minimum length, 2: maximum length */
				sprintf( __( 'Content must be %1$d–%2$d characters.', 'e2mconnect' ), self::CONTENT_MIN_LENGTH, self::CONTENT_MAX_LENGTH ),
				[ 'status' => 400 ]
			);
		}

		$type = isset( $data['type'] ) ? (string) $data['type'] : 'context';
		if ( ! in_array( $type, E2M_Memory_CPT::get_valid_types(), true ) ) {
			return new WP_Error( 'invalid_type', __( 'Unknown memory type.', 'e2mconnect' ), [ 'status' => 400 ] );
		}

		$status = isset( $data['status'] ) ? (string) $data['status'] : 'active';
		if ( ! in_array( $status, E2M_Memory_CPT::get_valid_statuses(), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown memory status.', 'e2mconnect' ), [ 'status' => 400 ] );
		}

		$visibility = isset( $data['visibility'] )
			? (string) $data['visibility']
			: E2M_Memory_CPT::default_visibility_for_type( $type );
		if ( ! in_array( $visibility, E2M_Memory_CPT::get_valid_visibilities(), true ) ) {
			return new WP_Error( 'invalid_visibility', __( 'Unknown visibility.', 'e2mconnect' ), [ 'status' => 400 ] );
		}

		$confidence = isset( $data['confidence'] ) ? (float) $data['confidence'] : 0.5;
		$confidence = max( 0.0, min( 1.0, $confidence ) );

		$slug = isset( $data['slug'] ) ? strtolower( trim( (string) $data['slug'] ) ) : '';
		if ( $slug !== '' ) {
			if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) || strlen( $slug ) > 100 ) {
				return new WP_Error( 'invalid_slug', __( 'Slug must be lowercase letters, digits, and hyphens (max 100 chars).', 'e2mconnect' ), [ 'status' => 400 ] );
			}
		}

		$tags = [];
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$tags = array_filter( array_map( 'sanitize_title', $data['tags'] ) );
			$tags = array_values( array_unique( $tags ) );
			$tags = array_slice( $tags, 0, self::TAGS_MAX_COUNT );
		}

		$expires_at = isset( $data['expires_at'] ) ? self::sanitize_datetime( (string) $data['expires_at'] ) : null;

		$confirmations  = isset( $data['confirmations'] ) ? max( 0, (int) $data['confirmations'] ) : 0;
		$contradictions = isset( $data['contradictions'] ) ? max( 0, (int) $data['contradictions'] ) : 0;

		$source_audit_id = isset( $data['source_audit_id'] ) ? max( 0, (int) $data['source_audit_id'] ) : 0;

		$metadata = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : [];

		return [
			'name'            => $name,
			'description'     => $description,
			'content'         => $content,
			'type'            => $type,
			'status'          => $status,
			'visibility'      => $visibility,
			'confidence'      => $confidence,
			'confirmations'   => $confirmations,
			'contradictions'  => $contradictions,
			'tags'            => $tags,
			'slug'            => $slug,
			'expires_at'      => $expires_at,
			'source_audit_id' => $source_audit_id,
			'metadata'        => $metadata,
		];
	}

	/**
	 * Persist validated input to post-meta. Caller has already updated
	 * the post itself via wp_insert_post / wp_update_post.
	 *
	 * @param array<string,mixed> $shaped Output of validate_input_shape().
	 */
	private static function write_meta( int $post_id, array $shaped ): void {
		update_post_meta( $post_id, E2M_Memory_CPT::META_TYPE,            $shaped['type'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_STATUS,          $shaped['status'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_VISIBILITY,      $shaped['visibility'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONFIDENCE,      $shaped['confidence'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONFIRMATIONS,   $shaped['confirmations'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONTRADICTIONS,  $shaped['contradictions'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_TAGS,            $shaped['tags'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_SLUG,            $shaped['slug'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, $shaped['source_audit_id'] );

		if ( $shaped['expires_at'] !== null && $shaped['expires_at'] !== '' ) {
			update_post_meta( $post_id, E2M_Memory_CPT::META_EXPIRES_AT, $shaped['expires_at'] );
		} else {
			delete_post_meta( $post_id, E2M_Memory_CPT::META_EXPIRES_AT );
		}

		if ( $shaped['metadata'] !== [] ) {
			$json = wp_json_encode( $shaped['metadata'] );
			update_post_meta( $post_id, E2M_Memory_CPT::META_EXTRA, is_string( $json ) ? $json : '' );
		}
	}

	/**
	 * Build a parameterized WHERE clause for index-table queries. All
	 * placeholders are %d or %s — never raw user input.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{0: string, 1: list<mixed>}
	 */
	private static function build_where( array $filters ): array {
		$conditions = [];
		$params     = [];

		if ( isset( $filters['type'] ) ) {
			$conditions[] = 'type = %s';
			$params[]     = (string) $filters['type'];
		}

		if ( isset( $filters['status'] ) ) {
			$statuses = (array) $filters['status'];
			$statuses = array_values( array_filter( $statuses, 'is_string' ) );
			if ( $statuses !== [] ) {
				$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
				$conditions[] = "status IN ({$placeholders})";
				$params       = array_merge( $params, $statuses );
			}
		} elseif ( empty( $filters['include_archived'] ) ) {
			$conditions[] = "status != %s";
			$params[]     = 'archived';
		}

		if ( isset( $filters['visibility'] ) ) {
			$vis = (array) $filters['visibility'];
			$vis = array_values( array_filter( $vis, 'is_string' ) );
			if ( $vis !== [] ) {
				$placeholders = implode( ',', array_fill( 0, count( $vis ), '%s' ) );
				$conditions[] = "visibility IN ({$placeholders})";
				$params       = array_merge( $params, $vis );
			}
		}

		if ( isset( $filters['author_id'] ) ) {
			$conditions[] = 'author_id = %d';
			$params[]     = (int) $filters['author_id'];
		}

		if ( isset( $filters['has_slug'] ) && $filters['has_slug'] ) {
			$conditions[] = "slug IS NOT NULL AND slug <> ''";
		}

		if ( isset( $filters['slug'] ) && is_string( $filters['slug'] ) && $filters['slug'] !== '' ) {
			$conditions[] = 'slug = %s';
			$params[]     = (string) $filters['slug'];
		}

		if ( isset( $filters['min_confidence'] ) ) {
			$conditions[] = 'confidence >= %f';
			$params[]     = (float) $filters['min_confidence'];
		}

		$where = $conditions === [] ? '1=1' : implode( ' AND ', $conditions );
		return [ $where, $params ];
	}

	/**
	 * Validate and normalize a MySQL DATETIME string. Returns null if the
	 * input is not parseable as a date.
	 */
	private static function sanitize_datetime( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( $ts === false ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
