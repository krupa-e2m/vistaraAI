<?php
/**
 * E2M Connect MCP - Gutenberg Manage Navigation
 *
 * CRUD on `wp_navigation` - the post type backing the block-editor
 * Navigation block. A `core/navigation` block binds to a specific
 * wp_navigation post via `<!-- wp:navigation {"ref":<post_id>} /-->`
 * (confirmed against wp-includes/blocks/navigation/block.json - the `ref`
 * attribute is identical in shape/purpose to `core/block`'s own `ref`,
 * which e2m/gutenberg-manage-synced-patterns already documents for synced
 * patterns) - core resolves it live at render time via
 * `get_post( $attributes['ref'] )`, requiring `post_status === 'publish'`.
 *
 * Unlike e2m/gutenberg-manage-templates / -template-parts, `wp_navigation`
 * has NO theme-file counterpart and NO `wp_theme` taxonomy scoping
 * (confirmed directly against wp-includes/taxonomy.php's `wp_theme`
 * registration, which lists wp_template/wp_template_part/wp_global_styles
 * but not wp_navigation) - a navigation menu is theme-agnostic CONTENT,
 * exactly like a classic nav menu, and persists across theme switches.
 * There is therefore no "source: theme vs custom" concept and no
 * "can't delete a theme-file-backed resource" guard the way those two
 * abilities need - every wp_navigation post here is an ordinary, fully
 * deletable DB row. CRUD follows the same idiom as
 * e2m/gutenberg-manage-synced-patterns (wp_insert_post/wp_update_post/
 * get_post/wp_delete_post), not the templates/template-parts pattern.
 *
 * A wp_navigation post's own post_content is a FLAT list of
 * core/navigation-link / core/navigation-submenu / core/page-list /
 * core/social-links / core/home-link (etc.) child blocks - confirmed
 * against real theme-bundled examples (twentytwentyfive's patterns) -
 * with NO wrapping `core/navigation` block inside the post itself; the
 * `core/navigation` wrapper only exists in the CONSUMING template/
 * template-part, referencing this post's id via `ref`.
 *
 * Confirmed elevated capability requirement: wp_navigation maps EVERY
 * capability to `edit_theme_options` (verified against its registration
 * in wp-includes/post.php - no `capability_type` key, every entry hardcoded
 * to `edit_theme_options`), stricter/flatter than wp_block's scheme (which
 * maps through the ordinary edit_posts/publish_posts capabilities). A
 * caller running as a lower-privileged user will get an ordinary
 * capability-mapped rejection from wp_insert_post()/wp_update_post(), not a
 * special error from this ability.
 *
 * Also exposes a "get_fallback" action wrapping WordPress core's own
 * WP_Navigation_Fallback::get_fallback() (since 6.3.0) - the exact
 * mechanism the block editor itself uses when a Navigation block has no
 * `ref`: reuse the most recently published wp_navigation post if one
 * exists, else convert the site's classic "primary" nav menu into one, else
 * create a default post containing `<!-- wp:page-list /-->`. Wrapping this
 * (rather than only offering plain CRUD) gives a caller wiring a header/
 * footer template part a correct way to get a sensible default navigation
 * instead of hand-rolling the same fallback logic or hardcoding a
 * post_id-0 special case.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-navigation', [
	'label'       => __( '[Gutenberg] Manage Navigation', 'e2mconnect' ),
	'description' => __( 'CRUD on wp_navigation (the post type backing the Navigation block) + a get_fallback action mirroring WP_Navigation_Fallback::get_fallback(). Bind a core/navigation block to the result via <!-- wp:navigation {"ref":<post_id>} /-->. Theme-agnostic - no theme-file counterpart, no wp_theme taxonomy scoping, unlike templates/template-parts. Requires edit_theme_options (not merely edit_posts).', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [
				'type'        => 'string',
				'enum'        => [ 'create', 'read', 'update', 'delete', 'list', 'get_fallback' ],
				'description' => '"create" inserts a new wp_navigation post. "read" fetches one by post_id. "update" changes title/content. "delete" permanently removes one (no theme-file fallback exists, so there is nothing to guard against - unlike templates/template-parts). "list" returns every wp_navigation post (title + id only). "get_fallback" returns the post WordPress core itself would use when a Navigation block has no ref - reusing the most recently published navigation, converting the classic "primary" menu, or creating a default (may create a post as a side effect, matching core\'s own fallback behavior exactly).',
			],
			'post_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Required for "read"/"update". Optional for "delete" (required in practice - there is no slug-based fallback resolution the way templates/template-parts have). Passing it back from a prior read/create/list/get_fallback lets the safety gatekeeper\'s automatic snapshot engage.',
			],
			'title'   => [ 'type' => 'string', 'description' => 'Required for "create". Optional for "update".' ],
			'content' => [ 'type' => 'string', 'description' => 'Required for "create". Raw Gutenberg block markup - a flat list of core/navigation-link / core/navigation-submenu / core/page-list / etc. child blocks, with NO wrapping core/navigation block (that wrapper lives only in the consuming template/template-part, referencing this post via ref). Optional for "update" (fully replaces existing content when given).' ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer' ],
			'title'        => [ 'type' => 'string' ],
			'content'      => [ 'type' => 'string' ],
			'navigations'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list" - every wp_navigation post\'s {post_id, title}.' ],
			'fallback_source' => [ 'type' => 'string', 'description' => 'Present on "get_fallback" only - which fallback tier produced the result: "existing" (reused a published navigation), "classic_menu" (converted a classic nav menu), or "default" (created a fresh default post).' ],
			'validation'   => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_navigation_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Navigation',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-navigation ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_navigation_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete', 'list', 'get_fallback' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: create, read, update, delete, list, get_fallback.', 'e2mconnect' ) );
	}

	if ( ! post_type_exists( 'wp_navigation' ) ) {
		return new WP_Error( 'navigation_unavailable', __( 'This WordPress core does not support wp_navigation.', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return e2m_engine_gutenberg_list_navigations();
		case 'read':
			return e2m_engine_gutenberg_read_navigation( $input );
		case 'create':
			return e2m_engine_gutenberg_create_navigation( $input );
		case 'update':
			return e2m_engine_gutenberg_update_navigation( $input );
		case 'delete':
			return e2m_engine_gutenberg_delete_navigation( $input );
		case 'get_fallback':
			return e2m_engine_gutenberg_get_navigation_fallback();
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * List every wp_navigation post (title + id only, matching the cheap-list
 * convention e2m_engine_gutenberg_list_synced_patterns() already
 * establishes for wp_block).
 *
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_list_navigations(): array {
	$posts = get_posts( [
		'post_type'      => 'wp_navigation',
		'post_status'    => [ 'publish', 'draft', 'private' ],
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$navigations = [];
	foreach ( $posts as $post ) {
		$navigations[] = [
			'post_id' => (int) $post->ID,
			'title'   => (string) $post->post_title,
		];
	}

	return [ 'navigations' => $navigations ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_navigation( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "read".', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'wp_navigation' ) {
		return new WP_Error( 'navigation_not_found', __( 'No wp_navigation post exists with the given post_id.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => (int) $post->ID,
		'title'      => (string) $post->post_title,
		'content'    => (string) $post->post_content,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_navigation( array $input ) {
	if ( empty( $input['title'] ) || ! is_string( $input['title'] ) ) {
		return new WP_Error( 'missing_title', __( 'title is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}
	if ( empty( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return new WP_Error( 'missing_content', __( 'content is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}

	$validation = e2m_engine_gutenberg_validate_navigation_content( (string) $input['content'] );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'navigation_content_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'content failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	$post_id = wp_insert_post(
		[
			'post_type'    => 'wp_navigation',
			'post_title'   => wp_strip_all_tags( (string) $input['title'] ),
			'post_content' => wp_slash( (string) $input['content'] ),
			'post_status'  => 'publish',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return [
		'post_id'    => (int) $post_id,
		'title'      => (string) $input['title'],
		'content'    => (string) $input['content'],
		'validation' => $validation,
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_update_navigation( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "update".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_navigation' ) {
		return new WP_Error( 'navigation_not_found', __( 'No wp_navigation post exists with the given post_id.', 'e2mconnect' ) );
	}

	$update_args = [ 'ID' => $post_id ];
	$validation  = [ 'valid' => true, 'warnings' => [], 'errors' => [] ];

	if ( isset( $input['title'] ) ) {
		if ( ! is_string( $input['title'] ) || $input['title'] === '' ) {
			return new WP_Error( 'invalid_title', __( 'title must be a non-empty string.', 'e2mconnect' ) );
		}
		$update_args['post_title'] = wp_strip_all_tags( (string) $input['title'] );
	}

	$resulting_content = (string) $existing->post_content;
	if ( isset( $input['content'] ) ) {
		if ( ! is_string( $input['content'] ) || $input['content'] === '' ) {
			return new WP_Error( 'invalid_content', __( 'content must be a non-empty string.', 'e2mconnect' ) );
		}
		$validation = e2m_engine_gutenberg_validate_navigation_content( (string) $input['content'] );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'navigation_content_invalid',
				sprintf(
					/* translators: %s: joined validation error messages */
					__( 'content failed validation: %s', 'e2mconnect' ),
					implode( '; ', $validation['errors'] )
				),
				[ 'validation' => $validation ]
			);
		}
		$update_args['post_content'] = wp_slash( (string) $input['content'] );
		$resulting_content           = (string) $input['content'];
	}

	if ( count( $update_args ) > 1 ) {
		$updated = wp_update_post( $update_args, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	return [
		'post_id'    => $post_id,
		'title'      => isset( $update_args['post_title'] ) ? (string) $update_args['post_title'] : (string) $existing->post_title,
		'content'    => $resulting_content,
		'validation' => $validation,
	];
}

/**
 * Delete a wp_navigation post. No theme-file counterpart exists for this
 * post type (confirmed - navigation is theme-agnostic content, not scoped
 * via the wp_theme taxonomy the way templates/template-parts are), so
 * unlike e2m/gutenberg-manage-templates / -template-parts there is no
 * "can't delete a theme-file-backed resource" case to guard against - every
 * wp_navigation post is an ordinary, fully deletable DB row.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_navigation( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "delete".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_navigation' ) {
		return new WP_Error( 'navigation_not_found', __( 'No wp_navigation post exists with the given post_id.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, true );
	if ( ! $result ) {
		return new WP_Error( 'navigation_delete_failed', __( 'Failed to delete the wp_navigation post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Wrap WordPress core's own WP_Navigation_Fallback::get_fallback() - the
 * exact mechanism the block editor itself uses when a Navigation block has
 * no `ref`. May create a new wp_navigation post as a side effect (a classic-
 * menu conversion or a brand-new default post), matching core's own
 * behavior exactly rather than only ever reading.
 *
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_get_navigation_fallback() {
	if ( ! class_exists( 'WP_Navigation_Fallback' ) ) {
		return new WP_Error( 'navigation_fallback_unavailable', __( 'WP_Navigation_Fallback is unavailable on this WordPress core.', 'e2mconnect' ) );
	}

	// Check deterministically, BEFORE calling get_fallback(), whether a
	// published navigation already exists - this is the exact same query
	// WP_Navigation_Fallback::get_most_recently_published_navigation() runs
	// internally (post_type wp_navigation, post_status publish, 1 result),
	// so knowing the answer beforehand tells us with certainty whether
	// get_fallback() is about to return that existing post untouched or is
	// about to create a NEW one (classic-menu conversion or default) - no
	// need to guess from timestamps or content after the fact.
	$had_existing = ! empty( get_posts( [
		'post_type'      => 'wp_navigation',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	] ) );

	$post = WP_Navigation_Fallback::get_fallback();
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'navigation_fallback_failed', __( 'WordPress core could not produce a fallback navigation (no published navigation exists, no classic menu to convert, and core/page-list is unavailable to seed a default).', 'e2mconnect' ) );
	}

	if ( $had_existing ) {
		$fallback_source = 'existing';
	} elseif (
		(string) $post->post_name === 'navigation'
		&& in_array( trim( (string) $post->post_content ), [ '<!-- wp:page-list /-->', '' ], true )
	) {
		// The exact post_name + content shape create_default_fallback()
		// itself hardcodes (post_name 'navigation', content either
		// '<!-- wp:page-list /-->' or '' if core/page-list isn't
		// registered - see WP_Navigation_Fallback::get_default_fallback_
		// blocks()). A classic-menu conversion never produces this - it
		// carries the converted menu's own real nav-link blocks.
		$fallback_source = 'default';
	} else {
		$fallback_source = 'classic_menu';
	}

	return [
		'post_id'         => (int) $post->ID,
		'title'           => (string) $post->post_title,
		'content'         => (string) $post->post_content,
		'fallback_source' => $fallback_source,
		'validation'      => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * This ability's own explicit structural validation for navigation content -
 * conservative parse/serialize round-trip check, identical approach to
 * e2m/gutenberg-manage-synced-patterns' equivalent function.
 *
 * @param string $content
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_navigation_content( string $content ): array {
	$errors   = [];
	$warnings = [];

	if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
		$parsed       = parse_blocks( $content );
		$reserialized = serialize_blocks( $parsed );
		if ( trim( (string) $reserialized ) === '' && trim( $content ) !== '' ) {
			$errors[] = 'content did not parse into any recognizable block - check for malformed block comment delimiters.';
		}
	}

	$warnings[] = 'This ability checks parse/serialize round-trip stability only. Call e2m/gutenberg-validate-blocks with this content as block_markup for the full registry-resolution and attribute-schema gate before create/update on a navigation that will actually render.';

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
