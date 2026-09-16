<?php
/**
 * E2M Connect — Memory CPT.
 *
 * Registers the `e2m_memory` custom post type used as storage of truth
 * for the unified memory subsystem (custom instructions + skills + memories
 * collapsed into one primitive).
 *
 * Memories carry post-meta for type, status, visibility, confidence,
 * confirmations/contradictions, last_fetched_at, expires_at, tags, slug,
 * and source_audit_id. The denormalized lookup table created in
 * class-e2m-memory-index-table.php mirrors the hot fields for fast
 * filtering — the index is the source of truth for read queries; the
 * CPT is the source of truth for content + revisions.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_CPT {

	public const POST_TYPE = 'e2m_memory';

	// Post-meta keys. Prefixed with underscore so they don't appear in
	// the default Custom Fields metabox even if someone re-enables it.
	public const META_TYPE             = '_e2m_memory_type';
	public const META_STATUS           = '_e2m_memory_status';
	public const META_VISIBILITY       = '_e2m_memory_visibility';
	public const META_CONFIDENCE       = '_e2m_memory_confidence';
	public const META_CONFIRMATIONS    = '_e2m_memory_confirmations';
	public const META_CONTRADICTIONS   = '_e2m_memory_contradictions';
	public const META_LAST_FETCHED_AT  = '_e2m_memory_last_fetched_at';
	public const META_EXPIRES_AT       = '_e2m_memory_expires_at';
	public const META_TAGS             = '_e2m_memory_tags';
	public const META_SLUG             = '_e2m_memory_slug';
	public const META_SOURCE_AUDIT_ID  = '_e2m_memory_source_audit_id';
	public const META_LAST_EDITED_BY   = '_e2m_memory_last_edited_by';
	public const META_EXTRA            = '_e2m_memory_metadata';

	/** @return list<string> */
	public static function get_valid_types(): array {
		return [ 'context', 'rules', 'profile', 'reference' ];
	}

	/** @return list<string> */
	public static function get_valid_statuses(): array {
		return [ 'active', 'always', 'stale', 'disputed', 'archived', 'pinned', 'pending_review' ];
	}

	/** @return list<string> */
	public static function get_valid_visibilities(): array {
		return [ 'private', 'team', 'system' ];
	}

	/**
	 * Default visibility per memory type. Profile defaults to private so a
	 * user's personal preferences don't leak across roles; everything else
	 * defaults to team. System is always an admin escalation, never a
	 * default.
	 */
	public static function default_visibility_for_type( string $type ): string {
		return $type === 'profile' ? 'private' : 'team';
	}

	/**
	 * Register the CPT on init. Idempotent — safe to call multiple times.
	 * Hooked at priority 5 so abilities (registered later on init) can rely
	 * on the post type existing.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'              => __( 'Memories', 'e2mconnect' ),
				'labels'             => [
					'name'          => __( 'Memories', 'e2mconnect' ),
					'singular_name' => __( 'Memory', 'e2mconnect' ),
				],
				// Memory is private storage. We expose our own REST routes
				// with explicit permission callbacks rather than relying on
				// the CPT's REST surface.
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_admin_bar'  => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'supports'           => [ 'title', 'editor', 'excerpt', 'revisions', 'author' ],
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'query_var'          => false,
				'delete_with_user'   => false,
			]
		);

		self::register_meta();
	}

	/**
	 * Register post-meta with sanitization callbacks. show_in_rest stays
	 * false — clients use the dedicated `e2m/memory-*` abilities, not
	 * generic post-meta endpoints.
	 */
	private static function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_TYPE,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::get_valid_types(), true ) ? $value : 'context';
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STATUS,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::get_valid_statuses(), true ) ? $value : 'active';
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_VISIBILITY,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::get_valid_visibilities(), true ) ? $value : 'team';
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CONFIDENCE,
			[
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): float {
					$value = is_numeric( $value ) ? (float) $value : 0.5;
					return max( 0.0, min( 1.0, $value ) );
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CONFIRMATIONS,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( $v ): int => max( 0, (int) $v ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CONTRADICTIONS,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( $v ): int => max( 0, (int) $v ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_LAST_FETCHED_AT,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					return is_string( $value ) ? substr( $value, 0, 19 ) : '';
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_EXPIRES_AT,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					return is_string( $value ) ? substr( $value, 0, 19 ) : '';
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_TAGS,
			[
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): array {
					if ( ! is_array( $value ) ) {
						return [];
					}
					$tags = array_map( 'sanitize_title', $value );
					$tags = array_values( array_unique( array_filter( $tags ) ) );
					return array_slice( $tags, 0, 10 );
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SLUG,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
					$value = preg_replace( '/[^a-z0-9-]/', '', $value ) ?? '';
					return substr( $value, 0, 100 );
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SOURCE_AUDIT_ID,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
			]
		);

		// WordPress's `post_author` already tracks the original creator; this
		// meta records WHO most recently edited the memory. We set it in
		// E2M_Memory_Repository::update() so the human/AI-attribution
		// pipeline stays consistent (AI-via-app-password saves are attributed
		// to whichever WP user the AI is connected as).
		register_post_meta(
			self::POST_TYPE,
			self::META_LAST_EDITED_BY,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_EXTRA,
			[
				'type'              => 'string', // JSON-encoded blob; we serialize before set.
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static function ( $value ): string {
					if ( is_array( $value ) ) {
						$json = wp_json_encode( $value );
						return is_string( $json ) ? $json : '';
					}
					return is_string( $value ) ? $value : '';
				},
			]
		);
	}
}
