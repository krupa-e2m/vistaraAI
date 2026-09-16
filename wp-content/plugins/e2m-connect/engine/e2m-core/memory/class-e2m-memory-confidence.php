<?php
/**
 * E2M Connect — Memory Confidence Engine.
 *
 * Maps the AI-provided trigger_type to an initial confidence score and
 * decides whether a save should auto-promote to `active` or land in
 * `pending_review` waiting for human approval.
 *
 * The trigger types match the five gates in the discipline contract
 * (MEMORY.md, shipped in Step 17). They reflect *why* the AI thought
 * this memory was worth saving — explicit user request scores highest;
 * unsignalled inference scores lowest.
 *
 * The auto-save threshold is configurable per site (Step 9 settings).
 * 0.70 is the default and matches the Strict preset.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Confidence {

	public const TRIGGER_EXPLICIT_SAVE       = 'explicit_save';
	public const TRIGGER_REPEATED_CORRECTION = 'repeated_correction';
	public const TRIGGER_UNUSUAL_CONFIRMED   = 'unusual_confirmed';
	public const TRIGGER_FACT_STATED         = 'fact_stated';
	public const TRIGGER_INFERRED            = 'inferred';

	public const DEFAULT_AUTO_SAVE_THRESHOLD = 0.70;
	public const SETTINGS_OPTION             = 'e2m_memory_settings';

	/**
	 * The full set of recognized triggers — used by ability input schemas
	 * to enforce that the AI doesn't invent new categories.
	 *
	 * @return list<string>
	 */
	public static function get_valid_triggers(): array {
		return [
			self::TRIGGER_EXPLICIT_SAVE,
			self::TRIGGER_REPEATED_CORRECTION,
			self::TRIGGER_UNUSUAL_CONFIRMED,
			self::TRIGGER_FACT_STATED,
			self::TRIGGER_INFERRED,
		];
	}

	/**
	 * Initial confidence for a memory based on which gate fired the save.
	 *
	 * | Trigger              | Score |
	 * |----------------------|-------|
	 * | explicit_save        | 0.90  |
	 * | repeated_correction  | 0.85  |
	 * | unusual_confirmed    | 0.75  |
	 * | fact_stated          | 0.70  |
	 * | inferred             | 0.40  |
	 */
	public static function score_from_trigger( string $trigger ): float {
		return match ( $trigger ) {
			self::TRIGGER_EXPLICIT_SAVE       => 0.90,
			self::TRIGGER_REPEATED_CORRECTION => 0.85,
			self::TRIGGER_UNUSUAL_CONFIRMED   => 0.75,
			self::TRIGGER_FACT_STATED         => 0.70,
			self::TRIGGER_INFERRED            => 0.40,
			default                           => 0.40,
		};
	}

	/**
	 * Whether the AI-provided confidence is at or above the auto-save
	 * threshold. Below the threshold, memories land as pending_review and
	 * require explicit user approval (or the 7-day auto-promote cron in
	 * Step 11) before becoming active.
	 */
	public static function should_auto_save( float $confidence ): bool {
		return $confidence >= self::get_auto_save_threshold();
	}

	/**
	 * Read the site's configured auto-save threshold. Falls back to the
	 * default when the option is unset or out of range. Settings UI lives
	 * in Step 9 and writes to the same option.
	 */
	public static function get_auto_save_threshold(): float {
		$settings = get_option( self::SETTINGS_OPTION, [] );
		$settings = is_array( $settings ) ? $settings : [];

		$preset = $settings['preset'] ?? 'strict';
		if ( $preset === 'loose' ) {
			return 0.40;
		}
		if ( $preset === 'strict' ) {
			return 0.80;
		}

		// Custom preset: use the explicit threshold lever.
		$threshold = $settings['custom']['save_threshold'] ?? self::DEFAULT_AUTO_SAVE_THRESHOLD;
		$threshold = is_numeric( $threshold ) ? (float) $threshold : self::DEFAULT_AUTO_SAVE_THRESHOLD;
		return max( 0.0, min( 1.0, $threshold ) );
	}

	/**
	 * Read the site's configured schema strictness — controls whether
	 * validator failures block the save or merely warn. Defaults to
	 * STRICT.
	 */
	public static function get_schema_strictness(): string {
		$settings = get_option( self::SETTINGS_OPTION, [] );
		$settings = is_array( $settings ) ? $settings : [];
		$preset   = $settings['preset'] ?? 'strict';

		if ( $preset === 'loose' ) {
			return E2M_Memory_Validator::STRICTNESS_WARN;
		}
		if ( $preset === 'strict' ) {
			return E2M_Memory_Validator::STRICTNESS_STRICT;
		}

		$value = $settings['custom']['schema_strictness'] ?? E2M_Memory_Validator::STRICTNESS_STRICT;
		return $value === E2M_Memory_Validator::STRICTNESS_WARN
			? E2M_Memory_Validator::STRICTNESS_WARN
			: E2M_Memory_Validator::STRICTNESS_STRICT;
	}
}
