<?php
/**
 * E2M Connect — Memory Index Table.
 *
 * Denormalized lookup table mirroring hot fields from `e2m_memory` posts
 * and their post-meta. WordPress's meta_query is slow at scale (each filter
 * adds a JOIN against postmeta), so any memory filtering — by status,
 * visibility, type, slug, decay scans — goes through this table instead.
 *
 * The CPT remains the storage of truth for content + revisions. The index
 * is kept in sync via the `save_post_e2m_memory` and `before_delete_post`
 * hooks wired in the repository (E2M_Memory_Repository::sync_index /
 * delete_index_row).
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Index_Table {

	/**
	 * Schema version. Bumped when the table layout changes so the upgrade
	 * routine knows to re-run dbDelta(). dbDelta() is itself idempotent —
	 * this version is for logging + manual recovery.
	 */
	public const SCHEMA_VERSION = '1';

	private const OPTION_INSTALLED_VERSION = 'e2m_memory_index_schema_version';

	/**
	 * @return string Fully-qualified table name with $wpdb->prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'e2m_memory_index';
	}

	/**
	 * Create or upgrade the index table. Safe to call on every activation
	 * and load — dbDelta() diffs the current schema against the desired
	 * one. We also write a schema-version option so we can tell which sites
	 * are upgraded.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// NOTE: dbDelta is finicky about whitespace and key declarations.
		// Two spaces after PRIMARY KEY, KEY identifiers in lowercase.
		$sql = "CREATE TABLE {$table} (
			memory_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'context',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			visibility VARCHAR(20) NOT NULL DEFAULT 'team',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			confidence DECIMAL(3,2) NOT NULL DEFAULT 0.50,
			confirmations INT UNSIGNED NOT NULL DEFAULT 0,
			contradictions INT UNSIGNED NOT NULL DEFAULT 0,
			last_fetched_at DATETIME NULL DEFAULT NULL,
			fetch_count_30d INT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NULL DEFAULT NULL,
			slug VARCHAR(100) NULL DEFAULT NULL,
			source_audit_id BIGINT UNSIGNED NULL DEFAULT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (memory_id),
			UNIQUE KEY slug (slug),
			KEY status_visibility_author (status, visibility, author_id),
			KEY type_status (type, status),
			KEY decay_scan (status, last_fetched_at),
			KEY expires (expires_at),
			KEY author (author_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_INSTALLED_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Whether the index table exists in the database. Used by repository
	 * read paths to fail gracefully on installs that somehow missed the
	 * activation hook (multisite manual code activation, restored DB
	 * without table, etc.) — we self-heal on next admin load.
	 */
	public static function exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Drop the table. Only called from uninstall — never from runtime.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::OPTION_INSTALLED_VERSION );
	}

	/**
	 * Installed schema version, or empty string if never installed.
	 */
	public static function installed_version(): string {
		return (string) get_option( self::OPTION_INSTALLED_VERSION, '' );
	}
}
