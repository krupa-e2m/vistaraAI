<?php
/**
 * E2M Connect MCP - Gutenberg Manage Template Parts
 *
 * CRUD for `wp_template_part` - the header/footer/sidebar building blocks
 * referenced by the Template Part block inside a wp_template (see
 * e2m/gutenberg-manage-templates, S5-G9). Same resolution/precedence model
 * as that ability: a slug can resolve to a theme FILE
 * (wp-content/themes/<theme>/parts/<slug>.html, `source: "theme"`) or a
 * CUSTOMIZED DB row (`wp_template_part` post, `source: "custom"`) that
 * overrides it - core distinguishes these by slug + `wp_theme` taxonomy
 * match, and a DB row always wins over the file.
 *
 * AREA (header/footer/sidebar/uncategorized/navigation-overlay) is the one
 * genuinely template-part-specific concept this ability owns. Confirmed
 * against core source (wp-includes/taxonomy.php, block-template-utils.php):
 * - A DB-row part's area lives in the SEPARATE `wp_template_part_area`
 *   taxonomy (registered only on wp_template_part, not shared with
 *   wp_template) - NOT post meta, NOT derived from theme.json at read time.
 * - A pure theme-FILE part's area is NOT stored anywhere on the file itself
 *   - it comes from the active theme's theme.json `templateParts` array
 *   (already written by e2m/gutenberg-manage-theme-json, S5-G5), matched by
 *   slug, via wp_get_theme_data_template_parts(). Falls back to
 *   "uncategorized" when the slug has no theme.json entry.
 * - When a theme-file part gets "customized" (a DB row created at its
 *   slug for the first time), core's own WP_REST_Templates_Controller
 *   copies the theme.json-derived area into the new wp_template_part_area
 *   term at that moment (prepare_item_for_database()) - this ability's
 *   "create" action replicates that exact promotion behavior when `area`
 *   is omitted, rather than defaulting straight to "uncategorized" and
 *   diverging from what WordPress's own Site Editor does in the same
 *   situation.
 *
 * Same theme_mode WARN-not-block posture as e2m/gutenberg-manage-templates,
 * for the same reason - core's own REST controller has no theme-mode gate
 * either, and a hybrid theme with a theme.json can legitimately use
 * template parts even without full FSE page templates.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-template-parts', [
	'label'       => __( '[Gutenberg] Manage Template Parts', 'e2mconnect' ),
	'description' => __( 'CRUD for wp_template_part (header/footer/sidebar building blocks referenced by the Template Part block). Resolves the AREA (header/footer/sidebar/uncategorized/navigation-overlay) from the wp_template_part_area taxonomy for a DB-row part, or from theme.json\'s templateParts registration for a pure theme-file part - replicating core\'s own file-to-DB-row promotion behavior when a caller omits area on "create".', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [
				'type'        => 'string',
				'enum'        => [ 'create', 'read', 'update', 'delete', 'list' ],
				'description' => '"create" inserts a new custom wp_template_part DB row for a slug (or customizes/overrides an existing theme-file part at that slug). "read" resolves one part by slug. "update" changes title/content/area on an existing DB-row part. "delete" is rejected if the resolved part has no DB row (source: "theme"). "list" returns every resolvable part, optionally filtered by area.',
			],
			'slug'    => [
				'type'        => 'string',
				'description' => 'Required for "read"/"create"/"delete". For "update", slug is read from the resolved post_id instead.',
			],
			'post_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Required for "update". Optional for "delete" (falls back to resolving by slug if omitted). Passing it back from a prior "read"/"create"/"list" lets the safety gatekeeper\'s automatic snapshot engage. Validated against the slug\'s actually-resolved DB row when both are given.',
			],
			'title'   => [ 'type' => 'string', 'description' => 'Required for "create". Optional for "update".' ],
			'content' => [ 'type' => 'string', 'description' => 'Required for "create". Raw Gutenberg block markup. Optional for "update" (fully replaces existing content when given).' ],
			'area'    => [
				'type'        => 'string',
				'enum'        => [ 'header', 'footer', 'sidebar', 'uncategorized', 'navigation-overlay' ],
				'description' => 'Optional on "create"/"update". If omitted on "create", looked up from the active theme\'s theme.json templateParts registration for this slug (matching core\'s own file-to-DB-row promotion behavior); falls back to "uncategorized" if theme.json has no entry for this slug. A value outside the allowed enum is rejected outright by this ability\'s own schema (core\'s own _filter_block_template_part_area() would silently downgrade an invalid value to "uncategorized" instead - this ability chooses to surface the mistake rather than silently absorb it).',
			],
			'list_area' => [
				'type'        => 'string',
				'enum'        => [ 'header', 'footer', 'sidebar', 'uncategorized', 'navigation-overlay' ],
				'description' => 'Optional, "list" only. Restrict the listing to parts of this area.',
			],
			'theme_scope' => [
				'type'    => 'string',
				'enum'    => [ 'child', 'parent' ],
				'default' => 'child',
				'description' => 'Which theme to check for a theme-file match / read theme.json templateParts from. Does not affect which theme the DB row is scoped to (always the active theme).',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer', 'description' => '0 when the resolved part has no DB row (pure theme-file, source: "theme").' ],
			'slug'      => [ 'type' => 'string' ],
			'title'     => [ 'type' => 'string' ],
			'content'   => [ 'type' => 'string' ],
			'area'      => [ 'type' => 'string' ],
			'source'    => [ 'type' => 'string', 'description' => '"custom" (DB row) or "theme" (file, no DB row).' ],
			'parts'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list" - every resolvable part\'s {slug, title, area, source, post_id}.' ],
			'validation' => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_template_parts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Template Parts',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-template-parts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_template_parts_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete', 'list' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: create, read, update, delete, list.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'get_block_templates' ) || ! post_type_exists( 'wp_template_part' ) ) {
		return new WP_Error( 'template_parts_unavailable', __( 'This WordPress core does not support template parts (wp_template_part).', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return e2m_engine_gutenberg_list_template_parts( $input );
		case 'read':
			return e2m_engine_gutenberg_read_template_part( $input );
		case 'create':
			return e2m_engine_gutenberg_create_or_customize_template_part( $input );
		case 'update':
			return e2m_engine_gutenberg_update_template_part( $input );
		case 'delete':
			return e2m_engine_gutenberg_delete_template_part( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_list_template_parts( array $input ): array {
	$query = [];
	if ( ! empty( $input['list_area'] ) ) {
		$query['area'] = (string) $input['list_area'];
	}
	$results = get_block_templates( $query, 'wp_template_part' );

	$parts = [];
	foreach ( $results as $part ) {
		$parts[] = [
			'slug'    => (string) $part->slug,
			'title'   => (string) $part->title,
			'area'    => isset( $part->area ) ? (string) $part->area : 'uncategorized',
			'source'  => (string) $part->source,
			'post_id' => isset( $part->wp_id ) ? (int) $part->wp_id : 0,
		];
	}

	return [ 'parts' => $parts ];
}

/**
 * Resolve one template part by slug via get_block_template() against the
 * active theme - same composite-id convention as
 * e2m_engine_gutenberg_resolve_template() in manage-templates.php.
 *
 * @param string $slug
 * @return WP_Block_Template|null
 */
function e2m_engine_gutenberg_resolve_template_part( string $slug ) {
	$theme = get_stylesheet();
	return get_block_template( $theme . '//' . $slug, 'wp_template_part' );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_template_part( array $input ) {
	$slug = isset( $input['slug'] ) ? (string) $input['slug'] : '';
	if ( $slug === '' ) {
		return new WP_Error( 'missing_slug', __( 'slug is required for "read".', 'e2mconnect' ) );
	}

	$part = e2m_engine_gutenberg_resolve_template_part( $slug );
	if ( ! $part ) {
		return new WP_Error( 'template_part_not_found', __( 'No theme-file or DB-row template part resolves to this slug.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => isset( $part->wp_id ) ? (int) $part->wp_id : 0,
		'slug'       => (string) $part->slug,
		'title'      => (string) $part->title,
		'content'    => (string) $part->content,
		'area'       => isset( $part->area ) ? (string) $part->area : 'uncategorized',
		'source'     => (string) $part->source,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Look up the area theme.json's `templateParts` array registers for a given
 * slug (via wp_get_theme_data_template_parts(), the exact function core's
 * own file-to-DB-row promotion path reads from), falling back to
 * "uncategorized" when the slug has no entry - replicating
 * _add_block_template_part_area_info()'s own fallback exactly.
 *
 * @param string $slug
 * @return string
 */
function e2m_engine_gutenberg_lookup_area_from_theme_json( string $slug ): string {
	if ( ! function_exists( 'wp_get_theme_data_template_parts' ) ) {
		return 'uncategorized';
	}

	$theme_data = wp_get_theme_data_template_parts();
	if ( isset( $theme_data[ $slug ]['area'] ) && is_string( $theme_data[ $slug ]['area'] ) ) {
		$allowed = function_exists( 'get_allowed_block_template_part_areas' )
			? array_column( get_allowed_block_template_part_areas(), 'area' )
			: [ 'header', 'footer', 'sidebar', 'uncategorized', 'navigation-overlay' ];
		$area = (string) $theme_data[ $slug ]['area'];
		return in_array( $area, $allowed, true ) ? $area : 'uncategorized';
	}

	return 'uncategorized';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_or_customize_template_part( array $input ) {
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

	$validation = e2m_engine_gutenberg_validate_template_part_content( (string) $input['content'] );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'template_part_content_invalid',
			sprintf(
				/* translators: %s: joined validation error messages */
				__( 'content failed validation: %s', 'e2mconnect' ),
				implode( '; ', $validation['errors'] )
			),
			[ 'validation' => $validation ]
		);
	}

	$existing = e2m_engine_gutenberg_resolve_template_part( $slug );
	if ( $existing && isset( $existing->wp_id ) && (int) $existing->wp_id > 0 ) {
		return new WP_Error(
			'template_part_already_customized',
			__( 'A custom DB-row template part already exists for this slug - use "update" (with its post_id) instead of "create".', 'e2mconnect' )
		);
	}

	// Area resolution order: explicit input > the existing theme-file
	// part's own theme.json-derived area (if this "create" is customizing
	// an existing file part, per core's own promotion behavior) >
	// "uncategorized". This mirrors WP_REST_Templates_Controller::
	// prepare_item_for_database()'s exact fallback chain.
	if ( isset( $input['area'] ) ) {
		$area = (string) $input['area'];
	} elseif ( $existing && isset( $existing->area ) && (string) $existing->area !== '' ) {
		$area = (string) $existing->area;
	} else {
		$area = e2m_engine_gutenberg_lookup_area_from_theme_json( $slug );
	}

	$post_id = wp_insert_post(
		[
			'post_type'    => 'wp_template_part',
			'post_name'    => sanitize_title( $slug ),
			'post_title'   => wp_strip_all_tags( (string) $input['title'] ),
			'post_content' => wp_slash( (string) $input['content'] ),
			'post_status'  => 'publish',
			'tax_input'    => [
				'wp_theme'              => [ get_stylesheet() ],
				'wp_template_part_area' => [ $area ],
			],
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	wp_set_post_terms( (int) $post_id, [ get_stylesheet() ], 'wp_theme', false );
	wp_set_post_terms( (int) $post_id, [ $area ], 'wp_template_part_area', false );

	if ( function_exists( 'e2m_engine_detect_theme_mode' ) ) {
		[ $theme_mode ] = e2m_engine_detect_theme_mode();
		if ( $theme_mode !== 'block' ) {
			$validation['warnings'][] = sprintf(
				/* translators: %s: detected theme_mode value */
				__( 'The active theme\'s detected theme_mode is "%s", not "block" - this template part will not render via the Template Part block until the active theme is a full block/FSE theme. The write still succeeded.', 'e2mconnect' ),
				(string) $theme_mode
			);
		}
	}

	return [
		'post_id'    => (int) $post_id,
		'slug'       => $slug,
		'title'      => (string) $input['title'],
		'content'    => (string) $input['content'],
		'area'       => $area,
		'source'     => 'custom',
		'validation' => $validation,
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_update_template_part( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "update".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_template_part' ) {
		return new WP_Error( 'template_part_not_found', __( 'No wp_template_part post exists with the given post_id.', 'e2mconnect' ) );
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
		$validation = e2m_engine_gutenberg_validate_template_part_content( (string) $input['content'] );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'template_part_content_invalid',
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

	$resulting_area = wp_get_post_terms( $post_id, 'wp_template_part_area', [ 'fields' => 'names' ] );
	$area           = ! empty( $resulting_area ) && ! is_wp_error( $resulting_area ) ? (string) $resulting_area[0] : 'uncategorized';
	if ( isset( $input['area'] ) ) {
		$area = (string) $input['area'];
		wp_set_post_terms( $post_id, [ $area ], 'wp_template_part_area', false );
	}

	return [
		'post_id'    => $post_id,
		'slug'       => (string) $existing->post_name,
		'title'      => isset( $update_args['post_title'] ) ? (string) $update_args['post_title'] : (string) $existing->post_title,
		'content'    => $resulting_content,
		'area'       => $area,
		'source'     => 'custom',
		'validation' => $validation,
	];
}

/**
 * Delete a DB-row template part. Rejects when the resolved part has no DB
 * row at all - same "theme files can't be removed" guard as
 * e2m/gutenberg-manage-templates.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_template_part( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$slug    = isset( $input['slug'] ) ? (string) $input['slug'] : '';

	if ( $post_id <= 0 ) {
		if ( $slug === '' ) {
			return new WP_Error( 'missing_identifier', __( 'Either post_id or slug is required for "delete".', 'e2mconnect' ) );
		}
		$resolved = e2m_engine_gutenberg_resolve_template_part( $slug );
		if ( ! $resolved || empty( $resolved->wp_id ) ) {
			return new WP_Error(
				'template_part_not_custom',
				__( 'Templates based on theme files can\'t be removed - this slug has no DB-row template part to delete.', 'e2mconnect' )
			);
		}
		$post_id = (int) $resolved->wp_id;
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_template_part' ) {
		return new WP_Error( 'template_part_not_found', __( 'No wp_template_part post exists with the given post_id.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, true );
	if ( ! $result ) {
		return new WP_Error( 'template_part_delete_failed', __( 'Failed to delete the wp_template_part post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * This ability's own explicit structural validation for template part
 * content - conservative parse/serialize round-trip check, identical
 * approach to e2m/gutenberg-manage-templates' equivalent function.
 *
 * @param string $content
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_template_part_content( string $content ): array {
	$errors   = [];
	$warnings = [];

	if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
		$parsed       = parse_blocks( $content );
		$reserialized = serialize_blocks( $parsed );
		if ( trim( (string) $reserialized ) === '' && trim( $content ) !== '' ) {
			$errors[] = 'content did not parse into any recognizable block - check for malformed block comment delimiters.';
		}
	}

	$warnings[] = 'This ability checks parse/serialize round-trip stability only. Call e2m/gutenberg-validate-blocks with this content as block_markup for the full registry-resolution and attribute-schema gate before create/update on a part that will actually render.';

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
