<?php
/**
 * E2M Connect — Memory Seeds + Legacy Migration.
 *
 * On first activation of v0.1.0 (or upgrade from v0.0.x):
 *   1. Migrate the legacy Goal / Instructions / MD-file options into
 *      typed always-applied memories
 *   2. Install four blank seed memories (Context, Rules, Profile,
 *      Reference) -- only if the user doesn't already have seeds AND
 *      no legacy data was migrated
 *   3. Set e2m_memory_seeds_installed so this never re-runs
 *
 * Empty seeds cost zero tokens in the discover-abilities response
 * (the context injector skips empty content). The point is to give
 * the user a starting place in the admin UI.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Memory_Seeds {

	public const INSTALLED_OPTION = 'e2m_memory_seeds_installed';
	public const VERSION          = '1';

	/**
	 * Idempotent installation. Safe to call on every activation.
	 */
	public static function install_seeds(): void {
		if ( get_option( self::INSTALLED_OPTION, '' ) === self::VERSION ) {
			return;
		}

		// Make sure required dependencies are loaded. Activation hook
		// fires before the regular plugin loader runs, so a bare-bones
		// load is necessary here.
		require_once __DIR__ . '/class-e2m-memory-cpt.php';
		require_once __DIR__ . '/class-e2m-memory-index-table.php';
		require_once __DIR__ . '/class-e2m-memory-permissions.php';
		require_once __DIR__ . '/class-e2m-memory-repository.php';
		require_once __DIR__ . '/class-e2m-memory-audit-bridge.php';

		// CPT must be registered before wp_insert_post knows what to do.
		// Activation hooks fire after 'init' isn't, so we register here
		// if it hasn't fired yet.
		if ( ! post_type_exists( E2M_Memory_CPT::POST_TYPE ) ) {
			E2M_Memory_CPT::register();
		}

		// Index table must exist before sync_index can write to it.
		if ( ! E2M_Memory_Index_Table::exists() ) {
			E2M_Memory_Index_Table::maybe_create_table();
		}

		$migrated_any = self::migrate_legacy_options();
		if ( ! $migrated_any ) {
			self::install_blank_seeds();
		}

		update_option( self::INSTALLED_OPTION, self::VERSION, false );
	}

	/**
	 * Convert the v0.0.x legacy memory options into typed memories.
	 *
	 *   - e2m_memory_instructions_goal     -> type=context, name="Project goal"
	 *   - e2m_memory_instructions_content  -> type=rules,   name="House rules"
	 *   - e2m_memory_instructions_md       -> type=reference, name="Custom instructions"
	 *
	 * All set as status=always, visibility=system. After successful
	 * migration the legacy options are deleted -- the new memories are
	 * the source of truth from v0.1.0 onward.
	 *
	 * @return bool True when at least one legacy option was migrated.
	 */
	private static function migrate_legacy_options(): bool {
		$goal     = trim( (string) get_option( 'e2m_memory_instructions_goal', '' ) );
		$content  = trim( (string) get_option( 'e2m_memory_instructions_content', '' ) );
		$md_file  = trim( (string) get_option( 'e2m_memory_instructions_md', '' ) );
		$md_name  = (string) get_option( 'e2m_memory_instructions_md_filename', '' );

		$migrated = false;
		$author_id = self::pick_admin_author();

		if ( $goal !== '' && strlen( $goal ) >= 30 ) {
			self::create_seed(
				[
					'name'        => __( 'Project goal', 'e2mconnect' ),
					'description' => __( 'Migrated from v0.0.x: the high-level site goal.', 'e2mconnect' ),
					'content'     => $goal,
					'type'        => 'context',
				],
				$author_id
			);
			delete_option( 'e2m_memory_instructions_goal' );
			$migrated = true;
		}

		if ( $content !== '' && strlen( $content ) >= 30 ) {
			self::create_seed(
				[
					'name'        => __( 'House rules', 'e2mconnect' ),
					'description' => __( 'Migrated from v0.0.x: standing rules for every agent action.', 'e2mconnect' ),
					'content'     => $content,
					'type'        => 'rules',
				],
				$author_id
			);
			delete_option( 'e2m_memory_instructions_content' );
			$migrated = true;
		}

		if ( $md_file !== '' && strlen( $md_file ) >= 30 ) {
			$desc = $md_name !== ''
				? sprintf(
					/* translators: %s: original filename */
					__( 'Migrated from v0.0.x: contents of uploaded MD file (%s).', 'e2mconnect' ),
					$md_name
				)
				: __( 'Migrated from v0.0.x: contents of uploaded MD instructions file.', 'e2mconnect' );

			self::create_seed(
				[
					'name'        => __( 'Custom instructions doc', 'e2mconnect' ),
					'description' => $desc,
					'content'     => $md_file,
					'type'        => 'reference',
				],
				$author_id
			);
			delete_option( 'e2m_memory_instructions_md' );
			delete_option( 'e2m_memory_instructions_md_filename' );
			$migrated = true;
		}

		return $migrated;
	}

	/**
	 * Install four blank seed memories — Context, Rules, Profile,
	 * Reference. All status=always, visibility=system. Empty content
	 * means they ship zero tokens; they exist so the user has an
	 * obvious starting place in the admin UI.
	 */
	private static function install_blank_seeds(): void {
		$author_id = self::pick_admin_author();

		$seeds = [
			[
				'type'        => 'context',
				'name'        => __( 'Project context', 'e2mconnect' ),
				'description' => __( 'What we are working on — fill this in to give every agent immediate context.', 'e2mconnect' ),
			],
			[
				'type'        => 'rules',
				'name'        => __( 'House rules', 'e2mconnect' ),
				'description' => __( 'How you want the AI to work — add team conventions, design rules, and hard constraints here.', 'e2mconnect' ),
			],
			[
				'type'        => 'profile',
				'name'        => __( 'Site profile', 'e2mconnect' ),
				'description' => __( 'Who this site is for — audience, brand, business goals.', 'e2mconnect' ),
			],
			[
				'type'        => 'reference',
				'name'        => __( 'External resources', 'e2mconnect' ),
				'description' => __( 'Pointers to your brand guide, content style guide, and any external systems.', 'e2mconnect' ),
			],
		];

		foreach ( $seeds as $seed ) {
			// Seeds ship with empty content so unmodified placeholders
			// never get prepended to the agent context. The
			// discover-abilities injector already skips empty
			// status=always memories, so this is the cheapest "ship
			// blank, user fills in" model. We bypass the repository's
			// validators (which require 30 chars) by inserting the
			// post + meta directly here.
			self::install_blank_seed( $seed, $author_id );
		}
	}

	/**
	 * Bypass-the-validator seed insert. We can't use E2M_Memory_Repository::create()
	 * because its validator enforces 30-char content minimum, and the
	 * whole point of a seed is to ship blank for the user to fill in.
	 *
	 * @param array{type:string, name:string, description:string} $seed
	 */
	private static function install_blank_seed( array $seed, int $author_id ): void {
		$post_id = wp_insert_post(
			[
				'post_type'    => E2M_Memory_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $seed['name'],
				'post_excerpt' => $seed['description'],
				'post_content' => '', // truly blank — the user fills this in
				'post_author'  => $author_id > 0 ? $author_id : 1,
			],
			true
		);
		if ( is_wp_error( $post_id ) || ! is_int( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, E2M_Memory_CPT::META_TYPE,            $seed['type'] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_STATUS,          'always' );
		update_post_meta( $post_id, E2M_Memory_CPT::META_VISIBILITY,      'system' );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONFIDENCE,      0.90 );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONFIRMATIONS,   0 );
		update_post_meta( $post_id, E2M_Memory_CPT::META_CONTRADICTIONS,  0 );
		update_post_meta( $post_id, E2M_Memory_CPT::META_TAGS,            [] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_SLUG,            '' );
		update_post_meta( $post_id, E2M_Memory_CPT::META_SOURCE_AUDIT_ID, 0 );

		$metadata_json = wp_json_encode( [ 'trigger_type' => 'explicit_save', 'origin' => 'seed' ] );
		update_post_meta( $post_id, E2M_Memory_CPT::META_EXTRA, is_string( $metadata_json ) ? $metadata_json : '' );

		// The save_post_e2m_memory hook already calls sync_index, but
		// we call it explicitly here for safety — wp_insert_post during
		// activation may fire before our index-table check.
		if ( class_exists( 'E2M_Memory_Index_Table' ) && E2M_Memory_Index_Table::exists() ) {
			E2M_Memory_Repository::sync_index( $post_id );
		}
	}

	/**
	 * Resolve which user to attribute seed memories to. Falls back to
	 * the first administrator we can find.
	 */
	private static function pick_admin_author(): int {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			$user = get_user_by( 'id', $current );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
				return $current;
			}
		}
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Single-call create that sets the seed-specific defaults (status,
	 * visibility, trigger metadata).
	 */
	private static function create_seed( array $base, int $author_id ): void {
		$payload = array_merge(
			$base,
			[
				'visibility' => 'system',
				'status'     => 'always',
				'confidence' => 0.90, // seed = explicit owner intent
				'metadata'   => [
					'trigger_type' => 'explicit_save',
					'origin'       => 'seed',
				],
			]
		);
		E2M_Memory_Repository::create( $payload, $author_id );
	}
}
