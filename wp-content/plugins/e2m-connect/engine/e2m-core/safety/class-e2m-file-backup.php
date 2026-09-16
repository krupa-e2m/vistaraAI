<?php
/**
 * E2M Connect MCP - File Backup.
 *
 * Timestamped, per-file backups of theme / plugin / config files the agent
 * is about to write through the file abilities (update-theme-file, edit-file,
 * write-file). WordPress' revision system only protects post content; it does
 * nothing for functions.php, page templates, or any other on-disk file. This
 * class closes that gap.
 *
 * Each backup copies the CURRENT on-disk content (before the write) into the
 * shared backup store and records it in the manifest, so the admin UI and the
 * rollback-file ability can restore any single file to any earlier point. When
 * the agent CREATES a brand-new file there is nothing to copy, so we record a
 * marker (existed=false) and "rollback" deletes the created file instead.
 *
 * Rollback is itself reversible: before restoring, we snapshot the file's
 * current state, so an accidental rollback can be undone.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_File_Backup {

	/**
	 * Back up a file before it is written.
	 *
	 * @param string $abs_path Absolute path of the file about to change.
	 * @param string $reason   Why (e.g. 'pre:e2m/update-theme-file').
	 * @param string $task_id  Optional grouping id for one logical task.
	 * @return string Backup record id, or '' when nothing was recorded /
	 *                a hard failure occurred (caller decides whether to abort).
	 */
	public static function backup_file( string $abs_path, string $reason = '', string $task_id = '' ): string {
		if ( $abs_path === '' ) {
			return '';
		}

		$existed = is_file( $abs_path );

		// Brand-new file: nothing on disk to copy. Record a marker so rollback
		// can remove the created file. Keep the payload empty.
		if ( ! $existed ) {
			return E2M_Backup_Store::record( [
				'type'        => E2M_Backup_Store::TYPE_FILE,
				'reason'      => $reason,
				'target'      => $abs_path,
				'backup_path' => '',
				'task_id'     => $task_id,
				'meta'        => [
					'existed'  => false,
					'rel_path' => self::portable_path( $abs_path ),
				],
			] );
		}

		if ( ! is_readable( $abs_path ) ) {
			return '';
		}

		if ( ! E2M_Backup_Store::ensure_protected() ) {
			return '';
		}

		$rel_dest = self::build_payload_rel( $abs_path );
		$abs_dest = E2M_Backup_Store::dir() . $rel_dest;

		if ( ! copy( $abs_path, $abs_dest ) ) {
			return '';
		}

		return E2M_Backup_Store::record( [
			'type'        => E2M_Backup_Store::TYPE_FILE,
			'reason'      => $reason,
			'target'      => $abs_path,
			'backup_path' => $rel_dest,
			'task_id'     => $task_id,
			'meta'        => [
				'existed'  => true,
				'rel_path' => self::portable_path( $abs_path ),
				'size'     => (int) ( @filesize( $abs_path ) ?: 0 ),
				'sha256'   => (string) ( @hash_file( 'sha256', $abs_path ) ?: '' ),
			],
		] );
	}

	/**
	 * Restore a file backup by record id.
	 *
	 * existed=true  -> copy the saved payload back over the live file.
	 * existed=false -> the backup marked a freshly created file; delete it.
	 *
	 * Before either, we capture the file's CURRENT state so the rollback is
	 * itself undoable.
	 *
	 * @return array{restored: bool, target: string, undo_id: string}|WP_Error
	 */
	public static function rollback( string $backup_id ) {
		$record = E2M_Backup_Store::get( $backup_id );
		if ( $record === null || ( $record['type'] ?? '' ) !== E2M_Backup_Store::TYPE_FILE ) {
			return new WP_Error( 'e2m_backup_not_found', __( 'File backup not found.', 'e2mconnect' ) );
		}

		$target = (string) ( $record['target'] ?? '' );
		if ( $target === '' ) {
			return new WP_Error( 'e2m_backup_no_target', __( 'Backup record has no target path.', 'e2mconnect' ) );
		}

		// Guard: only ever restore into zones the file abilities may write to.
		$safe = function_exists( 'e2m_engine_assert_safe_mutable_file_type' )
			? e2m_engine_assert_safe_mutable_file_type( $target )
			: true;
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		// Make the rollback reversible by snapshotting the live file first.
		$undo_id = self::backup_file( $target, 'pre-rollback:' . $backup_id );

		$existed_at_backup = (bool) ( $record['meta']['existed'] ?? true );

		if ( ! $existed_at_backup ) {
			// The backup recorded a file that did not exist before the agent
			// created it - rolling back means removing the created file.
			if ( is_file( $target ) ) {
				wp_delete_file( $target );
			}
			return [ 'restored' => true, 'target' => $target, 'undo_id' => $undo_id ];
		}

		$payload = E2M_Backup_Store::absolute_path( $record );
		if ( $payload === '' || ! is_file( $payload ) ) {
			return new WP_Error( 'e2m_backup_payload_missing', __( 'Backup payload file is missing on disk.', 'e2mconnect' ) );
		}

		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'e2m_restore_mkdir_failed', __( 'Could not recreate the target directory.', 'e2mconnect' ) );
		}

		if ( ! copy( $payload, $target ) ) {
			return new WP_Error( 'e2m_restore_failed', __( 'Failed to write the restored file.', 'e2mconnect' ) );
		}

		return [ 'restored' => true, 'target' => $target, 'undo_id' => $undo_id ];
	}

	/**
	 * List file backups, newest first. Optionally filter to one target path.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_path( string $abs_path = '' ): array {
		$filter = [ 'type' => E2M_Backup_Store::TYPE_FILE ];
		if ( $abs_path !== '' ) {
			$filter['target'] = $abs_path;
		}
		return E2M_Backup_Store::list( $filter );
	}

	// ── internals ────────────────────────────────────────────────────────

	/**
	 * Build the manifest-relative payload path:
	 *   files/<hash>_<basename>__<timestamp>-<token>.bak
	 *
	 * The hash encodes the source path so distinct files with the same
	 * basename never collide; the basename keeps it human-readable. The random
	 * token guarantees uniqueness even when two backups of the SAME file land
	 * in the same second (e.g. a write immediately followed by a rollback's
	 * pre-rollback snapshot) - without it the second copy would clobber the
	 * first's payload.
	 */
	private static function build_payload_rel( string $abs_path ): string {
		$base  = sanitize_file_name( basename( $abs_path ) );
		$hash  = substr( md5( $abs_path ), 0, 8 );
		$token = substr( (string) wp_generate_password( 8, false, false ), 0, 6 );
		return 'files/' . $hash . '_' . $base . '__' . E2M_Backup_Store::timestamp_slug() . '-' . $token . '.bak';
	}

	/**
	 * A portable, human-readable form of the path (relative to wp-content or
	 * ABSPATH) for display. Falls back to the absolute path.
	 */
	private static function portable_path( string $abs_path ): string {
		$content = trailingslashit( WP_CONTENT_DIR );
		if ( str_starts_with( $abs_path, $content ) ) {
			return 'wp-content/' . substr( $abs_path, strlen( $content ) );
		}
		if ( defined( 'ABSPATH' ) && str_starts_with( $abs_path, ABSPATH ) ) {
			return substr( $abs_path, strlen( ABSPATH ) );
		}
		return $abs_path;
	}
}
