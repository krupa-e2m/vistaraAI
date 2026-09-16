<?php
/**
 * E2M Connect MCP - Canonical Tree Utilities
 *
 * Static helpers used by every builder ability to walk, search, and mutate
 * the E2M Connect canonical tree format. By keeping every tree operation in one
 * place we avoid drift between tools and guarantee predictable output across
 * different builder backends.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Builder_Canonical {

	/**
	 * Walk the tree, invoking $visit(&$node, $parent_id, $index) for every
	 * element in depth-first order. Mutations made inside $visit persist.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @param callable                         $visit
	 * @param string|null                      $parent_id
	 */
	public static function walk( array &$elements, callable $visit, ?string $parent_id = null ): void {
		foreach ( $elements as $index => &$node ) {
			$visit( $node, $parent_id, $index );
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				self::walk( $node['children'], $visit, (string) ( $node['id'] ?? '' ) );
			}
		}
		unset( $node );
	}

	/**
	 * Return a flat list of every node in depth-first order. Useful for
	 * lookups where tree shape is not needed.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<int, array<string, mixed>>
	 */
	public static function flatten( array $elements ): array {
		$flat = [];
		self::walk(
			$elements,
			function ( &$node ) use ( &$flat ) {
				$flat[] = $node;
			}
		);
		return $flat;
	}

	/**
	 * Locate a node by its unique ID. Returns a reference to the node inside
	 * $elements so callers can mutate it directly.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<string, mixed>|null
	 */
	public static function &find( array &$elements, string $id ): ?array {
		$null_placeholder = null;
		foreach ( $elements as &$node ) {
			if ( isset( $node['id'] ) && (string) $node['id'] === $id ) {
				return $node;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$hit = &self::find( $node['children'], $id );
				if ( $hit !== null ) {
					return $hit;
				}
			}
		}
		unset( $node );
		return $null_placeholder;
	}

	/**
	 * Remove a node by ID. Returns the removed node on success, null when
	 * not found. Children of the removed node are also detached.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<string, mixed>|null
	 */
	public static function remove( array &$elements, string $id ): ?array {
		foreach ( $elements as $index => $node ) {
			if ( isset( $node['id'] ) && (string) $node['id'] === $id ) {
				$removed = $elements[ $index ];
				array_splice( $elements, $index, 1 );
				return $removed;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$hit = self::remove( $elements[ $index ]['children'], $id );
				if ( $hit !== null ) {
					return $hit;
				}
			}
		}
		return null;
	}

	/**
	 * Insert a node as a child of the given parent at $position (0-based).
	 * When parent_id is null the node lands at the root level. Position may
	 * be out-of-range; it is clamped to the current child count.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @param array<string, mixed>             $node
	 */
	public static function insert( array &$elements, array $node, ?string $parent_id, int $position = PHP_INT_MAX ): bool {
		if ( $parent_id === null ) {
			$target = &$elements;
		} else {
			$parent = &self::find( $elements, $parent_id );
			if ( $parent === null ) {
				return false;
			}
			if ( ! isset( $parent['children'] ) || ! is_array( $parent['children'] ) ) {
				$parent['children'] = [];
			}
			$target = &$parent['children'];
		}

		$position = max( 0, min( (int) $position, count( $target ) ) );
		array_splice( $target, $position, 0, [ $node ] );
		return true;
	}

	/**
	 * Find every node whose "type" matches $type. Optionally restrict to
	 * widgets whose settings contain a given string.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( array $elements, ?string $type = null, ?string $contains = null ): array {
		$hits = [];
		foreach ( self::flatten( $elements ) as $node ) {
			if ( $type !== null && ( (string) ( $node['type'] ?? '' ) !== $type ) ) {
				continue;
			}
			if ( $contains !== null && $contains !== '' ) {
				$blob = wp_json_encode( $node['settings'] ?? [] );
				if ( $blob === false || stripos( $blob, $contains ) === false ) {
					continue;
				}
			}
			$hits[] = $node;
		}
		return $hits;
	}

	/**
	 * Produce a deep clone of a node tree with freshly-generated IDs so it
	 * can be pasted next to the original without collisions.
	 *
	 * @param array<string, mixed> $node
	 * @param callable             $id_generator No-arg callable returning a new unique ID.
	 * @return array<string, mixed>
	 */
	public static function clone_with_new_ids( array $node, callable $id_generator ): array {
		$copy       = $node;
		$copy['id'] = (string) $id_generator();

		if ( isset( $copy['children'] ) && is_array( $copy['children'] ) ) {
			$copy['children'] = array_map(
				static fn( $child ) => self::clone_with_new_ids( (array) $child, $id_generator ),
				$copy['children']
			);
		}

		return $copy;
	}

	/**
	 * Shallow merge of settings: $patch overrides $base at the top level,
	 * with nested arrays replaced wholesale (we do not attempt deep merge
	 * because builders store heterogeneous shapes - array-vs-object, lists
	 * of dicts with IDs, etc. - where deep merge is usually wrong).
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	public static function merge_settings( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			$base[ $key ] = $value;
		}
		return $base;
	}
}
