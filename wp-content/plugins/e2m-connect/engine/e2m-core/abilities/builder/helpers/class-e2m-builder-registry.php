<?php
/**
 * E2M Connect MCP - Builder Registry
 *
 * Centralised catalogue of builder adapters. The builder abilities use it to:
 *   - detect which builder authored a post (for routing)
 *   - fetch an explicit adapter by slug (for build-page / convert-html)
 *   - list every adapter that is installed on the server (for reporting)
 *
 * Registration happens once on first access; adapters are instantiated lazily
 * so we do not pay the cost of three adapter constructors for every request.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Builder_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Slug => fully qualified class name map.
	 *
	 * @var array<string, string>
	 */
	private array $classes = [];

	/**
	 * Slug => instance cache.
	 *
	 * @var array<string, E2M_Builder_Adapter_Interface>
	 */
	private array $instances = [];

	private function __construct() {
		$this->classes = [
			'elementor' => 'E2M_Builder_Elementor_Adapter',
			'gutenberg' => 'E2M_Builder_Gutenberg_Adapter',
			'bricks'    => 'E2M_Builder_Bricks_Adapter',
		];
	}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve an adapter instance for a slug, or null when the class is
	 * not loaded (e.g. the underlying builder is not installed).
	 */
	public function get( string $slug ): ?E2M_Builder_Adapter_Interface {
		$slug = strtolower( $slug );

		if ( isset( $this->instances[ $slug ] ) ) {
			return $this->instances[ $slug ];
		}

		if ( ! isset( $this->classes[ $slug ] ) ) {
			return null;
		}

		$class = $this->classes[ $slug ];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		/** @var E2M_Builder_Adapter_Interface $adapter */
		$adapter                  = new $class();
		$this->instances[ $slug ] = $adapter;
		return $adapter;
	}

	/**
	 * Return every adapter that is currently installed on the site, keyed
	 * by slug. Adapters report is_available() so Gutenberg is included
	 * everywhere while Elementor/Bricks depend on plugin/theme presence.
	 *
	 * @return array<string, E2M_Builder_Adapter_Interface>
	 */
	public function available(): array {
		$out = [];
		foreach ( array_keys( $this->classes ) as $slug ) {
			$adapter = $this->get( $slug );
			if ( $adapter && $adapter->is_available() ) {
				$out[ $slug ] = $adapter;
			}
		}
		return $out;
	}

	/**
	 * Find the adapter that owns a given post. Detection order matters:
	 * plugin-stored builders (Elementor, Bricks) are probed before
	 * Gutenberg because Gutenberg will claim any classic/ HTML content
	 * too on newer WordPress installs.
	 */
	public function for_post( int $post_id ): ?E2M_Builder_Adapter_Interface {
		$probe_order = [ 'elementor', 'bricks', 'gutenberg' ];

		foreach ( $probe_order as $slug ) {
			$adapter = $this->get( $slug );
			if ( $adapter && $adapter->is_available() && $adapter->supports( $post_id ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Return the slug of the adapter responsible for $post_id, or the
	 * string "classic" when none of the builders claim it.
	 */
	public function slug_for_post( int $post_id ): string {
		$adapter = $this->for_post( $post_id );
		return $adapter ? $adapter->slug() : 'classic';
	}
}
