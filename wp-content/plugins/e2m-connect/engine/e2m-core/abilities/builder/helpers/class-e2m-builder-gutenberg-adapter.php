<?php
/**
 * E2M Connect MCP - Gutenberg Builder Adapter
 *
 * Bridges E2M Connect canonical trees and Gutenberg block markup stored in
 * post_content. Relies on WordPress core's parse_blocks() + serialize_blocks()
 * for fidelity, then synthesises stable E2M Connect IDs for every node because
 * Gutenberg blocks do not carry IDs natively.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Builder_Gutenberg_Adapter implements E2M_Builder_Adapter_Interface {

	public function slug(): string {
		return 'gutenberg';
	}

	public function label(): string {
		return 'Gutenberg';
	}

	public function is_available(): bool {
		return function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' );
	}

	public function supports( int $post_id ): bool {
		$content = (string) get_post_field( 'post_content', $post_id );
		return str_contains( $content, '<!-- wp:' );
	}

	public function generate_id(): string {
		// Used when callers need a brand-new ID (e.g. make_widget before
		// the element is persisted). Final IDs in extract() are derived
		// deterministically from tree position so round-tripping through
		// extract → mutate → inject stays stable.
		return 'gb-new-' . strtolower( (string) wp_generate_password( 8, false, false ) );
	}

	public function extract( int $post_id ): array {
		$content = (string) get_post_field( 'post_content', $post_id );
		$blocks  = $this->drop_whitespace_blocks( parse_blocks( $content ) );

		$elements = [];
		foreach ( $blocks as $index => $block ) {
			$elements[] = $this->block_to_canonical( $block, 'gb-' . $index );
		}

		return [
			'builder'  => $this->slug(),
			'elements' => $elements,
		];
	}

	/**
	 * Persist canonical tree back into post_content.
	 *
	 * @param int                  $post_id
	 * @param array<string, mixed> $tree
	 * @return true|WP_Error
	 */
	public function inject( int $post_id, array $tree ) {
		$elements = isset( $tree['elements'] ) && is_array( $tree['elements'] ) ? $tree['elements'] : [];
		$blocks   = array_map( [ $this, 'canonical_to_block' ], $elements );
		$content  = serialize_blocks( $blocks );

		$result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			],
			true
		);

		return is_wp_error( $result ) ? $result : true;
	}

	public function make_container( array $settings = [], array $children = [] ): array {
		// Gutenberg's nearest equivalent to "container" is core/group.
		return [
			'id'       => $this->generate_id(),
			'kind'     => 'container',
			'type'     => 'core/group',
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
					__( 'Gutenberg adapter does not recognise friendly widget "%s".', 'e2mconnect' ),
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
			'heading'        => 'core/heading',
			'text'           => 'core/paragraph',
			'button'         => 'core/button',
			'image'          => 'core/image',
			'video'          => 'core/video',
			'divider'        => 'core/separator',
			'spacer'         => 'core/spacer',
			'icon'           => 'core/html',
			'icon-list'      => 'core/list',
			'icon-box'       => 'core/group',
			'image-box'      => 'core/media-text',
			'social-icons'   => 'core/social-links',
			'tabs'           => 'core/group',
			'accordion'      => 'core/details',
			'toggle'         => 'core/details',
			'alert'          => 'core/group',
			'counter'        => 'core/html',
			'progress-bar'   => 'core/html',
			'gallery'        => 'core/gallery',
			'slider'         => 'core/cover',
			'carousel'       => 'core/gallery',
			'testimonial'    => 'core/quote',
			'form'           => 'core/html',
			'search'         => 'core/search',
			'map'            => 'core/html',
			'pricing-table'  => 'core/columns',
			'cta'            => 'core/cover',
			'html'           => 'core/html',
			'menu'           => 'core/navigation',
			'sidebar'        => 'core/group',
		];
	}

	/**
	 * Recursively convert parsed block to canonical.
	 *
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private function block_to_canonical( $block, string $path = 'gb-0' ): array {
		$block = (array) $block;
		$name  = (string) ( $block['blockName'] ?? '' );

		$children = [];
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $this->drop_whitespace_blocks( $block['innerBlocks'] ) as $index => $child ) {
				$children[] = $this->block_to_canonical( $child, $path . '-' . $index );
			}
		}

		// We fold innerHTML into settings so round-trips survive when the
		// block carries rich markup (paragraph, heading, HTML block, etc.).
		$settings = (array) ( $block['attrs'] ?? [] );
		if ( isset( $block['innerHTML'] ) && trim( (string) $block['innerHTML'] ) !== '' ) {
			$settings['__inner_html'] = (string) $block['innerHTML'];
		}
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$settings['__inner_content'] = $block['innerContent'];
		}

		// Deterministic path-based ID. Two successive extract() calls return
		// the same IDs as long as the tree shape is unchanged, which is the
		// contract tools like update-element / find-element rely on.
		return [
			'id'       => $path,
			'kind'     => $name === 'core/group' ? 'container' : 'widget',
			'type'     => $name !== '' ? $name : 'core/html',
			'settings' => $settings,
			'children' => $children,
		];
	}

	/**
	 * Reverse of block_to_canonical.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function canonical_to_block( $node ): array {
		$node     = (array) $node;
		$settings = (array) ( $node['settings'] ?? [] );

		$inner_html        = isset( $settings['__inner_html'] ) ? (string) $settings['__inner_html'] : '';
		$has_inner_content = isset( $settings['__inner_content'] ) && is_array( $settings['__inner_content'] );

		$children_canonical = ( ! empty( $node['children'] ) && is_array( $node['children'] ) )
			? $node['children']
			: [];

		// Gutenberg's serializer needs a null placeholder in innerContent
		// for each innerBlock slot. A cached __inner_content captured when
		// the block had a different child count is stale after in-memory
		// edits, so we re-synthesise the array whenever the null-count in
		// the cache does not match the current child count.
		$child_count    = count( $children_canonical );
		$cached_content = $has_inner_content ? (array) $settings['__inner_content'] : null;
		$cached_nulls   = is_array( $cached_content )
			? count( array_filter( $cached_content, static fn( $v ) => $v === null ) )
			: 0;

		if ( $child_count > 0 && $cached_content !== null && $cached_nulls === $child_count ) {
			$inner_content = $cached_content;
		} elseif ( $child_count > 0 ) {
			$inner_content = [];
			if ( $inner_html !== '' ) {
				$inner_content[] = $inner_html;
			}
			foreach ( $children_canonical as $_child ) {
				$inner_content[] = null;
			}
		} elseif ( $cached_content !== null ) {
			$inner_content = $cached_content;
		} else {
			$inner_content = $inner_html !== '' ? [ $inner_html ] : [];
		}

		unset( $settings['__inner_html'], $settings['__inner_content'] );

		$children = [];
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				$children[] = $this->canonical_to_block( $child );
			}
		}

		return [
			'blockName'    => (string) ( $node['type'] ?? 'core/html' ),
			'attrs'        => $settings,
			'innerBlocks'  => $children,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * parse_blocks() returns placeholder entries with blockName = null between
	 * blocks (preserved whitespace). Dropping them keeps our canonical tree
	 * clean; we re-insert whitespace only when re-serializing.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private function drop_whitespace_blocks( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? null ) === null ) {
				continue;
			}
			$out[] = $block;
		}
		return $out;
	}
}
