<?php
/**
 * E2M Connect MCP - Elementor Builder Adapter
 *
 * Translates between E2M Connect canonical trees and Elementor's native
 * _elementor_data format. The native shape is a nested array of nodes with
 * "elType" (container/section/column/widget), "widgetType" (for widgets),
 * "settings", and "elements" (children). IDs are 7-character hex strings.
 *
 * Extraction normalises whatever mix of legacy sections and modern containers
 * the post contains. Injection writes back as JSON-encoded post meta.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Builder_Elementor_Adapter implements E2M_Builder_Adapter_Interface {

	public function slug(): string {
		return 'elementor';
	}

	public function label(): string {
		return 'Elementor';
	}

	public function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public function supports( int $post_id ): bool {
		return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
	}

	public function generate_id(): string {
		// Elementor uses 7 hex chars. wp_generate_password with special chars
		// disabled gives us a stable alphanumeric string; we trim to 7 and
		// lowercase for consistency with Elementor's own generator.
		$candidate = strtolower( (string) wp_generate_password( 7, false, false ) );
		return substr( $candidate, 0, 7 );
	}

	/**
	 * Extract the post's native tree into canonical form.
	 *
	 * @return array<string, mixed>
	 */
	public function extract( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = is_array( $raw ) ? $raw : [];
		}

		$native = is_array( $decoded ) ? $decoded : [];

		return [
			'builder'  => $this->slug(),
			'elements' => array_map( [ $this, 'native_to_canonical' ], $native ),
		];
	}

	/**
	 * Persist a canonical tree back as _elementor_data JSON.
	 *
	 * @param int                  $post_id
	 * @param array<string, mixed> $tree
	 * @return true|WP_Error
	 */
	public function inject( int $post_id, array $tree ) {
		$elements = isset( $tree['elements'] ) && is_array( $tree['elements'] ) ? $tree['elements'] : [];

		$native = array_map( [ $this, 'canonical_to_native' ], $elements );

		$encoded = wp_json_encode( $native );
		if ( $encoded === false ) {
			return new WP_Error( 'encode_failed', __( 'Unable to encode Elementor data.', 'e2mconnect' ) );
		}

		// Elementor expects slashed JSON when writing through update_post_meta.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		// Invalidate cached CSS so the next frontend render picks up changes.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_inline_svg' );

		return true;
	}

	public function make_container( array $settings = [], array $children = [] ): array {
		return [
			'id'       => $this->generate_id(),
			'kind'     => 'container',
			'type'     => 'container',
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
					__( 'Elementor adapter does not recognise friendly widget "%s".', 'e2mconnect' ),
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

	/**
	 * Friendly slug => native Elementor widgetType.
	 *
	 * @return array<string, string>
	 */
	public function friendly_widget_map(): array {
		return [
			'heading'        => 'heading',
			'text'           => 'text-editor',
			'button'         => 'button',
			'image'          => 'image',
			'video'          => 'video',
			'divider'        => 'divider',
			'spacer'         => 'spacer',
			'icon'           => 'icon',
			'icon-list'      => 'icon-list',
			'icon-box'       => 'icon-box',
			'image-box'      => 'image-box',
			'social-icons'   => 'social-icons',
			'tabs'           => 'tabs',
			'accordion'      => 'accordion',
			'toggle'         => 'toggle',
			'alert'          => 'alert',
			'counter'        => 'counter',
			'progress-bar'   => 'progress',
			'gallery'        => 'image-gallery',
			'slider'         => 'slides',
			'carousel'       => 'image-carousel',
			'testimonial'    => 'testimonial',
			'form'           => 'form',
			'search'         => 'search-form',
			'map'            => 'google_maps',
			'pricing-table'  => 'price-table',
			'cta'            => 'call-to-action',
			'html'           => 'html',
			'menu'           => 'nav-menu',
			'sidebar'        => 'sidebar',
		];
	}

	/**
	 * Convert a single native Elementor node to canonical form.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function native_to_canonical( $node ): array {
		$node     = (array) $node;
		$el_type  = (string) ( $node['elType'] ?? 'widget' );
		$kind_map = [
			'section'   => 'section',
			'column'    => 'column',
			'container' => 'container',
			'widget'    => 'widget',
		];
		$kind = $kind_map[ $el_type ] ?? 'widget';

		$type = $kind === 'widget'
			? (string) ( $node['widgetType'] ?? 'unknown' )
			: $el_type;

		$children = [];
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$children[] = $this->native_to_canonical( $child );
			}
		}

		return [
			'id'       => (string) ( $node['id'] ?? $this->generate_id() ),
			'kind'     => $kind,
			'type'     => $type,
			'settings' => (array) ( $node['settings'] ?? [] ),
			'children' => $children,
		];
	}

	/**
	 * Convert a canonical node back to native Elementor shape.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function canonical_to_native( $node ): array {
		$node = (array) $node;
		$kind = (string) ( $node['kind'] ?? 'widget' );
		$type = (string) ( $node['type'] ?? '' );

		$native = [
			'id'       => (string) ( $node['id'] ?? $this->generate_id() ),
			'settings' => (array) ( $node['settings'] ?? [] ),
			'elements' => [],
		];

		if ( in_array( $kind, [ 'section', 'column', 'container' ], true ) ) {
			$native['elType'] = $kind;
		} else {
			$native['elType']     = 'widget';
			$native['widgetType'] = $type !== '' ? $type : 'html';
		}

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				$native['elements'][] = $this->canonical_to_native( $child );
			}
		}

		return $native;
	}
}
