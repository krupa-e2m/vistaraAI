<?php
/**
 * E2M Connect MCP - Bricks Builder Adapter
 *
 * Bricks stores page content in post meta as a flat array of elements, each
 * with its own "parent" and "children" id references - a graph, not a tree.
 * This adapter reconstructs a proper tree for canonical output, and tears
 * it back apart into Bricks' flat storage format on inject.
 *
 * Meta key used:
 *   _bricks_page_content_2   (current Bricks storage as of v1.9+)
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Builder_Bricks_Adapter implements E2M_Builder_Adapter_Interface {

	private const META_KEY = '_bricks_page_content_2';

	public function slug(): string {
		return 'bricks';
	}

	public function label(): string {
		return 'Bricks';
	}

	public function is_available(): bool {
		return defined( 'BRICKS_VERSION' ) || ( function_exists( 'wp_get_theme' ) && wp_get_theme()->get_template() === 'bricks' );
	}

	public function supports( int $post_id ): bool {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $raw ) && count( $raw ) > 0;
	}

	public function generate_id(): string {
		// Bricks ids are 6 alphanumeric characters.
		return strtolower( (string) wp_generate_password( 6, false, false ) );
	}

	public function extract( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		// Build id -> element lookup, then reconstruct the tree from root
		// elements (parent == "0" or empty) down via the children arrays.
		$by_id = [];
		foreach ( $raw as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}
			$by_id[ (string) $element['id'] ] = $element;
		}

		$roots = array_values(
			array_filter(
				$raw,
				static function ( $element ) {
					if ( ! is_array( $element ) ) {
						return false;
					}
					$parent = (string) ( $element['parent'] ?? '0' );
					return $parent === '0' || $parent === '';
				}
			)
		);

		$elements = array_map(
			fn( array $root ) => $this->native_to_canonical( $root, $by_id ),
			$roots
		);

		return [
			'builder'  => $this->slug(),
			'elements' => $elements,
		];
	}

	/**
	 * Persist canonical tree back into Bricks flat-list storage.
	 *
	 * @param int                  $post_id
	 * @param array<string, mixed> $tree
	 * @return true|WP_Error
	 */
	public function inject( int $post_id, array $tree ) {
		$elements = isset( $tree['elements'] ) && is_array( $tree['elements'] ) ? $tree['elements'] : [];

		$flat = [];
		foreach ( $elements as $node ) {
			$this->canonical_to_flat( $node, '0', $flat );
		}

		update_post_meta( $post_id, self::META_KEY, $flat );
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		return true;
	}

	public function make_container( array $settings = [], array $children = [] ): array {
		return [
			'id'       => $this->generate_id(),
			'kind'     => 'container',
			'type'     => 'section',
			'settings' => $settings,
			'children' => $children,
		];
	}

	public function make_widget( string $friendly_type, array $settings = [], array $children = [] ) {
		$map    = $this->friendly_widget_map();
		$native = $map[ $friendly_type ] ?? null;

		if ( $native === null ) {
			return new WP_Error(
				'unsupported_widget',
				sprintf(
					/* translators: %s is the unmapped widget slug */
					__( 'Bricks adapter does not recognise friendly widget "%s".', 'e2mconnect' ),
					$friendly_type
				)
			);
		}

		return [
			'id'       => $this->generate_id(),
			'kind'     => 'widget',
			'type'     => $native,
			'settings' => $settings,
			'children' => $children,
		];
	}

	public function friendly_widget_map(): array {
		return [
			'heading'       => 'heading',
			'text'          => 'text-basic',
			'button'        => 'button',
			'image'         => 'image',
			'video'         => 'video',
			'divider'       => 'divider',
			'spacer'        => 'spacer',
			'icon'          => 'icon',
			'icon-list'     => 'icon-list',
			'icon-box'      => 'icon-box',
			'image-box'     => 'image',
			'social-icons'  => 'social-icons',
			'tabs'          => 'tabs',
			'accordion'     => 'accordion',
			'toggle'        => 'toggle',
			'alert'         => 'alert',
			'counter'       => 'counter',
			'progress-bar'  => 'progress-bar',
			'gallery'       => 'image-gallery',
			'slider'        => 'slider-nested',
			'carousel'      => 'image-gallery',
			'testimonial'   => 'testimonials',
			'form'          => 'form',
			'search'        => 'search',
			'map'           => 'map',
			'pricing-table' => 'pricing-tables',
			'cta'           => 'button',
			'html'          => 'code',
			'menu'          => 'nav-menu',
			'sidebar'       => 'sidebar',
		];
	}

	/**
	 * Recursively build canonical subtree starting from $root.
	 *
	 * @param array<string, mixed>                $root
	 * @param array<string, array<string, mixed>> $by_id
	 * @return array<string, mixed>
	 */
	private function native_to_canonical( array $root, array $by_id ): array {
		$children  = [];
		$child_ids = isset( $root['children'] ) && is_array( $root['children'] ) ? $root['children'] : [];
		foreach ( $child_ids as $cid ) {
			if ( isset( $by_id[ (string) $cid ] ) ) {
				$children[] = $this->native_to_canonical( $by_id[ (string) $cid ], $by_id );
			}
		}

		$name = (string) ( $root['name'] ?? 'unknown' );
		$kind = in_array( $name, [ 'section', 'container', 'div', 'block' ], true ) ? 'container' : 'widget';

		return [
			'id'       => (string) ( $root['id'] ?? $this->generate_id() ),
			'kind'     => $kind,
			'type'     => $name,
			'settings' => (array) ( $root['settings'] ?? [] ),
			'children' => $children,
		];
	}

	/**
	 * Flatten a canonical subtree into Bricks' parent/children id layout.
	 *
	 * @param array<string, mixed>             $node
	 * @param string                           $parent_id
	 * @param array<int, array<string, mixed>> $flat Accumulator, mutated.
	 */
	private function canonical_to_flat( array $node, string $parent_id, array &$flat ): void {
		$id         = (string) ( $node['id'] ?? $this->generate_id() );
		$child_ids  = [];
		$children   = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];

		foreach ( $children as $child ) {
			$child_id    = (string) ( $child['id'] ?? $this->generate_id() );
			$child_ids[] = $child_id;
		}

		$flat[] = [
			'id'       => $id,
			'name'     => (string) ( $node['type'] ?? 'section' ),
			'parent'   => $parent_id,
			'children' => $child_ids,
			'settings' => (array) ( $node['settings'] ?? [] ),
		];

		foreach ( $children as $child ) {
			$this->canonical_to_flat( (array) $child, $id, $flat );
		}
	}
}
