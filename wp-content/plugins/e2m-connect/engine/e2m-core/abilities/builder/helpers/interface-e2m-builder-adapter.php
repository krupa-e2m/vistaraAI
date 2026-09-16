<?php
/**
 * E2M Connect MCP - Builder Adapter Interface
 *
 * Contract every builder adapter implements. The MCP builder abilities never
 * talk to a builder's native storage directly; they route through one of
 * these implementations so the same tool call produces the right output
 * whether the post uses Elementor, Gutenberg, or Bricks.
 *
 * Canonical tree shape (shared across adapters):
 * {
 *   "elements": [
 *     {
 *       "id": "unique-id",
 *       "kind": "container|widget|section|column|block",
 *       "type": "heading|button|core/paragraph|...",
 *       "settings": { ... native-shaped settings ... },
 *       "children": [ ... ]
 *     }
 *   ]
 * }
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface E2M_Builder_Adapter_Interface {

	/**
	 * Machine slug for this builder ("elementor", "gutenberg", "bricks").
	 */
	public function slug(): string;

	/**
	 * Human label (for admin UI and tool output messaging).
	 */
	public function label(): string;

	/**
	 * Return true when this adapter should claim authorship of the post.
	 *
	 * Detection is strictly defensive: an adapter only reports true when it
	 * can find a concrete storage signal (meta key, serialized payload,
	 * block delimiter). A site may have Elementor installed without a page
	 * actually being built with it - we do not want to misroute writes.
	 */
	public function supports( int $post_id ): bool;

	/**
	 * Is this builder available on the server at all? Used by registry during
	 * capability queries (e.g. "can E2M Connect build a Bricks page here?").
	 */
	public function is_available(): bool;

	/**
	 * Extract the post's native structure into E2M Connect canonical form.
	 *
	 * @return array<string, mixed> { "elements": array<int, array> }
	 */
	public function extract( int $post_id ): array;

	/**
	 * Persist a canonical tree back into the post using native storage.
	 *
	 * @param int $post_id
	 * @param array<string, mixed> $tree Canonical tree.
	 * @return true|WP_Error
	 */
	public function inject( int $post_id, array $tree );

	/**
	 * Generate a unique ID in the shape this builder expects.
	 *
	 * Elementor uses a 7-character hex string, Bricks uses 6 alphanumerics,
	 * Gutenberg does not require IDs (we synthesise one for canonical use).
	 */
	public function generate_id(): string;

	/**
	 * Build a canonical element node for a widget/block by friendly type.
	 *
	 * The "friendly" type is a E2M Connect-normalised slug (e.g. "heading",
	 * "button", "image") that each adapter maps to its native equivalent.
	 * Returns a canonical node ready for injection or mutation.
	 *
	 * @param string $friendly_type
	 * @param array<string, mixed> $settings
	 * @param array<int, array>    $children
	 * @return array<string, mixed>|WP_Error
	 */
	public function make_widget( string $friendly_type, array $settings = [], array $children = [] );

	/**
	 * Build a canonical container node. Layout hints (flex, grid, columns)
	 * are translated by each adapter into its native container equivalent.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<int, array>    $children
	 * @return array<string, mixed>
	 */
	public function make_container( array $settings = [], array $children = [] ): array;

	/**
	 * Return the friendly-to-native widget type map used by make_widget().
	 *
	 * @return array<string, string>
	 */
	public function friendly_widget_map(): array;
}
