<?php
/**
 * E2M Connect — Memory Settings.
 *
 * Persistence and access for the per-site memory configuration:
 * preset (loose / strict / custom) plus three levers when preset=custom
 * (save_threshold, review_prompts, schema_strictness).
 *
 * Settings live in a single autoload=false WP option to avoid bloating
 * every page load with rarely-used JSON.
 *
 * The Confidence engine and Validator already read these settings via
 * E2M_Memory_Confidence::get_auto_save_threshold() and ::get_schema_strictness();
 * this class is the canonical writer + defaults provider.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Settings {

	public const OPTION_KEY     = 'e2m_memory_settings';
	public const DEFAULT_PRESET = 'strict';

	public const VALID_PRESETS         = [ 'strict', 'loose', 'custom' ];
	public const VALID_REVIEW_PROMPTS  = [ 'every', 'every_5', 'never' ];
	public const VALID_STRICTNESS      = [ 'strict', 'warn' ];

	/**
	 * Default shape returned when the option is unset or malformed.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'preset' => self::DEFAULT_PRESET,
			'custom' => [
				'save_threshold'    => 0.70,
				'review_prompts'    => 'every',
				'schema_strictness' => 'strict',
			],
			'limits' => [
				'soft_cap' => 500,
				'hard_cap' => 1000,
			],
		];
	}

	/**
	 * Read the current settings merged with defaults. Always returns a
	 * fully-shaped array so callers never need to ?? their way through it.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		return self::merge_with_defaults( $raw );
	}

	/**
	 * Persist a partial update. Unknown fields are ignored; invalid values
	 * are coerced to safe defaults. Returns the final shape after save.
	 *
	 * @param array<string,mixed> $partial
	 * @return array<string,mixed>
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$next    = self::sanitize_input( $partial, $current );
		update_option( self::OPTION_KEY, $next, false );
		return $next;
	}

	/**
	 * Reset to factory defaults. Used by the admin "Reset" button and by
	 * uninstall cleanup.
	 *
	 * @return array<string,mixed>
	 */
	public static function reset(): array {
		$defaults = self::defaults();
		update_option( self::OPTION_KEY, $defaults, false );
		return $defaults;
	}

	/**
	 * Merge a raw option value with defaults so the caller always gets a
	 * fully-shaped structure.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private static function merge_with_defaults( array $raw ): array {
		$defaults = self::defaults();

		$preset = isset( $raw['preset'] ) && in_array( $raw['preset'], self::VALID_PRESETS, true )
			? (string) $raw['preset']
			: $defaults['preset'];

		$custom = is_array( $raw['custom'] ?? null ) ? $raw['custom'] : [];
		$custom = [
			'save_threshold' => isset( $custom['save_threshold'] ) && is_numeric( $custom['save_threshold'] )
				? max( 0.0, min( 1.0, (float) $custom['save_threshold'] ) )
				: $defaults['custom']['save_threshold'],
			'review_prompts' => isset( $custom['review_prompts'] ) && in_array( $custom['review_prompts'], self::VALID_REVIEW_PROMPTS, true )
				? (string) $custom['review_prompts']
				: $defaults['custom']['review_prompts'],
			'schema_strictness' => isset( $custom['schema_strictness'] ) && in_array( $custom['schema_strictness'], self::VALID_STRICTNESS, true )
				? (string) $custom['schema_strictness']
				: $defaults['custom']['schema_strictness'],
		];

		$limits = is_array( $raw['limits'] ?? null ) ? $raw['limits'] : [];
		$limits = [
			'soft_cap' => isset( $limits['soft_cap'] ) ? max( 10, (int) $limits['soft_cap'] ) : $defaults['limits']['soft_cap'],
			'hard_cap' => isset( $limits['hard_cap'] ) ? max( 10, (int) $limits['hard_cap'] ) : $defaults['limits']['hard_cap'],
		];

		// Hard cap must be >= soft cap. Coerce if user misconfigured.
		if ( $limits['hard_cap'] < $limits['soft_cap'] ) {
			$limits['hard_cap'] = $limits['soft_cap'];
		}

		return [
			'preset' => $preset,
			'custom' => $custom,
			'limits' => $limits,
		];
	}

	/**
	 * Sanitize a user-supplied partial update. Same logic as
	 * merge_with_defaults but driven by the incoming partial.
	 *
	 * @param array<string,mixed> $partial
	 * @param array<string,mixed> $current Full current state for fall-back.
	 * @return array<string,mixed>
	 */
	private static function sanitize_input( array $partial, array $current ): array {
		// Build a synthetic raw blob: incoming overrides win, otherwise
		// keep current values, then merge through defaults to enforce
		// validity.
		$merged = [
			'preset' => $partial['preset'] ?? $current['preset'],
			'custom' => array_merge(
				$current['custom'],
				is_array( $partial['custom'] ?? null ) ? $partial['custom'] : []
			),
			'limits' => array_merge(
				$current['limits'],
				is_array( $partial['limits'] ?? null ) ? $partial['limits'] : []
			),
		];
		return self::merge_with_defaults( $merged );
	}
}
