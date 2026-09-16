<?php
/**
 * E2M Connect MCP - Gutenberg Manage Templates
 *
 * CRUD + hierarchy mapping for `wp_template` - WordPress's FSE template
 * system (front-page, page, single, archive, index, 404, etc.). Distinct
 * from e2m/gutenberg-manage-theme-json (S5-G5, the theme's design-token
 * defaults) and e2m/gutenberg-manage-global-styles (S5-G6, the user's
 * runtime style overrides) - a `wp_template` post is a full page LAYOUT
 * (block markup), not styling.
 *
 * Uses core's own real resolution functions - get_block_templates()/
 * get_block_template() (wp-includes/block-template-utils.php) - rather than
 * a hand-rolled WP_Query, for the same reason e2m/gutenberg-manage-global-
 * styles uses WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles():
 * these are the exact lookups core's own Site Editor uses, so this ability
 * never drifts from how WordPress itself resolves "the" template for a
 * given slug. Confirmed: a `wp_template` post is scoped to the active theme
 * via the SAME `wp_theme` taxonomy `wp_global_styles`/`wp_template_part`
 * already use (registered once, on all three post types).
 *
 * A template SLUG can resolve to either a theme FILE (wp-content/themes/
 * <theme>/templates/<slug>.html, no DB row, `source: "theme"`) or a
 * CUSTOMIZED DB row (`wp_template` post, `source: "custom"`) that overrides
 * the file - core distinguishes these purely by slug + `wp_theme` taxonomy
 * match, never a separate flag; a DB row for a given slug always wins over
 * the file. This ability's "list"/"read" surface BOTH kinds (matching
 * get_block_templates()'s own merge behavior) so a caller can see what
 * would actually render even before any DB row exists; only "create"/
 * "update"/"delete" require (or create) a real DB row.
 *
 * Confirmed via WP_REST_Templates_Controller::delete_item(): a template
 * whose resolved `source` is "theme" (pure file, no DB row) CANNOT be
 * deleted - there is nothing to delete. This ability replicates that guard
 * rather than silently no-op-ing or erroring with a confusing message.
 *
 * theme_mode (block/hybrid/classic, from server ability S6's
 * detect-page-builder) is NOT a hard gate here - confirmed core's own REST
 * controller has no such gate either; wp_template CRUD works on any theme,
 * it simply renders nothing on a classic theme (nothing in the classic
 * rendering pipeline calls get_block_template()). A non-"block" theme_mode
 * is surfaced as a WARNING, not a rejection - the caller (gutenberg-
 * foundation-builder, per its own fse_mode check) already owns the decision
 * of whether full FSE templates make sense for this project; this ability
 * should not re-decide that by refusing to write.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-templates', [
	'label'       => __( '[Gutenberg] Manage Templates', 'e2mconnect' ),
	'description' => __( 'CRUD + hierarchy listing for wp_template (FSE page templates - front-page, page, single, archive, index, 404, etc). Distinguishes theme-file-backed templates (source: "theme", read-only - cannot be deleted, can be "customized" by create/update which then creates the overriding DB row) from customized DB-row templates (source: "custom").', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'      => [
				'type'        => 'string',
				'enum'        => [ 'create', 'read', 'update', 'delete', 'list' ],
				'description' => '"create" inserts a new custom wp_template DB row for a slug (or overrides/customizes an existing theme-file template at that slug - same operation, core does not distinguish "new" from "override" at the API level). "read" resolves one template by slug (theme-file, DB-row, or the merged result if both exist - DB always wins). "update" changes title/content/description on an existing DB-row template. "delete" removes a DB-row template - rejected if the resolved template has no DB row (source: "theme"). "list" returns every resolvable template (theme files + DB rows + any plugin-registered ones), matching what get_block_templates() itself returns.',
			],
			'slug'        => [
				'type'        => 'string',
				'description' => 'Required for "read"/"create"/"delete". The template slug (e.g. "front-page", "single", "page-about"). For "update", slug is read from the resolved post_id instead.',
			],
			'post_id'     => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Required for "update". Optional for "delete" (falls back to resolving by slug if omitted). Passing it back from a prior "read"/"create"/"list" lets the safety gatekeeper\'s automatic snapshot engage (it pattern-matches on this literal input key). Validated against the slug\'s actually-resolved DB row when both are given - a mismatch is rejected, never silently overwrites the wrong post.',
			],
			'title'       => [ 'type' => 'string', 'description' => 'Required for "create". Optional for "update".' ],
			'content'     => [ 'type' => 'string', 'description' => 'Required for "create". Raw Gutenberg block markup for the template body. Optional for "update" (fully replaces existing content when given - no merge semantics for a block tree).' ],
			'description' => [ 'type' => 'string', 'description' => 'Optional, create/update. Human-readable description shown in the Site Editor template picker.' ],
			'theme_scope' => [
				'type'    => 'string',
				'enum'    => [ 'child', 'parent' ],
				'default' => 'child',
				'description' => 'Which theme\'s templates/ directory to check for a theme-file match. Does not affect which theme the DB row is scoped to (always the active theme via get_stylesheet(), matching core\'s own tax_query behavior).',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer', 'description' => '0 when the resolved template has no DB row (pure theme-file, source: "theme").' ],
			'slug'         => [ 'type' => 'string' ],
			'title'        => [ 'type' => 'string' ],
			'content'      => [ 'type' => 'string' ],
			'description'  => [ 'type' => 'string' ],
			'source'       => [ 'type' => 'string', 'description' => '"custom" (DB row) or "theme" (file, no DB row) - core\'s own WP_Block_Template::source value.' ],
			'templates'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list" - every resolvable template\'s {slug, title, source, post_id}.' ],
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

	'execute_callback'    => 'e2m_engine_gutenberg_manage_templates_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Templates',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-templates ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_templates_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete', 'list' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: create, read, update, delete, list.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'get_block_templates' ) || ! post_type_exists( 'wp_template' ) ) {
		return new WP_Error( 'templates_unavailable', __( 'This WordPress core does not support block templates (wp_template).', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return e2m_engine_gutenberg_list_templates();
		case 'read':
			return e2m_engine_gutenberg_read_template( $input );
		case 'create':
			return e2m_engine_gutenberg_create_or_customize_template( $input );
		case 'update':
			return e2m_engine_gutenberg_update_template( $input );
		case 'delete':
			return e2m_engine_gutenberg_delete_template( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * List every resolvable wp_template (theme files + DB rows + any
 * plugin-registered templates) via core's own get_block_templates() - the
 * exact merge behavior the Site Editor's own template list uses.
 *
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_list_templates(): array {
	$results = get_block_templates( [], 'wp_template' );

	$templates = [];
	foreach ( $results as $template ) {
		$templates[] = [
			'slug'    => (string) $template->slug,
			'title'   => (string) $template->title,
			'source'  => (string) $template->source,
			'post_id' => isset( $template->wp_id ) ? (int) $template->wp_id : 0,
		];
	}

	return [ 'templates' => $templates ];
}

/**
 * Resolve one template by slug via get_block_template() - the id format
 * core itself uses internally is "<theme>//<slug>"; this ability accepts a
 * bare slug and builds that composite id against the active theme, since a
 * caller has no reason to know or supply the theme half explicitly (the
 * active theme is not optional/ambiguous the way it could be in a
 * multi-theme scenario core itself does not support at runtime).
 *
 * @param string $slug
 * @return WP_Block_Template|null
 */
function e2m_engine_gutenberg_resolve_template( string $slug ) {
	$theme = get_stylesheet();
	return get_block_template( $theme . '//' . $slug, 'wp_template' );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_template( array $input ) {
	$slug = isset( $input['slug'] ) ? (string) $input['slug'] : '';
	if ( $slug === '' ) {
		return new WP_Error( 'missing_slug', __( 'slug is required for "read".', 'e2mconnect' ) );
	}

	$template = e2m_engine_gutenberg_resolve_template( $slug );
	if ( ! $template ) {
		return new WP_Error( 'template_not_found', __( 'No theme-file or DB-row template resolves to this slug.', 'e2mconnect' ) );
	}

	return [
		'post_id'     => isset( $template->wp_id ) ? (int) $template->wp_id : 0,
		'slug'        => (string) $template->slug,
		'title'       => (string) $template->title,
		'content'     => (string) $template->content,
		'description' => (string) $template->description,
		'source'      => (string) $template->source,
		'validation'  => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Create a new custom template, or "customize" an existing theme-file
 * template at the same slug (core does not distinguish these two cases at
 * the API level - inserting a wp_template post at a slug that already has
 * a theme-file simply makes the DB row win from then on, per core's own
 * slug+wp_theme-taxonomy precedence rule).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_or_customize_template( array $input ) {
	$slug = isset( $input['slug'] ) ? (string) $input['slug'] : '';
	if ( $slug === '' ) {
		return new WP_Error( 'missing_slug', __( 'slug is required for "create".', 'e2mconnect' ) );
	}
	if ( empty( $input['title'] ) || ! is_string( $input['title'] ) ) {
		return new WP_Error( 'missing_title', __( 'title is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}
	if ( empty( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return new WP_Error( 'missing_content', __( 'content is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}

	$validation = e2m_engine_gutenberg_validate_template_content( (string) $input['content'] );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'template_content_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'content failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	$existing = e2m_engine_gutenberg_resolve_template( $slug );
	if ( $existing && isset( $existing->wp_id ) && (int) $existing->wp_id > 0 ) {
		return new WP_Error(
			'template_already_customized',
			__( 'A custom DB-row template already exists for this slug - use "update" (with its post_id) instead of "create".', 'e2mconnect' )
		);
	}

	$post_id = wp_insert_post(
		[
			'post_type'    => 'wp_template',
			'post_name'    => sanitize_title( $slug ),
			'post_title'   => wp_strip_all_tags( (string) $input['title'] ),
			'post_content' => wp_slash( (string) $input['content'] ),
			'post_excerpt' => isset( $input['description'] ) ? wp_strip_all_tags( (string) $input['description'] ) : '',
			'post_status'  => 'publish',
			'tax_input'    => [ 'wp_theme' => [ get_stylesheet() ] ],
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	// tax_input on wp_insert_post() only applies terms for taxonomies the
	// current user can assign; explicitly set it too so a customization
	// made by a capability that lacks assign_terms on wp_theme still lands
	// the term correctly (wp_theme is show_in_rest=>false, non-public, so
	// there is no assign_terms capability gate to worry about in practice,
	// but setting it explicitly costs nothing and removes the ambiguity).
	wp_set_post_terms( (int) $post_id, [ get_stylesheet() ], 'wp_theme', false );

	if ( function_exists( 'e2m_engine_detect_theme_mode' ) ) {
		[ $theme_mode ] = e2m_engine_detect_theme_mode();
		if ( $theme_mode !== 'block' ) {
			$validation['warnings'][] = sprintf(
				/* translators: %s: detected theme_mode value */
				__( 'The active theme\'s detected theme_mode is "%s", not "block" - this template will not render until the active theme is a full block/FSE theme (detect-page-builder\'s theme_mode field). The write still succeeded.', 'e2mconnect' ),
				(string) $theme_mode
			);
		}
	}

	return [
		'post_id'     => (int) $post_id,
		'slug'        => $slug,
		'title'       => (string) $input['title'],
		'content'     => (string) $input['content'],
		'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
		'source'      => 'custom',
		'validation'  => $validation,
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_update_template( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "update".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_template' ) {
		return new WP_Error( 'template_not_found', __( 'No wp_template post exists with the given post_id.', 'e2mconnect' ) );
	}

	$update_args = [ 'ID' => $post_id ];
	$validation  = [ 'valid' => true, 'warnings' => [], 'errors' => [] ];

	if ( isset( $input['title'] ) ) {
		if ( ! is_string( $input['title'] ) || $input['title'] === '' ) {
			return new WP_Error( 'invalid_title', __( 'title must be a non-empty string.', 'e2mconnect' ) );
		}
		$update_args['post_title'] = wp_strip_all_tags( (string) $input['title'] );
	}
	if ( isset( $input['description'] ) ) {
		$update_args['post_excerpt'] = wp_strip_all_tags( (string) $input['description'] );
	}

	$resulting_content = (string) $existing->post_content;
	if ( isset( $input['content'] ) ) {
		if ( ! is_string( $input['content'] ) || $input['content'] === '' ) {
			return new WP_Error( 'invalid_content', __( 'content must be a non-empty string.', 'e2mconnect' ) );
		}
		$validation = e2m_engine_gutenberg_validate_template_content( (string) $input['content'] );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'template_content_invalid',
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
		'post_id'     => $post_id,
		'slug'        => (string) $existing->post_name,
		'title'       => isset( $update_args['post_title'] ) ? (string) $update_args['post_title'] : (string) $existing->post_title,
		'content'     => $resulting_content,
		'description' => isset( $update_args['post_excerpt'] ) ? (string) $update_args['post_excerpt'] : (string) $existing->post_excerpt,
		'source'      => 'custom',
		'validation'  => $validation,
	];
}

/**
 * Delete a DB-row template. Rejects (does not silently no-op) when the
 * resolved template has no DB row at all - matching
 * WP_REST_Templates_Controller::delete_item()'s own "Templates based on
 * theme files can't be removed" rule exactly, since there is genuinely
 * nothing to delete for a pure theme-file template.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_template( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$slug    = isset( $input['slug'] ) ? (string) $input['slug'] : '';

	if ( $post_id <= 0 ) {
		if ( $slug === '' ) {
			return new WP_Error( 'missing_identifier', __( 'Either post_id or slug is required for "delete".', 'e2mconnect' ) );
		}
		$resolved = e2m_engine_gutenberg_resolve_template( $slug );
		if ( ! $resolved || empty( $resolved->wp_id ) ) {
			return new WP_Error(
				'template_not_custom',
				__( 'Templates based on theme files can\'t be removed - this slug has no DB-row template to delete.', 'e2mconnect' )
			);
		}
		$post_id = (int) $resolved->wp_id;
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_template' ) {
		return new WP_Error( 'template_not_found', __( 'No wp_template post exists with the given post_id.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, true );
	if ( ! $result ) {
		return new WP_Error( 'template_delete_failed', __( 'Failed to delete the wp_template post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * This ability's own explicit structural validation for template content -
 * conservative parse/serialize round-trip check, mirroring
 * e2m/gutenberg-manage-synced-patterns' equivalent function. A caller
 * wanting the full registry/attribute-schema gate should also call
 * e2m/gutenberg-validate-blocks with this content as block_markup, same
 * "nothing else writes safely without it" relationship that ability
 * documents for every other Gutenberg-writing ability.
 *
 * @param string $content
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_template_content( string $content ): array {
	$errors   = [];
	$warnings = [];

	if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
		$parsed       = parse_blocks( $content );
		$reserialized = serialize_blocks( $parsed );
		if ( trim( (string) $reserialized ) === '' && trim( $content ) !== '' ) {
			$errors[] = 'content did not parse into any recognizable block - check for malformed block comment delimiters.';
		}
	}

	$warnings[] = 'This ability checks parse/serialize round-trip stability only. Call e2m/gutenberg-validate-blocks with this content as block_markup for the full registry-resolution and attribute-schema gate before create/update on a template that will actually render.';

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
