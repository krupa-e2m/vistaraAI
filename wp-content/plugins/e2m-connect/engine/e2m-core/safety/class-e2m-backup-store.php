<?php
/**
 * E2M Connect MCP - Backup Store.
 *
 * Single owner of the on-disk backup home at wp-content/e2m-connect-backups/.
 * Everything the agent-safety layer writes - timestamped theme/template file
 * copies, Elementor page JSON exports, and scoped DB row dumps - is recorded
 * here against one append-only manifest so the admin UI and the rollback
 * abilities have a single source of truth.
 *
 * The directory lives OUTSIDE the plugin folder on purpose: deactivating,
 * updating, or re-vendoring E2M Connect must never destroy a client's
 * backups. Retention is time-based (default 30 days) and enforced by a daily
 * cron via prune_older_than().
 *
 * Layout:
 *   e2m-connect-backups/
 *     files/        timestamped copies of functions.php / templates / etc.
 *     elementor/    per-page _elementor_data JSON exports
 *     db/           scoped DB row exports (randomised secret suffix)
 *     manifest.json single index of every backup record
 *     .htaccess + index.php + web.config  (deny direct web access)
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Backup_Store {

	private const MANIFEST      = 'manifest.json';
	private const SECRET_OPTION = 'e2m_engine_backup_secret';

	/** Valid backup categories (sub-directory names). */
	public const TYPE_FILE      = 'file';
	public const TYPE_ELEMENTOR = 'elementor';
	public const TYPE_DB        = 'db';

	/** Map a record type to its sub-directory. */
	private const SUBDIRS = [
		self::TYPE_FILE      => 'files',
		self::TYPE_ELEMENTOR => 'elementor',
		self::TYPE_DB        => 'db',
	];

	/**
	 * Public base directory the operator sees - wp-content/e2m-connect-backups/.
	 * Only deny-guards live here; the actual payloads live one level deeper in
	 * an unguessable secret sub-directory (see dir()). Always trailing-slashed.
	 */
	public static function base_dir(): string {
		if ( defined( 'E2M_ENGINE_BACKUP_DIR' ) ) {
			return trailingslashit( (string) E2M_ENGINE_BACKUP_DIR );
		}
		return trailingslashit( WP_CONTENT_DIR ) . 'e2m-connect-backups/';
	}

	/**
	 * Absolute path to the ACTIVE store, always trailing-slashed.
	 *
	 * Payloads (file copies, Elementor JSON, DB rows) and the manifest live in
	 * base/<secret>/ where <secret> is a long random slug. On nginx (where
	 * .htaccess is ignored) this unguessable path is the real defence against
	 * a backup file being fetched over HTTP. The secret is persisted in an
	 * option so it survives deactivation, and is rediscovered by scan if the
	 * option is ever lost.
	 */
	public static function dir(): string {
		return self::base_dir() . self::secret() . '/';
	}

	/**
	 * Resolve (and lazily create/persist) the secret store slug.
	 */
	private static function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( $secret !== '' && self::is_valid_secret( $secret ) ) {
			return $secret;
		}

		// Option lost or never set - try to recover an existing secret dir by
		// scanning the base for a sub-dir that already holds our store.
		$base = self::base_dir();
		if ( is_dir( $base ) ) {
			$dirs = glob( $base . '*', GLOB_ONLYDIR ) ?: [];
			foreach ( $dirs as $candidate ) {
				$leaf = basename( $candidate );
				if ( ! self::is_valid_secret( $leaf ) ) {
					continue;
				}
				if ( is_file( $candidate . '/' . self::MANIFEST ) || is_dir( $candidate . '/files' ) ) {
					update_option( self::SECRET_OPTION, $leaf, false );
					return $leaf;
				}
			}
		}

		$fresh = 'store-' . wp_generate_password( 32, false, false );
		update_option( self::SECRET_OPTION, $fresh, false );
		return $fresh;
	}

	/** Guard against path traversal / junk in the persisted secret slug. */
	private static function is_valid_secret( string $slug ): bool {
		return $slug !== '' && (bool) preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $slug );
	}

	/**
	 * Absolute path to a type's sub-directory, always trailing-slashed.
	 */
	public static function subdir( string $type ): string {
		$leaf = self::SUBDIRS[ $type ] ?? 'misc';
		return self::dir() . $leaf . '/';
	}

	/**
	 * Create the backup home + sub-directories and drop web-access guards.
	 * Idempotent and cheap to call before every write.
	 */
	public static function ensure_protected(): bool {
		$base = self::base_dir();
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return false;
		}
		// Guard the public base too, so nothing is ever directory-listable
		// even on the rare Apache host where .htaccess IS honoured.
		self::write_guards( $base );

		$root = self::dir();
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		foreach ( self::SUBDIRS as $leaf ) {
			$path = $root . $leaf . '/';
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}
		}

		self::write_guards( $root );
		return true;
	}

	/**
	 * Append a record to the manifest. Caller has already written the
	 * backup payload to disk and passes its location + metadata here.
	 *
	 * Expected keys (extra keys are preserved verbatim):
	 *   type        file|elementor|db                 (required)
	 *   reason      e.g. 'pre:e2m/update-theme-file'   (required)
	 *   target      path / post_id / scope label       (required)
	 *   backup_path path RELATIVE to the backup home   (required)
	 *   task_id     groups backups from one task        (optional)
	 *   meta        arbitrary extra info (hash, size…)  (optional)
	 *
	 * @param array<string, mixed> $entry
	 * @return string The generated record id (empty on failure).
	 */
	public static function record( array $entry ): string {
		if ( ! self::ensure_protected() ) {
			return '';
		}

		$id  = self::generate_id();
		$now = time();

		$record = [
			'id'          => $id,
			'type'        => (string) ( $entry['type'] ?? '' ),
			'reason'      => (string) ( $entry['reason'] ?? '' ),
			'target'      => $entry['target'] ?? '',
			'backup_path' => (string) ( $entry['backup_path'] ?? '' ),
			'task_id'     => (string) ( $entry['task_id'] ?? '' ),
			'created_at'  => gmdate( 'c', $now ),
			'created_ts'  => $now,
			// Microsecond-resolution order key so backups taken in the same
			// second (e.g. a write immediately followed by a rollback) still
			// sort deterministically newest-first.
			'created_mt'  => microtime( true ),
			'user_id'     => get_current_user_id(),
			'user_login'  => (string) ( wp_get_current_user()->user_login ?? '' ),
			'meta'        => is_array( $entry['meta'] ?? null ) ? $entry['meta'] : [],
		];

		$manifest   = self::read_manifest();
		$manifest[] = $record;
		self::write_manifest( $manifest );

		return $id;
	}

	/**
	 * Return manifest records, newest first, optionally filtered.
	 *
	 * Supported filters: type, task_id, target.
	 *
	 * @param array<string, mixed> $filter
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( array $filter = [] ): array {
		$rows = self::read_manifest();

		if ( isset( $filter['type'] ) ) {
			$rows = array_values( array_filter( $rows, static fn ( $r ) => ( $r['type'] ?? '' ) === $filter['type'] ) );
		}
		if ( isset( $filter['task_id'] ) ) {
			$rows = array_values( array_filter( $rows, static fn ( $r ) => ( $r['task_id'] ?? '' ) === $filter['task_id'] ) );
		}
		if ( isset( $filter['target'] ) ) {
			$rows = array_values( array_filter( $rows, static fn ( $r ) => (string) ( $r['target'] ?? '' ) === (string) $filter['target'] ) );
		}

		usort(
			$rows,
			static fn ( $a, $b ) =>
				(float) ( $b['created_mt'] ?? $b['created_ts'] ?? 0 ) <=> (float) ( $a['created_mt'] ?? $a['created_ts'] ?? 0 )
		);
		return $rows;
	}

	/**
	 * Fetch a single record by id, or null when absent.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::read_manifest() as $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Resolve a record's payload to an absolute path on disk.
	 */
	public static function absolute_path( array $record ): string {
		$rel = ltrim( (string) ( $record['backup_path'] ?? '' ), '/\\' );
		return $rel === '' ? '' : self::dir() . $rel;
	}

	/**
	 * Delete a record: removes its on-disk payload and drops it from the
	 * manifest. Returns true when the manifest row was removed.
	 */
	public static function remove( string $id ): bool {
		$manifest = self::read_manifest();
		$kept     = [];
		$removed  = false;

		foreach ( $manifest as $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $id ) {
				$abs = self::absolute_path( $row );
				if ( $abs !== '' && is_file( $abs ) ) {
					wp_delete_file( $abs );
				}
				$removed = true;
				continue;
			}
			$kept[] = $row;
		}

		if ( $removed ) {
			self::write_manifest( $kept );
		}
		return $removed;
	}

	/**
	 * Remove every backup older than $days. Returns the number of records
	 * pruned. Driven by the daily cleanup cron.
	 */
	public static function prune_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff   = time() - ( $days * DAY_IN_SECONDS );
		$manifest = self::read_manifest();
		$kept     = [];
		$pruned   = 0;

		foreach ( $manifest as $row ) {
			if ( (int) ( $row['created_ts'] ?? 0 ) < $cutoff ) {
				$abs = self::absolute_path( $row );
				if ( $abs !== '' && is_file( $abs ) ) {
					wp_delete_file( $abs );
				}
				++$pruned;
				continue;
			}
			$kept[] = $row;
		}

		if ( $pruned > 0 ) {
			self::write_manifest( $kept );
		}
		return $pruned;
	}

	// ── internals ────────────────────────────────────────────────────────

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_manifest(): array {
		$path = self::dir() . self::MANIFEST;
		if ( ! is_file( $path ) ) {
			return [];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		$raw = (string) @file_get_contents( $path );
		if ( $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $manifest
	 */
	private static function write_manifest( array $manifest ): void {
		$path = self::dir() . self::MANIFEST;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
		@file_put_contents( $path, (string) wp_json_encode( array_values( $manifest ), JSON_PRETTY_PRINT ), LOCK_EX );
	}

	/**
	 * Drop deny-all guards so the backup payloads (which can contain live
	 * DB rows) are not directly fetchable over HTTP on common stacks.
	 */
	private static function write_guards( string $root ): void {
		$guards = [
			'.htaccess'  => "Order Allow,Deny\nDeny from all\n",
			'index.php'  => "<?php // Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];
		foreach ( $guards as $name => $contents ) {
			$file = $root . $name;
			if ( ! is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
				@file_put_contents( $file, $contents, LOCK_EX );
			}
		}
	}

	/**
	 * Sortable, collision-resistant record id: <unix>-<random>.
	 */
	private static function generate_id(): string {
		return time() . '-' . substr( (string) wp_generate_password( 12, false, false ), 0, 8 );
	}

	/**
	 * Filesystem-safe timestamp slug used in backup filenames, e.g.
	 * 2026-06-17_134501. Always UTC for cross-host consistency.
	 */
	public static function timestamp_slug(): string {
		return gmdate( 'Y-m-d_His' );
	}
}
