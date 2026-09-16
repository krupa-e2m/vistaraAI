<?php
/**
 * E2M Connect MCP - Meta-Snapshot store.
 *
 * Ring-buffered "pre-write" snapshots of the builder-relevant post_meta
 * that WordPress' native revisions system does NOT capture. WP revisions
 * track post_title, post_content, post_excerpt and a handful of core
 * fields; Elementor (_elementor_data), Bricks (_bricks_page_content_2),
 * and countless ACF field mirrors live in post_meta and therefore escape
 * the revision trail.
 *
 * The gatekeeper calls capture_if_destructive() right before a
 * destructive ability executes. Each captured state is stored against
 * the post in a JSON-encoded meta key (`_e2m_snapshots`) bounded to
 * the retention count from plugin settings. Callers can then list / get
 * / diff / restore through the dedicated safety abilities.
 *
 * We intentionally keep snapshots in post_meta (rather than a custom
 * table) so they travel with the post in export/import flows and obey
 * WP's multisite visibility rules automatically.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Meta_Snapshot {

	private const META_KEY = '_e2m_snapshots';

	/**
	 * Meta keys we treat as "builder content" and therefore always
	 * capture. Keys not in this list are ignored so snapshots stay
	 * small - we're targeting the rare, meaningful state, not every
	 * plugin's metadata.
	 *
	 * @var array<int, string>
	 */
	private const BUILDER_META_KEYS = [
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_edit_mode',
		'_elementor_css',
		'_bricks_page_content_2',
		'_bricks_page_header_2',
		'_bricks_page_footer_2',
		'_bricks_editor_mode',
	];

	/**
	 * Create a snapshot for the given post. Returns the snapshot ID
	 * (a short random string) or an empty string when snapshots are
	 * disabled via settings.
	 */
	public static function capture( int $post_id, string $reason = '' ): string {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return '';
		}
		$settings  = e2m_engine_get_settings();
		$retention = (int) ( $settings['snapshots_retention'] ?? 10 );
		if ( $retention <= 0 ) {
			return ''; // feature disabled
		}

		$state = self::collect_state( $post_id );
		if ( $state === null ) {
			return ''; // nothing meaningful to snapshot
		}

		$snapshot = [
			'id'         => self::generate_id(),
			'created_at' => gmdate( 'c' ),
			'user_id'    => get_current_user_id(),
			'user_login' => (string) ( wp_get_current_user()->user_login ?? '' ),
			'reason'     => $reason,
			'state'      => $state,
		];

		$queue   = self::read_queue( $post_id );
		$queue[] = $snapshot;

		// Ring buffer: keep the most recent N entries.
		if ( count( $queue ) > $retention ) {
			$queue = array_slice( $queue, -$retention );
		}

		update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $queue ) ) );
		return $snapshot['id'];
	}

	/**
	 * Capture only when the ability is destructive. Invoked from the
	 * gatekeeper, which knows the ability name. Returns '' for readonly
	 * ops so the caller can short-circuit.
	 */
	public static function capture_if_destructive(
		string $ability_name,
		int $post_id,
		array $input
	): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		if ( self::is_read_only_slug( $ability_name ) ) {
			return '';
		}
		return self::capture(
			$post_id,
			sprintf( 'pre:%s', $ability_name )
		);
	}

	/**
	 * Return every snapshot for a post, newest first. Lightweight - we
	 * only return metadata (no raw state), so admins can scan quickly.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $post_id ): array {
		$queue = self::read_queue( $post_id );
		return array_reverse(
			array_map(
				static function ( array $entry ): array {
					return [
						'id'         => (string) ( $entry['id'] ?? '' ),
						'created_at' => (string) ( $entry['created_at'] ?? '' ),
						'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
						'user_login' => (string) ( $entry['user_login'] ?? '' ),
						'reason'     => (string) ( $entry['reason'] ?? '' ),
						'size_bytes' => strlen( (string) wp_json_encode( $entry['state'] ?? [] ) ),
					];
				},
				$queue
			)
		);
	}

	/**
	 * Fetch the full state for a specific snapshot (including the raw
	 * builder meta payloads). Callers typically pair this with
	 * restore_state() when the admin accepts a diff preview.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $post_id, string $snapshot_id ): ?array {
		foreach ( self::read_queue( $post_id ) as $entry ) {
			if ( (string) ( $entry['id'] ?? '' ) === $snapshot_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Apply a snapshot's stored state back to the post. Returns true on
	 * success, WP_Error on miss.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( int $post_id, string $snapshot_id ) {
		$entry = self::get( $post_id, $snapshot_id );
		if ( $entry === null ) {
			return new WP_Error( 'e2m_snapshot_not_found', __( 'Snapshot not found.', 'e2mconnect' ) );
		}
		$state = is_array( $entry['state'] ?? null ) ? $entry['state'] : [];

		foreach ( self::BUILDER_META_KEYS as $key ) {
			if ( array_key_exists( $key, $state ) ) {
				$value = $state[ $key ];
				if ( $value === null ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
				}
			}
		}

		// Restore post_content when captured (it's always a string).
		if ( array_key_exists( 'post_content', $state ) ) {
			wp_update_post(
				[ 'ID' => $post_id, 'post_content' => (string) $state['post_content'] ],
				true
			);
		}

		return true;
	}

	/**
	 * Compute a shallow diff between two snapshot IDs. Keys that differ
	 * are returned with their "from" and "to" payloads; unchanged keys
	 * are omitted. Useful for admin review.
	 *
	 * @return array<string, array{from: mixed, to: mixed}>|WP_Error
	 */
	public static function diff( int $post_id, string $from_id, string $to_id ) {
		$a = self::get( $post_id, $from_id );
		$b = self::get( $post_id, $to_id );
		if ( $a === null || $b === null ) {
			return new WP_Error( 'e2m_snapshot_not_found', __( 'One or both snapshots could not be located.', 'e2mconnect' ) );
		}
		$state_a = (array) ( $a['state'] ?? [] );
		$state_b = (array) ( $b['state'] ?? [] );
		$keys    = array_unique( array_merge( array_keys( $state_a ), array_keys( $state_b ) ) );

		$out = [];
		foreach ( $keys as $key ) {
			$from = $state_a[ $key ] ?? null;
			$to   = $state_b[ $key ] ?? null;
			if ( $from !== $to ) {
				$out[ $key ] = [ 'from' => $from, 'to' => $to ];
			}
		}
		return $out;
	}

	/**
	 * Collect the current builder-relevant state of a post. Returns
	 * null when the post carries nothing worth saving (e.g. a plain
	 * Classic post that has never been edited through MCP).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function collect_state( int $post_id ): ?array {
		$state = [];
		$meaningful = false;

		foreach ( self::BUILDER_META_KEYS as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( $value !== '' && $value !== null && $value !== [] ) {
				$state[ $key ] = $value;
				$meaningful    = true;
			}
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		if ( $content !== '' ) {
			$state['post_content'] = $content;
			$meaningful            = true;
		}

		return $meaningful ? $state : null;
	}

	/**
	 * Age-prune the per-post snapshot trail to a retention window (days),
	 * across every post that carries snapshots. Complements the per-post ring
	 * buffer (count cap) so the time-based 30-day policy applies here too.
	 * Driven by the daily backup-cleanup cron.
	 *
	 * @return int Number of snapshot entries removed.
	 */
	public static function prune_all_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY )
		);
		if ( empty( $post_ids ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$queue   = self::read_queue( $post_id );
			if ( empty( $queue ) ) {
				continue;
			}

			$kept = array_values( array_filter(
				$queue,
				static function ( $entry ) use ( $cutoff ): bool {
					$created = isset( $entry['created_at'] ) ? strtotime( (string) $entry['created_at'] ) : 0;
					return $created === false || $created >= $cutoff;
				}
			) );

			$delta = count( $queue ) - count( $kept );
			if ( $delta <= 0 ) {
				continue;
			}
			$removed += $delta;

			if ( empty( $kept ) ) {
				delete_post_meta( $post_id, self::META_KEY );
			} else {
				update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $kept ) ) );
			}
		}
		return $removed;
	}

	/**
	 * Read the stored snapshot queue for a post. Always returns an array
	 * even when nothing has been snapshotted yet.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_queue( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return [];
	}

	/**
	 * Short randomish id for snapshot records. Collisions don't matter
	 * because ids are scoped to a single post and the queue never
	 * exceeds ~100 entries.
	 */
	private static function generate_id(): string {
		return 'snap-' . substr( (string) wp_generate_password( 12, false, false ), 0, 10 );
	}

	/**
	 * Read-only slug heuristic mirrored from the gatekeeper so we don't
	 * create a circular include.
	 */
	private static function is_read_only_slug( string $slug ): bool {
		$tail   = substr( $slug, strrpos( $slug, '/' ) + 1 );
		foreach ( [ 'list-', 'get-', 'read-', 'find-', 'search-', 'detect-', 'discover-', 'validate-', 'export-' ] as $prefix ) {
			if ( str_starts_with( $tail, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
