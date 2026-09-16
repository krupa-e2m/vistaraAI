<?php
/**
 * E2M Connect MCP - Safety Helper.
 *
 * Tiny static facade for the bits of safety state the gatekeeper, abilities,
 * and admin UI all read:
 *
 *   - Should this call run in dry-run preview mode?
 *   - Is this post protected from MCP writes?
 *   - Standard error envelopes for the above.
 *
 * Recovery from a bad agent change is always via the snapshot/restore path,
 * not via mode-driven blocking - approval queue, confirmation tokens, and
 * the anomaly tripwire have all been retired.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Safety {

	/**
	 * Return the raw WordPress environment type for display.
	 */
	public static function environment(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
	}

	/**
	 * Whether a destructive ability should run in dry-run mode for this
	 * invocation. Precedence (highest wins):
	 *   1. Explicit dry_run=true|false in input
	 *   2. Global dry_run_default setting
	 *   3. false
	 *
	 * @param array<string, mixed> $input
	 */
	public static function is_dry_run( array $input ): bool {
		if ( array_key_exists( 'dry_run', $input ) ) {
			return (bool) $input['dry_run'];
		}
		$settings = e2m_engine_get_settings();
		return (bool) ( $settings['dry_run_default'] ?? false );
	}

	/**
	 * Is the given post on the protected-posts list? When true, MCP tools
	 * must refuse to write to it.
	 */
	public static function is_post_protected( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$settings = e2m_engine_get_settings();
		$list     = $settings['protected_posts'] ?? [];
		return is_array( $list ) && in_array( $post_id, array_map( 'intval', $list ), true );
	}

	/**
	 * Standard WP_Error for protected-post rejection.
	 */
	public static function protected_post_error( int $post_id ): WP_Error {
		return new WP_Error(
			'e2m_post_protected',
			sprintf(
				/* translators: %d is the protected post ID. */
				__( 'Post %d is on the E2M Connect protected list. Remove it from Settings first.', 'e2mconnect' ),
				$post_id
			),
			[ 'status' => 403, 'post_id' => $post_id ]
		);
	}

	/**
	 * Build the dry-run preview payload structure.
	 *
	 * @param string               $summary Short human-readable label of what would happen.
	 * @param array<string, mixed> $details Arbitrary diff / preview content.
	 * @return array<string, mixed>
	 */
	public static function dry_run_envelope( string $summary, array $details = [] ): array {
		return [
			'dry_run'     => true,
			'summary'     => $summary,
			'would_do'    => $details,
			'environment' => self::environment(),
		];
	}
}
