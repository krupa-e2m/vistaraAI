<?php
/**
 * E2M Connect — Memory Similarity.
 *
 * Detects near-duplicates so the AI updates an existing memory rather
 * than creating a parallel one. v1 uses TF-IDF cosine similarity
 * computed in PHP — cheap enough for the corpus sizes we care about
 * (<= 500 memories per site at the soft cap).
 *
 * The M3 milestone replaces this with embedding-based search powered by
 * a vector column; the surface area here stays the same so the swap is
 * local.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Similarity {

	/**
	 * Common English stop words. Trimmed list — enough to denoise short
	 * memory descriptions without dragging in a 500-word vocabulary.
	 *
	 * @var array<string, true>
	 */
	private const STOP_WORDS = [
		'a' => true, 'an' => true, 'and' => true, 'are' => true, 'as' => true, 'at' => true,
		'be' => true, 'by' => true, 'for' => true, 'from' => true, 'has' => true, 'have' => true,
		'i' => true, 'in' => true, 'is' => true, 'it' => true, 'its' => true, 'of' => true,
		'on' => true, 'or' => true, 'that' => true, 'the' => true, 'this' => true, 'to' => true,
		'was' => true, 'were' => true, 'will' => true, 'with' => true, 'we' => true, 'you' => true,
	];

	/**
	 * Find memories whose content is at or above `$threshold_pct` similar
	 * to the candidate. Filters by visibility (caller-supplied) so a
	 * private memory in another user's bucket can't trigger a false
	 * duplicate hit.
	 *
	 * @param array<string,mixed> $filters Same shape as E2M_Memory_Repository filters.
	 * @return list<array{id:int, name:string, score:float}>
	 */
	public static function find_near_duplicates( string $content, int $threshold_pct = 90, array $filters = [] ): array {
		$threshold = max( 0, min( 100, $threshold_pct ) ) / 100.0;
		$content_vec = self::vectorize( $content );
		if ( $content_vec === [] ) {
			return [];
		}

		// Pull a bounded set of candidate memories. The corpus is capped
		// (default soft cap = 500) so a single pass is acceptable; if the
		// hard cap is ever raised dramatically, this becomes the chokepoint
		// and the M3 embedding swap arrives in time.
		$filters['include_archived'] = false; // duplicates against archived don't count
		$result = E2M_Memory_Repository::list( $filters, 1, 500 );

		$matches = [];
		foreach ( $result['memories'] as $candidate ) {
			$candidate_vec = self::vectorize( (string) ( $candidate['content'] ?? '' ) );
			if ( $candidate_vec === [] ) {
				continue;
			}
			$score = self::cosine_similarity( $content_vec, $candidate_vec );
			if ( $score >= $threshold ) {
				$matches[] = [
					'id'    => (int) $candidate['id'],
					'name'  => (string) $candidate['name'],
					'score' => (float) $score,
				];
			}
		}

		// Sort descending by score so the top hit is the most likely
		// duplicate.
		usort(
			$matches,
			static fn ( array $a, array $b ): int => $b['score'] <=> $a['score']
		);

		return $matches;
	}

	/**
	 * Convert text to a normalized TF-IDF-ish term map. We skip the IDF
	 * stage (would require a full corpus pass on every check) and just
	 * use term-frequency normalized by total term count. Good enough for
	 * detecting "this is basically the same memory" — bad at semantic
	 * similarity (which is what the M3 embedding swap fixes).
	 *
	 * @return array<string, float> Term => normalized frequency.
	 */
	private static function vectorize( string $text ): array {
		$text = strtolower( wp_strip_all_tags( $text ) );
		$text = preg_replace( '/[^a-z0-9\s]+/', ' ', $text ) ?? '';
		$tokens = preg_split( '/\s+/', trim( $text ) );
		if ( ! is_array( $tokens ) || $tokens === [] ) {
			return [];
		}

		$counts = [];
		foreach ( $tokens as $token ) {
			$token = trim( $token );
			if ( $token === '' || strlen( $token ) < 3 ) {
				continue; // skip short tokens
			}
			if ( isset( self::STOP_WORDS[ $token ] ) ) {
				continue;
			}
			$counts[ $token ] = ( $counts[ $token ] ?? 0 ) + 1;
		}

		$total = array_sum( $counts );
		if ( $total === 0 ) {
			return [];
		}

		$vector = [];
		foreach ( $counts as $term => $count ) {
			$vector[ $term ] = $count / $total;
		}
		return $vector;
	}

	/**
	 * Cosine similarity between two term-frequency vectors.
	 *
	 * @param array<string, float> $a
	 * @param array<string, float> $b
	 * @return float 0.0–1.0
	 */
	private static function cosine_similarity( array $a, array $b ): float {
		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;

		foreach ( $a as $term => $weight_a ) {
			$mag_a += $weight_a * $weight_a;
			if ( isset( $b[ $term ] ) ) {
				$dot += $weight_a * $b[ $term ];
			}
		}
		foreach ( $b as $weight_b ) {
			$mag_b += $weight_b * $weight_b;
		}

		if ( $mag_a === 0.0 || $mag_b === 0.0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $mag_a ) * sqrt( $mag_b ) );
	}
}
