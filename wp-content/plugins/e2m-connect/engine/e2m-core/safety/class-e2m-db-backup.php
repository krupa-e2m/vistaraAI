<?php
/**
 * E2M Connect MCP - Scoped Database Backup.
 *
 * Captures a point-in-time copy of a small, well-defined set of database rows
 * (a post + its meta, named options, a term + its meta, a user + its meta)
 * before a task changes them, and can restore that exact row-set later. This
 * is the "agent safety before any task" net for DB/content changes; the scope
 * is computed by E2M_Risk_Classifier so we never dump whole tables.
 *
 * Scope is a list of { table, where, args } items where `table` is a LOGICAL
 * key (posts, postmeta, options, terms, ...) resolved to the real, prefixed
 * table at runtime - so a backup stays portable across table prefixes/hosts.
 *
 * Restore is point-in-time exact for the scoped set: each item's row-set is
 * DELETEd and the saved rows re-INSERTed, which both reverts changed values
 * and removes rows added after the backup. Restore is reversible (the current
 * scoped state is backed up first; its undo id is returned).
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_DB_Backup {

	/**
	 * Capture the rows described by $scope to a JSON file in the store.
	 *
	 * @param array<int, array<string, mixed>> $scope
	 * @return string Backup record id, or '' when nothing was captured / failed.
	 */
	public static function backup_scope( array $scope, string $reason = '', string $task_id = '' ): string {
		if ( empty( $scope ) ) {
			return '';
		}
		if ( ! self::enabled() || ! E2M_Backup_Store::ensure_protected() ) {
			return '';
		}

		global $wpdb;
		$tables = [];
		$total  = 0;

		foreach ( $scope as $item ) {
			$logical = (string) ( $item['table'] ?? '' );
			$real    = self::resolve_table( $logical );
			$where   = (string) ( $item['where'] ?? '' );
			$args    = array_values( (array) ( $item['args'] ?? [] ) );
			if ( $real === '' || $where === '' ) {
				continue;
			}

			$sql = "SELECT * FROM `$real` WHERE $where"; // table+where are ours, not user input
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];

			$tables[] = [
				'table' => $logical,
				'where' => $where,
				'args'  => $args,
				'rows'  => $rows,
			];
			$total += count( $rows );
		}

		if ( empty( $tables ) ) {
			return '';
		}

		$payload = [
			'captured_at' => gmdate( 'c' ),
			'row_count'   => $total,
			'tables'      => $tables,
		];

		$rel_dest = 'db/scope_' . E2M_Backup_Store::timestamp_slug() . '-'
			. substr( (string) wp_generate_password( 10, false, false ), 0, 8 ) . '.json';
		$abs_dest = E2M_Backup_Store::dir() . $rel_dest;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
		if ( @file_put_contents( $abs_dest, (string) wp_json_encode( $payload ), LOCK_EX ) === false ) {
			return '';
		}

		return E2M_Backup_Store::record( [
			'type'        => E2M_Backup_Store::TYPE_DB,
			'reason'      => $reason,
			'target'      => self::scope_label( $tables ),
			'backup_path' => $rel_dest,
			'task_id'     => $task_id,
			'meta'        => [
				'row_count' => $total,
				'tables'    => array_map( static fn ( $t ) => (string) $t['table'], $tables ),
				'rel_path'  => 'wp-content/' . str_replace( trailingslashit( WP_CONTENT_DIR ), '', $abs_dest ),
			],
		] );
	}

	/**
	 * Restore a scoped DB backup by record id. Reversible.
	 *
	 * @return array{restored: bool, row_count: int, undo_id: string}|WP_Error
	 */
	public static function restore( string $backup_id ) {
		$record = E2M_Backup_Store::get( $backup_id );
		if ( $record === null || ( $record['type'] ?? '' ) !== E2M_Backup_Store::TYPE_DB ) {
			return new WP_Error( 'e2m_backup_not_found', __( 'Database backup not found.', 'e2mconnect' ) );
		}

		$payload_path = E2M_Backup_Store::absolute_path( $record );
		if ( $payload_path === '' || ! is_file( $payload_path ) ) {
			return new WP_Error( 'e2m_backup_payload_missing', __( 'Database backup file is missing on disk.', 'e2mconnect' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		$payload = json_decode( (string) @file_get_contents( $payload_path ), true );
		if ( ! is_array( $payload ) || ! is_array( $payload['tables'] ?? null ) ) {
			return new WP_Error( 'e2m_backup_corrupt', __( 'Database backup file could not be decoded.', 'e2mconnect' ) );
		}

		// Reconstruct the scope so the current state can be snapshotted (undo).
		$scope = array_map(
			static fn ( $t ) => [ 'table' => $t['table'] ?? '', 'where' => $t['where'] ?? '', 'args' => $t['args'] ?? [] ],
			$payload['tables']
		);
		$undo_id = self::backup_scope( $scope, 'pre-restore:' . $backup_id );

		global $wpdb;
		$restored = 0;

		foreach ( $payload['tables'] as $entry ) {
			$real  = self::resolve_table( (string) ( $entry['table'] ?? '' ) );
			$where = (string) ( $entry['where'] ?? '' );
			$args  = array_values( (array) ( $entry['args'] ?? [] ) );
			$rows  = is_array( $entry['rows'] ?? null ) ? $entry['rows'] : [];
			if ( $real === '' || $where === '' ) {
				continue;
			}

			// Clear the scoped row-set, then re-insert the saved rows so the
			// scope is restored exactly (reverts edits AND removes additions).
			$del = "DELETE FROM `$real` WHERE $where";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $args ? $wpdb->prepare( $del, $args ) : $del );

			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert( $real, $row );
					++$restored;
				}
			}

			// Raw SQL bypasses WordPress' object caches; invalidate them so
			// get_option()/get_post_meta()/etc. reflect the restored rows.
			self::flush_caches( (string) ( $entry['table'] ?? '' ), $args );
		}

		return [ 'restored' => true, 'row_count' => $restored, 'undo_id' => $undo_id ];
	}

	/**
	 * Invalidate the object caches affected by a restored row-set.
	 *
	 * @param array<int, mixed> $args The where-clause args (ids or option names).
	 */
	private static function flush_caches( string $logical, array $args ): void {
		switch ( $logical ) {
			case 'options':
				foreach ( $args as $name ) {
					if ( is_string( $name ) ) {
						wp_cache_delete( $name, 'options' );
						wp_cache_delete( $name, 'notoptions' );
					}
				}
				wp_cache_delete( 'alloptions', 'options' );
				break;
			case 'posts':
			case 'postmeta':
				if ( isset( $args[0] ) && function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( (int) $args[0] );
				}
				break;
			case 'terms':
			case 'termmeta':
			case 'term_taxonomy':
				if ( isset( $args[0] ) && function_exists( 'clean_term_cache' ) ) {
					clean_term_cache( (int) $args[0] );
				}
				break;
			case 'users':
			case 'usermeta':
				if ( isset( $args[0] ) && function_exists( 'clean_user_cache' ) ) {
					clean_user_cache( (int) $args[0] );
				}
				break;
			case 'comments':
			case 'commentmeta':
				if ( isset( $args[0] ) && function_exists( 'clean_comment_cache' ) ) {
					clean_comment_cache( (int) $args[0] );
				}
				break;
		}
	}

	// ── internals ──────────────────────────────────────────────────────────

	private static function enabled(): bool {
		if ( ! function_exists( 'e2m_engine_get_settings' ) ) {
			return true;
		}
		$settings = e2m_engine_get_settings();
		return (bool) ( $settings['db_backup_enabled'] ?? true );
	}

	/**
	 * Map a logical table key to the real, prefixed table name. Returns ''
	 * for unknown keys so callers skip them safely.
	 */
	private static function resolve_table( string $logical ): string {
		global $wpdb;
		$map = [
			'posts'              => $wpdb->posts,
			'postmeta'           => $wpdb->postmeta,
			'options'            => $wpdb->options,
			'terms'              => $wpdb->terms,
			'termmeta'           => $wpdb->termmeta,
			'term_taxonomy'      => $wpdb->term_taxonomy,
			'term_relationships' => $wpdb->term_relationships,
			'users'              => $wpdb->users,
			'usermeta'           => $wpdb->usermeta,
			'comments'           => $wpdb->comments,
			'commentmeta'        => $wpdb->commentmeta,
		];
		return (string) ( $map[ $logical ] ?? '' );
	}

	/**
	 * Human-readable label for the manifest "target" column.
	 *
	 * @param array<int, array<string, mixed>> $tables
	 */
	private static function scope_label( array $tables ): string {
		$parts = [];
		foreach ( $tables as $t ) {
			$parts[] = (string) ( $t['table'] ?? '' ) . '(' . count( (array) ( $t['rows'] ?? [] ) ) . ')';
		}
		return implode( ', ', array_filter( $parts ) );
	}
}
