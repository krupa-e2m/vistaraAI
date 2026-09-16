<?php
/**
 * E2M Connect — Memory Conflict Detector.
 *
 * Flags pairs/groups of memories that look like they contradict each
 * other at the same scope/specificity. The blueprint design point: the
 * AI does NOT pick silently when conflicts are present — it surfaces
 * them to the user.
 *
 * v1 heuristic: group memories by topic signature (tags + first
 * distinctive nouns from the description) and within a group flag any
 * pair whose contents disagree -- where "disagree" is approximated as
 * "have shared topic terms but very low body-content similarity, OR
 * contain explicit antonym pairs like always/never".
 *
 * Will produce false positives. The whole flow is "surface to user, let
 * them decide" -- so a small extra-conflict signal is preferable to
 * missing real conflicts and letting the agent pick the wrong rule.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Conflicts {

	/**
	 * Tokens that signal a directional rule. If a pair of memories share
	 * topic terms but disagree on these tokens, flag the conflict.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const POLARITY_PAIRS = [
		[ 'always', 'never' ],
		[ 'must', 'must not' ],
		[ 'do', "don't" ],
		[ 'use', 'avoid' ],
		[ 'prefer', 'avoid' ],
		[ 'enable', 'disable' ],
		[ 'on', 'off' ],
	];

	/**
	 * Find conflict groups within a set of memories.
	 *
	 * @param list<array<string,mixed>> $memories
	 * @return list<array{ids: list<int>, reason: string}>
	 */
	public static function detect_in_set( array $memories ): array {
		if ( count( $memories ) < 2 ) {
			return [];
		}

		// Build topic groups. A memory belongs to a group keyed by any of
		// its tags or by its first distinctive description term.
		$by_topic = [];
		foreach ( $memories as $memory ) {
			$topics = self::topic_signatures( $memory );
			foreach ( $topics as $topic ) {
				$by_topic[ $topic ][] = $memory;
			}
		}

		$conflicts  = [];
		$seen_pairs = [];

		foreach ( $by_topic as $topic => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			for ( $i = 0; $i < count( $group ); $i++ ) {
				for ( $j = $i + 1; $j < count( $group ); $j++ ) {
					$a = $group[ $i ];
					$b = $group[ $j ];
					$pair_key = min( (int) $a['id'], (int) $b['id'] ) . '|' . max( (int) $a['id'], (int) $b['id'] );
					if ( isset( $seen_pairs[ $pair_key ] ) ) {
						continue;
					}
					$reason = self::pair_conflicts( $a, $b );
					if ( $reason !== null ) {
						$seen_pairs[ $pair_key ] = true;
						$conflicts[] = [
							'ids'    => [ (int) $a['id'], (int) $b['id'] ],
							'topic'  => (string) $topic,
							'reason' => $reason,
						];
					}
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Topic signatures for a memory. Returns tags + the first three
	 * distinctive description terms (sanitized, lowercased, deduped).
	 *
	 * @return list<string>
	 */
	private static function topic_signatures( array $memory ): array {
		$signals = [];
		$tags = is_array( $memory['tags'] ?? null ) ? $memory['tags'] : [];
		foreach ( $tags as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( $tag !== '' ) {
				$signals[] = $tag;
			}
		}

		$description = strtolower( (string) ( $memory['description'] ?? '' ) );
		$tokens      = preg_split( '/[^a-z0-9]+/', $description ) ?: [];
		$distinctive = [];
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) < 4 ) {
				continue;
			}
			$distinctive[] = $token;
			if ( count( $distinctive ) >= 3 ) {
				break;
			}
		}
		return array_values( array_unique( array_merge( $signals, $distinctive ) ) );
	}

	/**
	 * Decide whether two memories conflict. Returns a reason string when
	 * they do, or null if they look compatible.
	 */
	private static function pair_conflicts( array $a, array $b ): ?string {
		$content_a = strtolower( (string) ( $a['content'] ?? '' ) );
		$content_b = strtolower( (string) ( $b['content'] ?? '' ) );

		foreach ( self::POLARITY_PAIRS as [ $positive, $negative ] ) {
			$a_has_positive = self::contains_word( $content_a, $positive );
			$a_has_negative = self::contains_word( $content_a, $negative );
			$b_has_positive = self::contains_word( $content_b, $positive );
			$b_has_negative = self::contains_word( $content_b, $negative );

			if ( ( $a_has_positive && $b_has_negative ) || ( $a_has_negative && $b_has_positive ) ) {
				return sprintf(
					/* translators: 1: positive token, 2: negative token */
					__( 'Polarity conflict: one memory uses "%1$s", the other uses "%2$s" on the same topic.', 'e2mconnect' ),
					$positive,
					$negative
				);
			}
		}

		return null;
	}

	private static function contains_word( string $haystack, string $needle ): bool {
		// preg_quote handles "must not" and "don't" safely.
		return (bool) preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/iu', $haystack );
	}
}
