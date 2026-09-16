<?php
/**
 * E2M Connect MCP - Risk Classifier.
 *
 * Looks at an about-to-run (non read-only) ability and its input and decides
 * what database safety net to apply BEFORE the agent proceeds:
 *
 *   - a SCOPED db backup of exactly the rows the task is likely to touch
 *     (a single post + its meta, specific option rows, a term + its meta,
 *     a user + its meta), or
 *   - a WARNING when the blast radius cannot be scoped reliably (plugin/theme
 *     updates, raw SQL, bulk deletes) - logged so the operator sees it, the
 *     task is not blocked.
 *
 * Scope is expressed as a list of { table (logical key), where, args } items
 * that E2M_DB_Backup turns into SELECT/DELETE/INSERT. Per the operator's
 * decision the policy is "scoped, never a full dump"; unscopeable work warns.
 *
 * @package  E2M Connect_MCP
 * @since    0.3.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Risk_Classifier {

	private const READ_ONLY_PREFIXES = [ 'list-', 'get-', 'read-', 'find-', 'search-', 'detect-', 'discover-', 'validate-', 'export-' ];

	/**
	 * Classify a write ability.
	 *
	 * @param array<string, mixed> $input
	 * @return array{risk: string, db_scope: array<int, array<string,mixed>>, warn: bool, reason: string}
	 */
	public static function classify( string $slug, array $input ): array {
		$none = [ 'risk' => 'none', 'db_scope' => [], 'warn' => false, 'reason' => '' ];

		$tail = substr( $slug, strrpos( $slug, '/' ) + 1 );
		foreach ( self::READ_ONLY_PREFIXES as $prefix ) {
			if ( str_starts_with( $tail, $prefix ) ) {
				return $none;
			}
		}

		// File-content abilities are covered by E2M_File_Backup (a separate
		// pre-write file copy), not by a DB backup - so don't classify them as
		// DB work and don't emit a "can't scope" warning for them.
		if ( in_array( $tail, [ 'edit-file', 'write-file', 'update-theme-file', 'update-theme-stylesheet' ], true ) ) {
			return $none;
		}

		// Unscopeable, high-blast-radius work -> warn, do not attempt a scope.
		if ( self::is_unscopeable( $tail ) ) {
			return [
				'risk'     => 'high',
				'db_scope' => [],
				'warn'     => true,
				'reason'   => sprintf( 'Ability "%s" can affect files or many DB rows; a scoped backup is not possible. Proceeding without an automatic DB backup.', $slug ),
			];
		}

		$scope = [];

		// 1. Post-targeted work: the post row + all its meta (covers Elementor,
		//    ACF field values, Yoast/Rank Math meta, page settings, etc.).
		$post_id = self::int_from( $input, [ 'post_id', 'page_id', 'attachment_id', 'parent_id' ] );
		if ( $post_id > 0 ) {
			$scope[] = [ 'table' => 'posts', 'where' => 'ID = %d', 'args' => [ $post_id ] ];
			$scope[] = [ 'table' => 'postmeta', 'where' => 'post_id = %d', 'args' => [ $post_id ] ];
		}

		// 2. Option writes: scope to the named option(s) when we can see them.
		$option_names = self::option_names_from( $input );
		if ( ! empty( $option_names ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
			$scope[]      = [ 'table' => 'options', 'where' => "option_name IN ($placeholders)", 'args' => array_values( $option_names ) ];
		} elseif ( self::touches_options( $tail ) && $post_id === 0 ) {
			// An option write we can't pin to specific names - warn instead of
			// backing up the whole options table.
			return [
				'risk'     => 'medium',
				'db_scope' => [],
				'warn'     => true,
				'reason'   => sprintf( 'Ability "%s" writes site options but the target option name was not provided; skipping the scoped backup.', $slug ),
			];
		}

		// 3. Term / taxonomy work: the term, its taxonomy row, and its meta.
		$term_id = self::int_from( $input, [ 'term_id', 'category_id', 'tag_id' ] );
		if ( $term_id > 0 ) {
			$scope[] = [ 'table' => 'terms', 'where' => 'term_id = %d', 'args' => [ $term_id ] ];
			$scope[] = [ 'table' => 'term_taxonomy', 'where' => 'term_id = %d', 'args' => [ $term_id ] ];
			$scope[] = [ 'table' => 'termmeta', 'where' => 'term_id = %d', 'args' => [ $term_id ] ];
		}

		// 4. User work: the user row + its meta.
		$user_id = self::int_from( $input, [ 'user_id' ] );
		if ( $user_id > 0 ) {
			$scope[] = [ 'table' => 'users', 'where' => 'ID = %d', 'args' => [ $user_id ] ];
			$scope[] = [ 'table' => 'usermeta', 'where' => 'user_id = %d', 'args' => [ $user_id ] ];
		}

		if ( ! empty( $scope ) ) {
			return [
				'risk'     => 'low',
				'db_scope' => $scope,
				'warn'     => false,
				'reason'   => 'Scoped DB backup of the rows this task targets.',
			];
		}

		// A write we couldn't scope and isn't on the high-risk list: no backup,
		// no warning (e.g. cache flush, transient ops). Stay quiet.
		return $none;
	}

	// ── heuristics ─────────────────────────────────────────────────────────

	/** Work whose blast radius can't be expressed as a scoped row-set. */
	private static function is_unscopeable( string $tail ): bool {
		foreach ( [ 'plugin', 'theme', 'update-core', 'install', 'activate', 'deactivate', 'bulk', 'query', 'sql', 'truncate', 'flush', 'import', 'migrate' ] as $needle ) {
			if ( str_contains( $tail, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function touches_options( string $tail ): bool {
		foreach ( [ 'option', 'setting' ] as $needle ) {
			if ( str_contains( $tail, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * First positive integer found among the given input keys.
	 *
	 * @param array<string, mixed> $input
	 * @param array<int, string>   $keys
	 */
	private static function int_from( array $input, array $keys ): int {
		foreach ( $keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$v = (int) $input[ $key ];
				if ( $v > 0 ) {
					return $v;
				}
			}
		}
		return 0;
	}

	/**
	 * Collect option names referenced by the input, from the common shapes
	 * abilities use (option_name, name, or an options map).
	 *
	 * @param array<string, mixed> $input
	 * @return array<int, string>
	 */
	private static function option_names_from( array $input ): array {
		$names = [];
		foreach ( [ 'option_name', 'option', 'name' ] as $key ) {
			if ( ! empty( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$names[] = $input[ $key ];
			}
		}
		if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
			foreach ( $input['options'] as $k => $v ) {
				if ( is_string( $k ) ) {
					$names[] = $k;
				} elseif ( is_string( $v ) ) {
					$names[] = $v;
				}
			}
		}
		return array_values( array_unique( array_filter( $names, 'is_string' ) ) );
	}
}
