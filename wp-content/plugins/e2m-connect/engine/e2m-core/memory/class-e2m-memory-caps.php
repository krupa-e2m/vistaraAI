<?php
/**
 * E2M Connect — Memory Caps.
 *
 * Soft and hard limits on memory count per site, configured via the
 * E2M_Memory_Settings page.
 *
 *   - Soft cap (default 500): warns the user but doesn't block saves
 *   - Hard cap (default 1000): auto-archives the lowest-confidence
 *     non-pinned memory to make room for the new one
 *
 * Pinned memories are immune to auto-archive. We never delete on cap
 * pressure -- only archive. Archived memories show in a "memory archive"
 * view (Step 15) so users can restore mistakes.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Caps {

	/**
	 * Decide whether the next save should proceed. Returns:
	 *
	 *   - can_save = true if total active+pending memories are under hard cap
	 *   - warning  = a string when we crossed 90% of soft cap, else null
	 *   - archived = id of an auto-archived memory if hard cap was hit, else null
	 *
	 * @return array{can_save: bool, warning: ?string, archived: ?int}
	 */
	public static function check_before_save(): array {
		$settings = E2M_Memory_Settings::get();
		$soft_cap = (int) $settings['limits']['soft_cap'];
		$hard_cap = (int) $settings['limits']['hard_cap'];

		// Only count memories that occupy a "slot" -- pinned, always,
		// active, pending_review. Archived/stale/disputed don't count.
		$current = E2M_Memory_Repository::count(
			[ 'status' => [ 'pinned', 'always', 'active', 'pending_review' ] ]
		);

		$warning  = null;
		$archived = null;

		if ( $current >= (int) ( $soft_cap * 0.9 ) ) {
			$warning = sprintf(
				/* translators: 1: current count, 2: soft cap */
				__( 'You have %1$d memories — approaching the soft cap of %2$d. Review and prune low-confidence entries.', 'e2mconnect' ),
				$current,
				$soft_cap
			);
		}

		if ( $current >= $hard_cap ) {
			$archived = self::archive_lowest_confidence();
			if ( $archived === null ) {
				// All memories are pinned -- truly cannot save.
				return [
					'can_save' => false,
					'warning'  => __( 'Memory hard cap reached and all remaining memories are pinned. Unpin or hard-delete entries before saving.', 'e2mconnect' ),
					'archived' => null,
				];
			}
		}

		return [
			'can_save' => true,
			'warning'  => $warning,
			'archived' => $archived,
		];
	}

	/**
	 * Find the lowest-confidence non-pinned memory and archive it.
	 * Returns the archived id, or null if no eligible memory was found
	 * (e.g. everything is pinned).
	 */
	public static function archive_lowest_confidence(): ?int {
		if ( ! E2M_Memory_Index_Table::exists() ) {
			return null;
		}

		global $wpdb;
		$table = E2M_Memory_Index_Table::table_name();

		// Skip pinned memories. Order by confidence ascending, then by
		// last_fetched_at ascending so the least-used memory wins ties.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$memory_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT memory_id FROM {$table}
				 WHERE status NOT IN (%s, %s)
				 ORDER BY confidence ASC, COALESCE(last_fetched_at, '1970-01-01') ASC
				 LIMIT 1",
				'pinned',
				'archived'
			)
		);

		if ( $memory_id === null ) {
			return null;
		}

		$id     = (int) $memory_id;
		$result = E2M_Memory_Repository::archive( $id );
		return ( $result === true ) ? $id : null;
	}
}
