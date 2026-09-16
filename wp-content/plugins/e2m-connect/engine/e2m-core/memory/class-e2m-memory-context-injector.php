<?php
/**
 * E2M Connect — Memory Context Injector.
 *
 * Hooks the e2m_engine_server_instructions filter to inject memory
 * content into the system prompt every connected agent sees on first
 * contact (and on subsequent prompts, thanks to Anthropic prompt-cache).
 *
 * Two layers, two priorities:
 *   - Priority 5  -> prepend the always-applied memory block (4 typed
 *                    sections: Context, Rules, Profile, Reference)
 *   - Priority 20 -> append a catalogue of slug-callable memories so
 *                    agents know what playbooks exist
 *
 * Empty memories ship ZERO tokens. If "Rules" status=always is blank,
 * its section disappears entirely (no header, no blank line). If all
 * four are blank, no block is emitted at all.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Context_Injector {

	private const PRIORITY_ALWAYS_BLOCK = 5;
	private const PRIORITY_SLUG_INDEX   = 20;

	/**
	 * Maximum number of always-applied memories injected. Soft safety
	 * cap to prevent prompt bloat if the user somehow accumulates dozens
	 * of always-memories. Configurable through settings later.
	 */
	private const MAX_ALWAYS_MEMORIES = 20;

	/**
	 * Per-request cache. Building the always-block requires several
	 * post-meta reads -- we don't want to redo them when the filter
	 * fires twice in the same request (which can happen during the MCP
	 * server config build).
	 */
	private static ?string $cached_always_block = null;
	private static ?string $cached_slug_index   = null;

	public static function register(): void {
		add_filter( 'e2m_engine_server_instructions', [ self::class, 'inject_always_block' ], self::PRIORITY_ALWAYS_BLOCK );
		add_filter( 'e2m_engine_server_instructions', [ self::class, 'inject_slug_index' ], self::PRIORITY_SLUG_INDEX );
	}

	/**
	 * Prepend always-applied memories grouped by type. Order matches the
	 * blueprint: Context -> Rules -> Profile -> Reference.
	 */
	public static function inject_always_block( string $instructions ): string {
		// Master switch — when the subsystem is disabled in admin, the
		// injector becomes a no-op so the agent sees no memory content
		// in its system prompt.
		if ( function_exists( 'e2m_memory_is_enabled' ) && ! e2m_memory_is_enabled() ) {
			return $instructions;
		}
		$block = self::$cached_always_block ?? self::build_always_block();
		self::$cached_always_block = $block;

		if ( $block === '' ) {
			return $instructions;
		}
		return $block . "\n\n" . $instructions;
	}

	/**
	 * Append a catalogue of slug-callable memories so the agent knows
	 * which playbooks it can fetch by name. Just slug + description --
	 * not the full content (that costs tokens; the agent fetches on
	 * demand via memory-get).
	 */
	public static function inject_slug_index( string $instructions ): string {
		if ( function_exists( 'e2m_memory_is_enabled' ) && ! e2m_memory_is_enabled() ) {
			return $instructions;
		}
		$index = self::$cached_slug_index ?? self::build_slug_index();
		self::$cached_slug_index = $index;

		if ( $index === '' ) {
			return $instructions;
		}
		return $instructions . "\n\n" . $index;
	}

	/**
	 * Build the always-block, or empty string if no eligible memories.
	 */
	private static function build_always_block(): string {
		// Pull all status=always memories (excluding archived implicitly).
		$result = E2M_Memory_Repository::list(
			[ 'status' => 'always' ],
			1,
			self::MAX_ALWAYS_MEMORIES
		);

		// Group by type in display order.
		$by_type = [
			'context'   => [],
			'rules'     => [],
			'profile'   => [],
			'reference' => [],
		];

		foreach ( $result['memories'] as $memory ) {
			$type    = (string) ( $memory['type'] ?? 'context' );
			$content = trim( (string) ( $memory['content'] ?? '' ) );

			// Empty always-memories are SKIPPED. This is the zero-token
			// guarantee from the blueprint -- blank seeds cost nothing.
			if ( $content === '' ) {
				continue;
			}

			// Visibility filter: system memories visible to all; team
			// memories visible to all logged-in users; private memories
			// only to their author. We're building the prompt for a
			// specific user (the one connected), so we honor e2m_memory_user_can_see.
			if ( ! e2m_memory_user_can_see( $memory ) ) {
				continue;
			}

			if ( isset( $by_type[ $type ] ) ) {
				$by_type[ $type ][] = $memory;
			}
		}

		// If every bucket is empty, emit nothing.
		$has_any = false;
		foreach ( $by_type as $bucket ) {
			if ( $bucket !== [] ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return '';
		}

		$type_labels = [
			'context'   => __( 'Context', 'e2mconnect' ),
			'rules'     => __( 'Rules', 'e2mconnect' ),
			'profile'   => __( 'Profile', 'e2mconnect' ),
			'reference' => __( 'Reference', 'e2mconnect' ),
		];

		$lines   = [];
		$lines[] = '## Site memory — always applied';
		$lines[] = '';
		$lines[] = __( 'These rules apply to every action you take on this site. Follow them strictly.', 'e2mconnect' );
		$lines[] = '';

		foreach ( $by_type as $type => $memories ) {
			if ( $memories === [] ) {
				continue;
			}
			$lines[] = '### ' . $type_labels[ $type ];
			foreach ( $memories as $memory ) {
				$lines[] = '';
				$lines[] = trim( (string) $memory['content'] );
			}
			$lines[] = '';
		}

		return rtrim( implode( "\n", $lines ) );
	}

	/**
	 * Build the slug-callable catalogue, or empty string if no eligible
	 * memories have a slug.
	 */
	private static function build_slug_index(): string {
		$result = E2M_Memory_Repository::list(
			[ 'has_slug' => true, 'status' => 'active' ],
			1,
			E2M_Memory_Repository::LIST_MAX_PER_PAGE
		);

		$lines = [];
		foreach ( $result['memories'] as $memory ) {
			if ( ! e2m_memory_user_can_see( $memory ) ) {
				continue;
			}
			$slug = (string) ( $memory['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			$lines[] = sprintf( '- `%s` — %s', $slug, (string) $memory['description'] );
		}

		if ( $lines === [] ) {
			return '';
		}

		array_unshift(
			$lines,
			'## Quick-call memories',
			'',
			__( 'Call `e2m/memory-get` with one of these slugs to load the full playbook on demand. Use them when the topic matches; do not load them speculatively.', 'e2mconnect' ),
			''
		);

		return implode( "\n", $lines );
	}
}
