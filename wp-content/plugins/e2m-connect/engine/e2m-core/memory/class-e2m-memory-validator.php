<?php
/**
 * E2M Connect — Memory Validator.
 *
 * Enforces the type-specific schema rules that the repository's
 * shape-level validation can't express on its own:
 *
 *   - rules type: content must contain both **Why:** and **How to apply:**
 *   - context type: content must not contain relative date words
 *   - reference type: content must contain a URL or stable identifier
 *   - profile type: profile memories have no extra checks beyond shape
 *
 * The repository runs validate_input_shape() first (length, enum
 * membership, slug pattern). This class layers the discipline-contract
 * rules on top. Schema strictness can be downgraded to "warn" via the
 * memory settings page (Step 9), in which case violations return a
 * warning instead of an error.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Validator {

	public const STRICTNESS_STRICT = 'strict';
	public const STRICTNESS_WARN   = 'warn';

	/**
	 * Pre-compiled list of relative-date words that the AI must resolve
	 * to absolute dates before saving a context-type memory. Anchored
	 * with \b so we don't false-match inside other words (e.g. "monday"
	 * inside "mondayitis"). All lowercase; case-insensitive at match time.
	 *
	 * Bounded alternation — no nested quantifiers — to keep the regex
	 * outside the ReDoS family.
	 *
	 * @var array<int, string>
	 */
	private const RELATIVE_DATE_WORDS = [
		'today',
		'tomorrow',
		'yesterday',
		'tonight',
		'next week',
		'this week',
		'last week',
		'next month',
		'this month',
		'last month',
		'next year',
		'this year',
		'last year',
		'monday',
		'tuesday',
		'wednesday',
		'thursday',
		'friday',
		'saturday',
		'sunday',
		'soon',
		'recently',
		'lately',
	];

	/**
	 * Apply discipline rules to a shape-validated payload.
	 *
	 * @param array<string,mixed> $shaped Output of repository validate_input_shape().
	 * @param string $strictness One of STRICTNESS_STRICT, STRICTNESS_WARN.
	 * @return true|WP_Error True when the payload passes; WP_Error in strict
	 *                       mode on violation; true with a warning-only side
	 *                       channel when strictness is WARN (warnings are
	 *                       attached via apply_filters for caller capture).
	 */
	public static function validate( array $shaped, string $strictness = self::STRICTNESS_STRICT ): bool|WP_Error {
		$type    = (string) ( $shaped['type'] ?? 'context' );
		$content = (string) ( $shaped['content'] ?? '' );
		$result  = match ( $type ) {
			'rules'     => self::validate_rules_schema( $content ),
			'context'   => self::validate_context_no_relative_dates( $content ),
			'reference' => self::validate_reference_has_url( $content ),
			default     => true,
		};

		if ( $result === true ) {
			return true;
		}

		if ( $strictness === self::STRICTNESS_WARN ) {
			// Warning path: surface the message via a filter so the calling
			// ability can include it in the response without blocking the
			// save. Return true so the save proceeds.
			$message = $result instanceof WP_Error ? $result->get_error_message() : (string) $result;
			do_action( 'e2m_memory_validation_warning', $message, $shaped );
			return true;
		}

		return $result instanceof WP_Error
			? $result
			: new WP_Error( 'invalid_content', __( 'Memory content failed schema validation.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	/**
	 * Rules type: must include both **Why:** and **How to apply:** markers
	 * (case-insensitive, anywhere in the content).
	 */
	private static function validate_rules_schema( string $content ): bool|WP_Error {
		$has_why  = (bool) preg_match( '/\*\*Why:\*\*/i', $content );
		$has_how  = (bool) preg_match( '/\*\*How to apply:\*\*/i', $content );

		if ( ! $has_why ) {
			return new WP_Error(
				'rules_missing_why',
				__( 'Rules-type memories must include a **Why:** section explaining the reason for the rule.', 'e2mconnect' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! $has_how ) {
			return new WP_Error(
				'rules_missing_how',
				__( 'Rules-type memories must include a **How to apply:** section describing when the rule kicks in.', 'e2mconnect' ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Context type: reject relative date words so the AI is forced to
	 * resolve "Thursday" to "2026-06-04" before saving. Without this rule,
	 * a memory saved on Monday saying "ship by Thursday" silently rots
	 * over time.
	 */
	private static function validate_context_no_relative_dates( string $content ): bool|WP_Error {
		$lower = strtolower( $content );
		foreach ( self::RELATIVE_DATE_WORDS as $word ) {
			// \b on word boundaries, plus quotemeta to be safe for any
			// regex specials we ever add to the list.
			$pattern = '/\b' . preg_quote( $word, '/' ) . '\b/';
			if ( preg_match( $pattern, $lower ) ) {
				return new WP_Error(
					'context_relative_date',
					sprintf(
						/* translators: %s: the relative-date word found in the content */
						__( 'Context memories cannot contain relative dates ("%s"). Resolve to an absolute date (e.g. 2026-06-04) before saving.', 'e2mconnect' ),
						$word
					),
					[ 'status' => 400 ]
				);
			}
		}
		return true;
	}

	/**
	 * Reference type: content must contain at least one URL or a
	 * recognizable external identifier (Notion, Drive, Linear, Figma…).
	 */
	private static function validate_reference_has_url( string $content ): bool|WP_Error {
		if ( preg_match( '#https?://[^\s)]+#i', $content ) ) {
			return true;
		}
		if ( preg_match( '/\b(Notion|Drive|Linear|Figma|Slack|GitHub|Jira|Asana|Airtable|Sheets)\b\s*[:#]/i', $content ) ) {
			return true;
		}
		return new WP_Error(
			'reference_no_pointer',
			__( 'Reference memories must contain a URL or a recognizable external identifier (Notion:, Drive:, Linear:, etc.).', 'e2mconnect' ),
			[ 'status' => 400 ]
		);
	}
}
