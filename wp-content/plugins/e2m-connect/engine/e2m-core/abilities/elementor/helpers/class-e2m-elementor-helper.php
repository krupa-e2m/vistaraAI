<?php
/**
 * E2M Connect MCP - Elementor Helper
 *
 * Shared utilities for every "e2m/elementor-*" ability:
 *   - presence detection for Elementor core and Elementor Pro
 *   - a Pro guard that produces a consistent WP_Error when Pro is needed
 *   - thin accessors for the parts of Elementor we touch often
 *     (widgets manager, kits manager, templates manager, documents)
 *
 * Keeping these in one class lets every ability fail loudly and
 * predictably when Elementor is missing, instead of fatal-erroring on an
 * undefined class lookup.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Elementor_Helper {

	/**
	 * Is Elementor (free) loaded on this site?
	 */
	public static function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' )
			&& class_exists( '\\Elementor\\Plugin' )
			&& isset( \Elementor\Plugin::$instance );
	}

	/**
	 * Is Elementor Pro also active? Used by every Pro-gated ability before
	 * it tries to touch Pro-only classes.
	 */
	public static function is_pro_available(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) && class_exists( '\\ElementorPro\\Plugin' );
	}

	/**
	 * Return a WP_Error that abilities bubble up when Elementor isn't active.
	 */
	public static function require_elementor() {
		if ( self::is_available() ) {
			return null;
		}
		return new WP_Error(
			'elementor_missing',
			__( 'Elementor is not installed or activated on this site.', 'e2mconnect' )
		);
	}

	/**
	 * Return a WP_Error that abilities bubble up when Pro isn't active.
	 * Used by Pro-gated shortcuts so clients see the same error code.
	 */
	public static function require_pro() {
		$core_check = self::require_elementor();
		if ( $core_check !== null ) {
			return $core_check;
		}
		if ( self::is_pro_available() ) {
			return null;
		}
		return new WP_Error(
			'elementor_pro_required',
			__( 'This ability requires Elementor Pro to be installed and activated.', 'e2mconnect' )
		);
	}

	/**
	 * Accessor for Elementor's widgets manager. Returns null when Elementor
	 * is missing - callers should consult require_elementor() first.
	 *
	 * @return \Elementor\Widgets_Manager|null
	 */
	public static function widgets_manager() {
		if ( ! self::is_available() ) {
			return null;
		}
		return \Elementor\Plugin::$instance->widgets_manager;
	}

	/**
	 * Accessor for the Kits Manager (globals live here).
	 *
	 * @return \Elementor\Core\Kits\Manager|null
	 */
	public static function kits_manager() {
		if ( ! self::is_available() ) {
			return null;
		}
		return \Elementor\Plugin::$instance->kits_manager;
	}

	/**
	 * Accessor for the Templates Library manager.
	 *
	 * @return \Elementor\TemplateLibrary\Manager|null
	 */
	public static function templates_manager() {
		if ( ! self::is_available() ) {
			return null;
		}
		return \Elementor\Plugin::$instance->templates_manager;
	}

	/**
	 * Accessor for the Documents manager.
	 *
	 * @return \Elementor\Core\Documents_Manager|null
	 */
	public static function documents_manager() {
		if ( ! self::is_available() ) {
			return null;
		}
		return \Elementor\Plugin::$instance->documents;
	}

	/**
	 * Look up the raw _elementor_data payload for a post and return it as a
	 * PHP array. Returns an empty array when the post does not carry
	 * Elementor content yet.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function read_post_data( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
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
	 * Persist the Elementor payload for a post. Writes JSON-slashed because
	 * Elementor expects update_post_meta to receive pre-slashed JSON.
	 *
	 * @param array<int, array<string, mixed>> $data
	 * @return true|WP_Error
	 */
	public static function write_post_data( int $post_id, array $data ) {
		$encoded = wp_json_encode( $data );
		if ( $encoded === false ) {
			return new WP_Error( 'encode_failed', __( 'Unable to encode Elementor data.', 'e2mconnect' ) );
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		delete_post_meta( $post_id, '_elementor_css' );
		return true;
	}

	/**
	 * Walk a native Elementor tree looking for a node with the given ID.
	 * Returns a reference to the node so callers can mutate it in place.
	 *
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<string, mixed>|null
	 */
	public static function &find_node( array &$nodes, string $id ): ?array {
		$null = null;
		foreach ( $nodes as &$node ) {
			if ( isset( $node['id'] ) && (string) $node['id'] === $id ) {
				return $node;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$hit = &self::find_node( $node['elements'], $id );
				if ( $hit !== null ) {
					return $hit;
				}
			}
		}
		unset( $node );
		return $null;
	}
}
