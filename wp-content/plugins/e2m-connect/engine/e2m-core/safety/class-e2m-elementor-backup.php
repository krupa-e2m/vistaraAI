<?php
/**
 * E2M Connect MCP - Elementor Backup.
 *
 * Two complementary safety layers for Elementor pages:
 *
 *  1. FILE EXPORTS - a timestamped JSON snapshot of the page's full Elementor
 *     state (_elementor_data kept raw/byte-faithful, page settings, version,
 *     post_content) written into the shared backup store. Survives plugin
 *     deactivation and is restorable to any point. This is the file-based
 *     "download the Elementor JSON before editing" the operator asked for, on
 *     top of the in-DB E2M_Meta_Snapshot ring buffer.
 *
 *  2. NATIVE REVISIONS - Elementor saves each editor save as a WordPress post
 *     revision and copies _elementor_data into the revision's meta. We expose
 *     listing + restoring those as a second, zero-cost recovery path.
 *
 * Both restore paths are reversible: they snapshot the page's current state
 * (a fresh file export) before mutating it and return its undo id.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Elementor_Backup {

	private const DATA_KEY     = '_elementor_data';
	private const SETTINGS_KEY = '_elementor_page_settings';
	private const VERSION_KEY  = '_elementor_version';

	/** True when the post carries Elementor builder data. */
	public static function is_elementor_page( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$data = get_post_meta( $post_id, self::DATA_KEY, true );
		return $data !== '' && $data !== null && $data !== [];
	}

	/**
	 * Write a timestamped JSON export of the page's Elementor state to the
	 * backup store. Returns the backup record id, or '' when the post has no
	 * Elementor data / on failure.
	 */
	public static function export_page( int $post_id, string $reason = '', string $task_id = '' ): string {
		if ( ! self::is_elementor_page( $post_id ) ) {
			return '';
		}
		if ( ! E2M_Backup_Store::ensure_protected() ) {
			return '';
		}

		// Keep _elementor_data byte-faithful: store the exact stored string
		// rather than decode/re-encode it (which can reorder keys / lose
		// formatting Elementor relies on).
		$data_raw      = get_post_meta( $post_id, self::DATA_KEY, true );
		$data_raw      = is_string( $data_raw ) ? $data_raw : (string) wp_json_encode( $data_raw );
		$page_settings = get_post_meta( $post_id, self::SETTINGS_KEY, true );
		$version       = (string) get_post_meta( $post_id, self::VERSION_KEY, true );
		$post          = get_post( $post_id );

		$export = [
			'post_id'            => $post_id,
			'title'              => $post ? (string) $post->post_title : '',
			'exported_at'        => gmdate( 'c' ),
			'elementor_version'  => $version,
			'elementor_data_raw' => $data_raw,
			'page_settings'      => $page_settings,
			'post_content'       => $post ? (string) $post->post_content : '',
		];

		$slug     = sanitize_file_name( $post ? $post->post_name : (string) $post_id );
		$rel_dest = 'elementor/' . $post_id . '_' . ( $slug !== '' ? $slug : 'page' ) . '__'
			. E2M_Backup_Store::timestamp_slug() . '-'
			. substr( (string) wp_generate_password( 8, false, false ), 0, 6 ) . '.json';

		$abs_dest = E2M_Backup_Store::dir() . $rel_dest;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
		$ok = @file_put_contents( $abs_dest, (string) wp_json_encode( $export, JSON_PRETTY_PRINT ), LOCK_EX );
		if ( $ok === false ) {
			return '';
		}

		return E2M_Backup_Store::record( [
			'type'        => E2M_Backup_Store::TYPE_ELEMENTOR,
			'reason'      => $reason,
			'target'      => (string) $post_id,
			'backup_path' => $rel_dest,
			'task_id'     => $task_id,
			'meta'        => [
				'post_id'  => $post_id,
				'title'    => $export['title'],
				'rel_path' => 'wp-content/' . str_replace( trailingslashit( WP_CONTENT_DIR ), '', $abs_dest ),
				'bytes'    => strlen( $data_raw ),
			],
		] );
	}

	/**
	 * Capture an export only when a destructive Elementor-affecting ability is
	 * about to run on an Elementor page. Mirrors the meta-snapshot's
	 * capture_if_destructive contract; invoked from the gatekeeper.
	 */
	public static function maybe_export_if_destructive( string $ability_name, int $post_id ): string {
		if ( $post_id <= 0 || ! self::is_elementor_page( $post_id ) ) {
			return '';
		}
		if ( self::is_read_only_slug( $ability_name ) ) {
			return '';
		}
		return self::export_page( $post_id, 'pre:' . $ability_name );
	}

	/**
	 * Restore a page from a file export. Reversible (snapshots current state
	 * first). Returns the result array or WP_Error.
	 *
	 * @return array{restored: bool, post_id: int, undo_id: string}|WP_Error
	 */
	public static function restore_export( string $backup_id ) {
		$record = E2M_Backup_Store::get( $backup_id );
		if ( $record === null || ( $record['type'] ?? '' ) !== E2M_Backup_Store::TYPE_ELEMENTOR ) {
			return new WP_Error( 'e2m_backup_not_found', __( 'Elementor backup not found.', 'e2mconnect' ) );
		}

		$payload = E2M_Backup_Store::absolute_path( $record );
		if ( $payload === '' || ! is_file( $payload ) ) {
			return new WP_Error( 'e2m_backup_payload_missing', __( 'Elementor backup file is missing on disk.', 'e2mconnect' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		$export = json_decode( (string) @file_get_contents( $payload ), true );
		if ( ! is_array( $export ) ) {
			return new WP_Error( 'e2m_backup_corrupt', __( 'Elementor backup file could not be decoded.', 'e2mconnect' ) );
		}

		$post_id = (int) ( $export['post_id'] ?? (int) ( $record['target'] ?? 0 ) );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'e2m_backup_target_gone', __( 'The page this backup belongs to no longer exists.', 'e2mconnect' ) );
		}

		$undo_id = self::export_page( $post_id, 'pre-restore:' . $backup_id );

		self::write_state(
			$post_id,
			(string) ( $export['elementor_data_raw'] ?? '' ),
			$export['page_settings'] ?? null,
			(string) ( $export['elementor_version'] ?? '' ),
			array_key_exists( 'post_content', $export ) ? (string) $export['post_content'] : null
		);

		return [ 'restored' => true, 'post_id' => $post_id, 'undo_id' => $undo_id ];
	}

	/**
	 * List native Elementor revisions for a page, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_revisions( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}
		$revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
		$out       = [];
		foreach ( $revisions as $rev ) {
			$data = get_post_meta( $rev->ID, self::DATA_KEY, true );
			$out[] = [
				'revision_id'        => (int) $rev->ID,
				'created_at'         => (string) $rev->post_modified_gmt,
				'author'             => (int) $rev->post_author,
				'has_elementor_data' => ( $data !== '' && $data !== null && $data !== [] ),
			];
		}
		return $out;
	}

	/**
	 * Restore a page's Elementor data from one of its native revisions.
	 * Reversible (snapshots current state first).
	 *
	 * @return array{restored: bool, post_id: int, revision_id: int, undo_id: string}|WP_Error
	 */
	public static function restore_revision( int $post_id, int $revision_id ) {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
		}
		$revision = wp_get_post_revision( $revision_id );
		if ( $revision === null || (int) $revision->post_parent !== $post_id ) {
			return new WP_Error( 'e2m_revision_mismatch', __( 'Revision not found or does not belong to this page.', 'e2mconnect' ) );
		}

		$data = get_post_meta( $revision_id, self::DATA_KEY, true );
		if ( $data === '' || $data === null || $data === [] ) {
			return new WP_Error( 'e2m_revision_no_data', __( 'That revision has no Elementor data to restore.', 'e2mconnect' ) );
		}

		$undo_id = self::export_page( $post_id, 'pre-revision-restore:' . $revision_id );

		$settings = get_post_meta( $revision_id, self::SETTINGS_KEY, true );
		self::write_state(
			$post_id,
			is_string( $data ) ? $data : (string) wp_json_encode( $data ),
			$settings !== '' ? $settings : null,
			'',
			null
		);

		// Let WordPress also track the content restore in its own trail.
		wp_restore_post_revision( $revision_id );

		return [ 'restored' => true, 'post_id' => $post_id, 'revision_id' => $revision_id, 'undo_id' => $undo_id ];
	}

	// ── internals ────────────────────────────────────────────────────────

	/**
	 * Write Elementor state back onto a post and flush its rendered CSS so the
	 * front-end reflects the restore immediately.
	 */
	private static function write_state( int $post_id, string $data_raw, $page_settings, string $version, ?string $post_content ): void {
		if ( $data_raw !== '' ) {
			update_post_meta( $post_id, self::DATA_KEY, wp_slash( $data_raw ) );
		}
		if ( $page_settings !== null ) {
			update_post_meta( $post_id, self::SETTINGS_KEY, is_string( $page_settings ) ? wp_slash( $page_settings ) : $page_settings );
		}
		if ( $version !== '' ) {
			update_post_meta( $post_id, self::VERSION_KEY, $version );
		}
		if ( $post_content !== null ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $post_content ], true );
		}

		self::clear_cache( $post_id );
	}

	/** Flush Elementor's generated CSS for a post (best-effort). */
	private static function clear_cache( int $post_id ): void {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			$plugin->files_manager->clear_cache();
		}
		delete_post_meta( $post_id, '_elementor_css' );
	}

	/** Read-only slug heuristic, mirrored from the gatekeeper. */
	private static function is_read_only_slug( string $slug ): bool {
		$tail = substr( $slug, strrpos( $slug, '/' ) + 1 );
		foreach ( [ 'list-', 'get-', 'read-', 'find-', 'search-', 'detect-', 'discover-', 'validate-', 'export-' ] as $prefix ) {
			if ( str_starts_with( $tail, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
