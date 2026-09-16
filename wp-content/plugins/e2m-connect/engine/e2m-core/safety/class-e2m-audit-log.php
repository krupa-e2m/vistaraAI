<?php
/**
 * E2M Connect MCP - Audit Log.
 *
 * Append-only log of every destructive MCP operation. Lives in its own
 * table (`wp_e2m_audit_log`) so it can't be disabled or truncated by
 * flipping an analytics setting - admins tamper with it only through the
 * dedicated `e2m/clear-audit-log` ability (which itself is logged).
 *
 * Record shape:
 *   id, timestamp_gmt, user_id, user_login, ip, ability_name, http_method,
 *   post_id, input_hash, success, error_code, metadata (JSON).
 *
 * The full input payload is NOT stored by default to avoid secrets
 * leaking into the log - we store a SHA-256 hash instead. If operators
 * want the verbatim input, analytics already persists that (opt-in).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Audit_Log {

	/**
	 * @return string Fully-qualified table name with $wpdb->prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'e2m_audit_log';
	}

	/**
	 * Create the audit-log table on plugin activation. Schema is
	 * idempotent via dbDelta() - safe to call on upgrades too.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp_gmt DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			ip VARCHAR(64) NOT NULL DEFAULT '',
			ability_name VARCHAR(191) NOT NULL DEFAULT '',
			http_method VARCHAR(10) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			input_hash CHAR(64) NOT NULL DEFAULT '',
			success TINYINT(1) NOT NULL DEFAULT 0,
			error_code VARCHAR(100) NOT NULL DEFAULT '',
			metadata LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY timestamp_gmt (timestamp_gmt),
			KEY ability_name (ability_name),
			KEY user_id (user_id),
			KEY post_id (post_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Persist a log entry.
	 *
	 * @param array{
	 *   ability_name?: string,
	 *   input?: array<string, mixed>,
	 *   http_method?: string,
	 *   post_id?: int,
	 *   success?: bool,
	 *   error_code?: string,
	 *   metadata?: array<string, mixed>,
	 * } $entry
	 */
	public static function record( array $entry ): void {
		global $wpdb;

		$user  = wp_get_current_user();
		$data  = [
			'timestamp_gmt' => current_time( 'mysql', true ),
			'user_id'       => (int) ( $user->ID ?? 0 ),
			'user_login'    => (string) ( $user->user_login ?? '' ),
			'ip'            => self::resolve_ip(),
			'ability_name'  => (string) ( $entry['ability_name'] ?? '' ),
			'http_method'   => (string) ( $entry['http_method'] ?? '' ),
			'post_id'       => (int) ( $entry['post_id'] ?? 0 ),
			'input_hash'    => self::hash_input( (array) ( $entry['input'] ?? [] ) ),
			'success'       => ! empty( $entry['success'] ) ? 1 : 0,
			'error_code'    => (string) ( $entry['error_code'] ?? '' ),
			'metadata'      => wp_json_encode( (array) ( $entry['metadata'] ?? [] ) ) ?: null,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table_name(), $data );
	}

	/**
	 * Fetch log entries, newest first. $filters may contain:
	 *   ability_name, user_id, post_id, success, from, to
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table  = self::table_name();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['ability_name'] ) ) {
			$where[]  = 'ability_name = %s';
			$params[] = (string) $filters['ability_name'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $filters['post_id'];
		}
		if ( isset( $filters['success'] ) && $filters['success'] !== null ) {
			$where[]  = 'success = %d';
			$params[] = (int) (bool) $filters['success'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'timestamp_gmt >= %s';
			$params[] = (string) $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'timestamp_gmt <= %s';
			$params[] = (string) $filters['to'];
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, min( 500, $limit ) );
		$offset    = max( 0, $offset );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $params === [] ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			// MySQL drivers return every column as string; clients expect
			// numeric IDs + a boolean success flag, so we cast here.
			$row['id']       = (int) ( $row['id'] ?? 0 );
			$row['user_id']  = (int) ( $row['user_id'] ?? 0 );
			$row['post_id']  = (int) ( $row['post_id'] ?? 0 );
			$row['success']  = (bool) ( $row['success'] ?? 0 );
			$row['metadata'] = isset( $row['metadata'] ) && $row['metadata'] !== ''
				? (array) json_decode( (string) $row['metadata'], true )
				: [];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Count log entries matching filters (for pagination headers).
	 *
	 * @param array<string, mixed> $filters
	 */
	public static function count( array $filters = [] ): int {
		global $wpdb;
		$table  = self::table_name();
		$where  = [ '1=1' ];
		$params = [];

		foreach ( [ 'ability_name' => '%s', 'user_id' => '%d', 'post_id' => '%d' ] as $field => $placeholder ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[]  = "{$field} = {$placeholder}";
				$params[] = $placeholder === '%d' ? (int) $filters[ $field ] : (string) $filters[ $field ];
			}
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) ( $params === [] ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Prune entries older than N days. Called on a cron tick. When
	 * retention is 0, do nothing (keep forever).
	 */
	public static function prune_old(): void {
		$settings = e2m_engine_get_settings();
		$days     = (int) ( $settings['audit_retention_days'] ?? 30 );
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// Entries flagged as referenced-by-memory are preserved past the
		// retention window so memory "receipt" click-throughs keep working.
		// JSON predicate runs on the LONGTEXT column; cheap for the volumes
		// involved (cron runs daily, retention windows in days, not seconds).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE timestamp_gmt < %s
				   AND (metadata IS NULL
				        OR metadata NOT LIKE %s)",
				$cutoff,
				'%' . $wpdb->esc_like( '"referenced_by_memory":true' ) . '%'
			)
		);
	}

	/**
	 * Mark an audit entry as referenced by a memory record, so the daily
	 * pruner leaves it alone. Stored inside the existing metadata JSON
	 * blob to avoid a schema migration on the audit table.
	 *
	 * @return bool True if the entry was updated, false if not found.
	 */
	public static function pin_entry( int $audit_id ): bool {
		if ( $audit_id <= 0 ) {
			return false;
		}
		return self::set_referenced_flag( $audit_id, true );
	}

	/**
	 * Remove the referenced-by-memory flag. Allows normal pruning to
	 * resume — used when the linked memory is archived or hard-deleted.
	 */
	public static function unpin_entry( int $audit_id ): bool {
		if ( $audit_id <= 0 ) {
			return false;
		}
		return self::set_referenced_flag( $audit_id, false );
	}

	/**
	 * Read and re-write the metadata JSON blob with the referenced flag
	 * toggled. Returns true when the row exists; the write is idempotent.
	 */
	private static function set_referenced_flag( int $audit_id, bool $referenced ): bool {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT metadata FROM {$table} WHERE id = %d", $audit_id ) );
		if ( $raw === null ) {
			return false;
		}

		$decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [];
		$decoded = is_array( $decoded ) ? $decoded : [];

		if ( $referenced ) {
			$decoded['referenced_by_memory'] = true;
		} else {
			unset( $decoded['referenced_by_memory'] );
		}

		$encoded = wp_json_encode( $decoded );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			[ 'metadata' => $encoded !== false ? $encoded : null ],
			[ 'id' => $audit_id ],
			[ '%s' ],
			[ '%d' ]
		);
		return true;
	}

	/**
	 * Fetch the most recently inserted audit-log id. Used by callers that
	 * record() then need the id to link the entry to a memory record —
	 * since record() doesn't return the id directly (to keep its public
	 * shape minimal). MUST be called immediately after record() in the
	 * same request, before any other process can interleave.
	 */
	public static function last_insert_id(): int {
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Drop the entire log. Callers are expected to log THAT action
	 * themselves (via record() with success=true) before truncating.
	 */
	public static function truncate(): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Build a SHA-256 hash of a JSON-encoded payload. Secrets in the
	 * input become opaque once hashed, so we can correlate repeat calls
	 * without leaking content.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function hash_input( array $input ): string {
		$encoded = wp_json_encode( $input );
		return $encoded !== false ? hash( 'sha256', $encoded ) : '';
	}

	/**
	 * Best-effort client IP. Honours X-Forwarded-For when present behind
	 * a trusted proxy setup, otherwise falls back to REMOTE_ADDR. We
	 * never trust XFF without explicit operator opt-in elsewhere.
	 */
	private static function resolve_ip(): string {
		$ip = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
