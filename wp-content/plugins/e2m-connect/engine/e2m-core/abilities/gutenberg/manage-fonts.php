<?php
/**
 * E2M Connect MCP - Gutenberg Manage Fonts
 *
 * CRUD on the Font Library's two custom post types - `wp_font_family`
 * (the family record: name/slug/fontFamily CSS value) and `wp_font_face`
 * (one weight/style variant per family, `post_parent` = the family's post
 * ID, carrying the actual font-file reference). Gives a caller admin-UI
 * parity with WordPress's own Site Editor "Manage Fonts" screen - proper
 * `wp-content/uploads/fonts/` storage (confirmed NOT the Media Library -
 * font files never get a `wp_insert_attachment()` row) and correct cascade
 * cleanup of font files when a face/family is deleted.
 *
 * Confirmed both CPTs map EVERY relevant capability to `edit_theme_options`
 * (wp-includes/post.php's create_initial_post_types(), same registration
 * function that registers wp_navigation), `map_meta_cap => true`, no
 * `capability_type` key. Following e2m/gutenberg-manage-navigation's own
 * precedent exactly: no explicit current_user_can() check in this file -
 * wp_insert_post()/wp_update_post()/wp_delete_post() already reject a
 * caller lacking `edit_theme_options` via WordPress's own capability
 * mapping, and duplicating that check here would only risk drifting from
 * what core itself enforces.
 *
 * DELIBERATELY DOES NOT write theme.json's `settings.typography.
 * fontFamilies` as a side effect of registering a font here. Confirmed
 * directly against WP_Font_Face_Resolver::get_fonts_from_theme_json() (the
 * function that drives the actual frontend @font-face CSS via
 * wp_print_font_faces()): it reads EXCLUSIVELY from
 * wp_get_global_settings()['typography']['fontFamilies'] and never queries
 * these two CPTs at all. The real WordPress core has NO PHP-level bridge
 * from "registered in the Font Library" to "usable via theme.json" - that
 * connection is a JavaScript-only, editor-side, user-triggered write (the
 * Site Editor's Font Library UI itself constructs the theme.json entry
 * when a human clicks "Activate"). Since `gutenberg-foundation-builder`'s
 * own Step 2.3 already owns writing theme.json's fontFamilies array
 * (currently a direct-write stopgap explicitly framed as "until S5-G13
 * lands"), THIS ability staying narrowly scoped to CPT CRUD + file storage
 * avoids two abilities racing to write the same theme.json array. A caller
 * that registers a font here must separately dispatch
 * e2m/gutenberg-manage-theme-json (or let gutenberg-foundation-builder do
 * it) to actually make the font render - this is a real two-step process,
 * not an oversight.
 *
 * File upload uses core's own wp_handle_upload() with the `upload_dir`
 * filter swapped to `_wp_filter_font_directory` (via wp_font_dir()) -
 * confirmed this redirects storage to a flat `wp-content/uploads/fonts/`
 * directory (no year/month subdirs, unlike the Media Library), and that
 * the MCP bridge's base64-JSON transport (matching e2m/upload-media's own
 * established convention, since the bridge is JSON-RPC-shaped, not
 * multipart/form-data like core's own REST font-face endpoint) needs its
 * own upload path here rather than reusing wp_handle_upload() directly
 * (which expects a $_FILES-shaped array) - this file writes bytes via
 * WP_Filesystem/file_put_contents into wp_font_dir()'s path, exactly
 * mirroring e2m/upload-media.php's own base64-to-disk mechanism, adapted to
 * the font directory and the font MIME allowlist
 * (WP_Font_Utils::get_allowed_font_mime_types() - otf/ttf/woff/woff2 only).
 * The uploaded file's relative path is stored as post meta
 * (`_wp_font_face_file`, repeatable) on the font_face post, matching core's
 * own storage convention exactly so core's OWN cascade-delete cleanup
 * (_wp_before_delete_font_face() in wp-includes/fonts.php) removes the file
 * correctly when this ability later deletes the post via the ordinary
 * wp_delete_post() - no bespoke cleanup code needed here.
 *
 * No REST batching and no trashing exist on core's own Font Library
 * controllers ($allow_batch = false, delete requires force=true) - this
 * ability mirrors that: delete is always a permanent wp_delete_post(
 * $id, true ), matching e2m/gutenberg-manage-navigation's own delete
 * semantics for the same reason.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-fonts', [
	'label'       => __( '[Gutenberg] Manage Fonts', 'e2mconnect' ),
	'description' => __( 'CRUD on the Font Library (wp_font_family + wp_font_face). Font files are stored in wp-content/uploads/fonts/, NOT the Media Library. Deliberately does not write theme.json\'s fontFamilies array - that stays gutenberg-foundation-builder\'s job; registering a font here is a separate step from making it render.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'          => [
				'type'        => 'string',
				'enum'        => [ 'create_family', 'read_family', 'update_family', 'delete_family', 'list_families', 'create_face', 'read_face', 'delete_face', 'list_faces' ],
				'description' => '"create_family" inserts a new wp_font_family. "read_family"/"update_family"/"delete_family" act on one by post_id (delete cascades - deletes every child font-face and its uploaded files, matching core\'s own cascade). "list_families" returns every registered family. "create_face" inserts a new wp_font_face under a family (font_family_id required). "read_face"/"delete_face" act on one face by post_id. "list_faces" returns every face under a family (font_family_id required). Font faces have no "update" action - core itself has none either (a face is small enough to delete + recreate); this ability follows the same convention.',
			],
			'post_id'         => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The font_family or font_face post_id, depending on action. Required for read_family/update_family/delete_family/read_face/delete_face. Passing it back from a prior create/read/list lets the safety gatekeeper\'s automatic snapshot engage.' ],
			'font_family_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Required for create_face/list_faces - the parent wp_font_family post_id a face belongs to.' ],
			'name'            => [ 'type' => 'string', 'description' => 'Required for create_family. Human-readable family name (e.g. "Inter"). Optional for update_family.' ],
			'slug'            => [ 'type' => 'string', 'description' => 'Required for create_family. Immutable after create (matches core\'s own restriction - core rejects a slug change on update).' ],
			'font_family_css' => [ 'type' => 'string', 'description' => 'Required for create_family. The CSS font-family value (e.g. "Inter" or "Inter, sans-serif") - sanitized the same way core\'s WP_Font_Utils::sanitize_font_family() does (quoting for multi-word/special-char names). Optional for update_family.' ],
			'font_style'      => [ 'type' => 'string', 'default' => 'normal', 'description' => 'create_face only. e.g. "normal", "italic".' ],
			'font_weight'     => [ 'type' => 'string', 'default' => '400', 'description' => 'create_face only. e.g. "400", "700", "100 900" (variable font range).' ],
			'font_display'    => [ 'type' => 'string', 'default' => 'fallback', 'description' => 'create_face only. CSS font-display value.' ],
			'src_url'         => [ 'type' => 'string', 'description' => 'create_face only. A remote/already-hosted font file URL. Mutually exclusive with filename+data (upload a new file). Use this for a Google Fonts URL the caller does not want mirrored locally.' ],
			'filename'        => [ 'type' => 'string', 'description' => 'create_face only. Desired filename with extension (e.g. "inter-regular.woff2") for a NEW file upload. Mutually exclusive with src_url. Requires data.' ],
			'data'            => [ 'type' => 'string', 'description' => 'create_face only. Base64-encoded font file bytes, paired with filename. Only .otf/.ttf/.woff/.woff2 are accepted (WP_Font_Utils::get_allowed_font_mime_types()).' ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'          => [ 'type' => 'integer' ],
			'name'             => [ 'type' => 'string' ],
			'slug'             => [ 'type' => 'string' ],
			'font_family_css'  => [ 'type' => 'string' ],
			'font_family_id'   => [ 'type' => 'integer', 'description' => 'Present on font-face actions - the parent family\'s post_id.' ],
			'font_style'       => [ 'type' => 'string' ],
			'font_weight'      => [ 'type' => 'string' ],
			'src'              => [ 'type' => 'string', 'description' => 'The resolved font file URL (uploaded-file URL under wp-content/uploads/fonts/, or the given src_url verbatim).' ],
			'families'         => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list_families" - every family\'s {post_id, name, slug, font_family_css}.' ],
			'faces'            => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list_faces" - every face\'s {post_id, font_style, font_weight, src}.' ],
			'validation'       => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_fonts_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Fonts',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-fonts ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_fonts_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	$valid_actions = [ 'create_family', 'read_family', 'update_family', 'delete_family', 'list_families', 'create_face', 'read_face', 'delete_face', 'list_faces' ];
	if ( ! in_array( $action, $valid_actions, true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: create_family, read_family, update_family, delete_family, list_families, create_face, read_face, delete_face, list_faces.', 'e2mconnect' ) );
	}

	if ( ! post_type_exists( 'wp_font_family' ) || ! post_type_exists( 'wp_font_face' ) ) {
		return new WP_Error( 'font_library_unavailable', __( 'This WordPress core does not support the Font Library (wp_font_family/wp_font_face).', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list_families':
			return e2m_engine_gutenberg_list_font_families();
		case 'read_family':
			return e2m_engine_gutenberg_read_font_family( $input );
		case 'create_family':
			return e2m_engine_gutenberg_create_font_family( $input );
		case 'update_family':
			return e2m_engine_gutenberg_update_font_family( $input );
		case 'delete_family':
			return e2m_engine_gutenberg_delete_font_family( $input );
		case 'list_faces':
			return e2m_engine_gutenberg_list_font_faces( $input );
		case 'read_face':
			return e2m_engine_gutenberg_read_font_face( $input );
		case 'create_face':
			return e2m_engine_gutenberg_create_font_face( $input );
		case 'delete_face':
			return e2m_engine_gutenberg_delete_font_face( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * Decode a wp_font_family post's settings JSON (stored in post_content, per
 * WP_REST_Font_Families_Controller's own get_settings_from_post()).
 *
 * @param WP_Post $post
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_decode_font_family_settings( WP_Post $post ): array {
	$decoded = json_decode( (string) $post->post_content, true );
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_list_font_families(): array {
	$posts = get_posts( [
		'post_type'      => 'wp_font_family',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$families = [];
	foreach ( $posts as $post ) {
		$settings = e2m_engine_gutenberg_decode_font_family_settings( $post );
		$families[] = [
			'post_id'         => (int) $post->ID,
			'name'            => (string) $post->post_title,
			'slug'            => (string) $post->post_name,
			'font_family_css' => isset( $settings['fontFamily'] ) ? (string) $settings['fontFamily'] : '',
		];
	}

	return [ 'families' => $families ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_font_family( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "read_family".', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'wp_font_family' ) {
		return new WP_Error( 'font_family_not_found', __( 'No wp_font_family post exists with the given post_id.', 'e2mconnect' ) );
	}

	$settings = e2m_engine_gutenberg_decode_font_family_settings( $post );

	return [
		'post_id'         => (int) $post->ID,
		'name'            => (string) $post->post_title,
		'slug'            => (string) $post->post_name,
		'font_family_css' => isset( $settings['fontFamily'] ) ? (string) $settings['fontFamily'] : '',
		'validation'      => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Sanitize a CSS font-family value the same way core's own
 * WP_Font_Utils::sanitize_font_family() does when available - falls back to
 * a conservative local sanitizer (quote a multi-word name that isn't
 * already quoted) if that class is unavailable on an older core.
 *
 * @param string $font_family_css
 * @return string
 */
function e2m_engine_gutenberg_sanitize_font_family_css( string $font_family_css ): string {
	if ( class_exists( 'WP_Font_Utils' ) && method_exists( 'WP_Font_Utils', 'sanitize_font_family' ) ) {
		return (string) WP_Font_Utils::sanitize_font_family( $font_family_css );
	}
	$trimmed = trim( $font_family_css );
	if ( $trimmed !== '' && str_contains( $trimmed, ' ' ) && ! str_starts_with( $trimmed, '"' ) && ! str_starts_with( $trimmed, "'" ) ) {
		$first = explode( ',', $trimmed )[0];
		return '"' . trim( $first, '"\'' ) . '"' . substr( $trimmed, strlen( $first ) );
	}
	return $trimmed;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_font_family( array $input ) {
	if ( empty( $input['name'] ) || ! is_string( $input['name'] ) ) {
		return new WP_Error( 'missing_name', __( 'name is required and must be a non-empty string for "create_family".', 'e2mconnect' ) );
	}
	if ( empty( $input['slug'] ) || ! is_string( $input['slug'] ) ) {
		return new WP_Error( 'missing_slug', __( 'slug is required and must be a non-empty string for "create_family".', 'e2mconnect' ) );
	}
	if ( empty( $input['font_family_css'] ) || ! is_string( $input['font_family_css'] ) ) {
		return new WP_Error( 'missing_font_family_css', __( 'font_family_css is required and must be a non-empty string for "create_family".', 'e2mconnect' ) );
	}

	$slug = sanitize_title( (string) $input['slug'] );

	$existing = get_page_by_path( $slug, OBJECT, 'wp_font_family' );
	if ( $existing ) {
		return new WP_Error( 'font_family_already_exists', __( 'A wp_font_family post already exists with this slug - use "update_family" instead of "create_family".', 'e2mconnect' ) );
	}

	$settings = [ 'fontFamily' => e2m_engine_gutenberg_sanitize_font_family_css( (string) $input['font_family_css'] ) ];

	$post_id = wp_insert_post(
		[
			'post_type'    => 'wp_font_family',
			'post_title'   => wp_strip_all_tags( (string) $input['name'] ),
			'post_name'    => $slug,
			'post_content' => wp_slash( (string) wp_json_encode( $settings ) ),
			'post_status'  => 'publish',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return [
		'post_id'         => (int) $post_id,
		'name'            => (string) $input['name'],
		'slug'            => $slug,
		'font_family_css' => $settings['fontFamily'],
		'validation'      => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_update_font_family( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "update_family".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_font_family' ) {
		return new WP_Error( 'font_family_not_found', __( 'No wp_font_family post exists with the given post_id.', 'e2mconnect' ) );
	}

	if ( isset( $input['slug'] ) ) {
		// Matches core's own restriction - WP_REST_Font_Families_Controller
		// explicitly rejects a slug change on update.
		return new WP_Error( 'slug_immutable', __( 'slug cannot be changed after a font family is created - matches WordPress core\'s own restriction.', 'e2mconnect' ) );
	}

	$update_args = [ 'ID' => $post_id ];
	if ( isset( $input['name'] ) ) {
		if ( ! is_string( $input['name'] ) || $input['name'] === '' ) {
			return new WP_Error( 'invalid_name', __( 'name must be a non-empty string.', 'e2mconnect' ) );
		}
		$update_args['post_title'] = wp_strip_all_tags( (string) $input['name'] );
	}

	$settings = e2m_engine_gutenberg_decode_font_family_settings( $existing );
	if ( isset( $input['font_family_css'] ) ) {
		if ( ! is_string( $input['font_family_css'] ) || $input['font_family_css'] === '' ) {
			return new WP_Error( 'invalid_font_family_css', __( 'font_family_css must be a non-empty string.', 'e2mconnect' ) );
		}
		$settings['fontFamily']      = e2m_engine_gutenberg_sanitize_font_family_css( (string) $input['font_family_css'] );
		$update_args['post_content'] = wp_slash( (string) wp_json_encode( $settings ) );
	}

	if ( count( $update_args ) > 1 ) {
		$updated = wp_update_post( $update_args, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	return [
		'post_id'         => $post_id,
		'name'            => isset( $update_args['post_title'] ) ? (string) $update_args['post_title'] : (string) $existing->post_title,
		'slug'            => (string) $existing->post_name,
		'font_family_css' => isset( $settings['fontFamily'] ) ? (string) $settings['fontFamily'] : '',
		'validation'      => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Delete a font family, cascading to every child font-face (and their
 * uploaded files) exactly like core's own _wp_after_delete_font_family()
 * (wp-includes/fonts.php) does for a normal Site-Editor-driven delete -
 * this ability reuses the SAME core function rather than re-implementing
 * the cascade, so the file-cleanup behavior can never drift from core's own.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_font_family( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "delete_family".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_font_family' ) {
		return new WP_Error( 'font_family_not_found', __( 'No wp_font_family post exists with the given post_id.', 'e2mconnect' ) );
	}

	// wp_delete_post() itself fires the 'deleted_post'/'delete_post' hooks
	// that core's own _wp_after_delete_font_family()/_wp_before_delete_font_face()
	// are attached to (wp-includes/fonts.php) - the cascade to child faces
	// and their uploaded files happens automatically as a side effect of
	// this single call, exactly as it would from the Site Editor's own
	// delete action. No bespoke cascade code needed here.
	$result = wp_delete_post( $post_id, true );
	if ( ! $result ) {
		return new WP_Error( 'font_family_delete_failed', __( 'Failed to delete the wp_font_family post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_list_font_faces( array $input ) {
	$font_family_id = isset( $input['font_family_id'] ) ? (int) $input['font_family_id'] : 0;
	if ( $font_family_id <= 0 ) {
		return new WP_Error( 'missing_font_family_id', __( 'font_family_id is required for "list_faces".', 'e2mconnect' ) );
	}

	$family = get_post( $font_family_id );
	if ( ! $family || $family->post_type !== 'wp_font_family' ) {
		return new WP_Error( 'font_family_not_found', __( 'No wp_font_family post exists with the given font_family_id.', 'e2mconnect' ) );
	}

	$posts = get_posts( [
		'post_type'      => 'wp_font_face',
		'post_parent'    => $font_family_id,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	] );

	$faces = [];
	foreach ( $posts as $post ) {
		$settings = json_decode( (string) $post->post_content, true );
		$settings = is_array( $settings ) ? $settings : [];
		$faces[] = [
			'post_id'     => (int) $post->ID,
			'font_style'  => isset( $settings['fontStyle'] ) ? (string) $settings['fontStyle'] : 'normal',
			'font_weight' => isset( $settings['fontWeight'] ) ? (string) $settings['fontWeight'] : '400',
			'src'         => isset( $settings['src'] ) ? ( is_array( $settings['src'] ) ? (string) ( $settings['src'][0] ?? '' ) : (string) $settings['src'] ) : '',
		];
	}

	return [ 'faces' => $faces ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_font_face( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "read_face".', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'wp_font_face' ) {
		return new WP_Error( 'font_face_not_found', __( 'No wp_font_face post exists with the given post_id.', 'e2mconnect' ) );
	}

	$settings = json_decode( (string) $post->post_content, true );
	$settings = is_array( $settings ) ? $settings : [];

	return [
		'post_id'        => (int) $post->ID,
		'font_family_id' => (int) $post->post_parent,
		'font_style'     => isset( $settings['fontStyle'] ) ? (string) $settings['fontStyle'] : 'normal',
		'font_weight'    => isset( $settings['fontWeight'] ) ? (string) $settings['fontWeight'] : '400',
		'src'            => isset( $settings['src'] ) ? ( is_array( $settings['src'] ) ? (string) ( $settings['src'][0] ?? '' ) : (string) $settings['src'] ) : '',
		'validation'     => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Create a font face under a family. Handles both a plain remote src_url
 * and a NEW file upload (filename+data, base64) - the upload path writes
 * bytes into wp_font_dir()'s path (wp-content/uploads/fonts/, confirmed
 * distinct from the Media Library) via the same base64-to-disk mechanism
 * e2m/upload-media.php already establishes, adapted to the font directory
 * and the font-specific MIME allowlist.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_font_face( array $input ) {
	$font_family_id = isset( $input['font_family_id'] ) ? (int) $input['font_family_id'] : 0;
	if ( $font_family_id <= 0 ) {
		return new WP_Error( 'missing_font_family_id', __( 'font_family_id is required for "create_face".', 'e2mconnect' ) );
	}
	$family = get_post( $font_family_id );
	if ( ! $family || $family->post_type !== 'wp_font_family' ) {
		return new WP_Error( 'font_family_not_found', __( 'No wp_font_family post exists with the given font_family_id.', 'e2mconnect' ) );
	}
	$family_settings = e2m_engine_gutenberg_decode_font_family_settings( $family );
	if ( empty( $family_settings['fontFamily'] ) ) {
		return new WP_Error( 'font_family_missing_css_value', __( 'The parent font family has no fontFamily CSS value - it may be corrupted.', 'e2mconnect' ) );
	}

	$has_src_url = isset( $input['src_url'] ) && (string) $input['src_url'] !== '';
	$has_upload  = isset( $input['filename'] ) && (string) $input['filename'] !== '' && isset( $input['data'] ) && (string) $input['data'] !== '';
	if ( $has_src_url === $has_upload ) {
		return new WP_Error( 'invalid_input', __( 'Provide exactly one of src_url (a remote/existing URL) or filename+data (upload a new file), not both and not neither.', 'e2mconnect' ) );
	}

	$stored_meta_path = '';
	if ( $has_upload ) {
		$upload_result = e2m_engine_gutenberg_upload_font_file( (string) $input['filename'], (string) $input['data'] );
		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}
		$src               = $upload_result['url'];
		$stored_meta_path  = $upload_result['relative_path'];
	} else {
		$src = (string) $input['src_url'];
	}

	$settings = [
		'fontFamily'  => (string) $family_settings['fontFamily'],
		'fontStyle'   => isset( $input['font_style'] ) ? (string) $input['font_style'] : 'normal',
		'fontWeight'  => isset( $input['font_weight'] ) ? (string) $input['font_weight'] : '400',
		'fontDisplay' => isset( $input['font_display'] ) ? (string) $input['font_display'] : 'fallback',
		'src'         => $src,
	];

	// Matches core's own de-dup check (WP_REST_Font_Faces_Controller checks
	// for an existing face with the same generated slug before inserting).
	// WP_Font_Utils may be unavailable on an older core - degrade to
	// skipping the check rather than hard-failing on a missing class.
	$slug = class_exists( 'WP_Font_Utils' ) && method_exists( 'WP_Font_Utils', 'get_font_face_slug' )
		? (string) WP_Font_Utils::get_font_face_slug( $settings )
		: sanitize_title( $settings['fontFamily'] . '-' . $settings['fontStyle'] . '-' . $settings['fontWeight'] );

	$existing_face = get_posts( [
		'post_type'      => 'wp_font_face',
		'post_parent'    => $font_family_id,
		'title'          => $slug,
		'posts_per_page' => 1,
	] );
	if ( ! empty( $existing_face ) ) {
		return new WP_Error( 'font_face_already_exists', __( 'A font face with this family/style/weight combination already exists under this family.', 'e2mconnect' ) );
	}

	$post_id = wp_insert_post(
		[
			'post_type'    => 'wp_font_face',
			'post_parent'  => $font_family_id,
			'post_title'   => $slug,
			'post_content' => wp_slash( (string) wp_json_encode( $settings ) ),
			'post_status'  => 'publish',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Stamp the SAME meta key core's own upload flow uses
	// (_wp_font_face_file) so core's own cascade-delete cleanup
	// (_wp_before_delete_font_face() in wp-includes/fonts.php) removes this
	// file correctly when the face or its parent family is later deleted -
	// no bespoke cleanup code needed on this ability's own delete paths.
	if ( $stored_meta_path !== '' ) {
		add_post_meta( (int) $post_id, '_wp_font_face_file', $stored_meta_path );
	}

	return [
		'post_id'        => (int) $post_id,
		'font_family_id' => $font_family_id,
		'font_style'     => $settings['fontStyle'],
		'font_weight'    => $settings['fontWeight'],
		'src'            => $src,
		'validation'     => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Delete a font face. wp_delete_post() fires the hooks core's own
 * _wp_before_delete_font_face() (wp-includes/fonts.php) is attached to,
 * which reads back every `_wp_font_face_file` meta value and deletes the
 * corresponding file from wp_get_font_dir() - the same automatic cleanup
 * e2m_engine_gutenberg_delete_font_family() relies on for the cascade case.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_font_face( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "delete_face".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_font_face' ) {
		return new WP_Error( 'font_face_not_found', __( 'No wp_font_face post exists with the given post_id.', 'e2mconnect' ) );
	}

	$result = wp_delete_post( $post_id, true );
	if ( ! $result ) {
		return new WP_Error( 'font_face_delete_failed', __( 'Failed to delete the wp_font_face post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Upload a base64-encoded font file into wp-content/uploads/fonts/ - the
 * SAME base64-to-disk mechanism e2m/upload-media.php already establishes
 * for the Media Library, adapted to: (1) the font-specific MIME allowlist
 * (WP_Font_Utils::get_allowed_font_mime_types() - otf/ttf/woff/woff2 only,
 * confirmed core has no font-specific byte-size cap beyond the ordinary
 * PHP upload_max_filesize/post_max_size ini limits), and (2) the font
 * directory via wp_font_dir() (which applies the `upload_dir` filter swap
 * to `_wp_filter_font_directory` internally) instead of wp_upload_dir()'s
 * ordinary year/month Media Library path.
 *
 * @param string $filename
 * @param string $base64_data
 * @return array{url: string, relative_path: string}|WP_Error
 */
function e2m_engine_gutenberg_upload_font_file( string $filename, string $base64_data ) {
	$filename = sanitize_file_name( $filename );
	if ( $filename === '' ) {
		return new WP_Error( 'invalid_filename', __( 'filename is required and must produce a safe filename.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MCP payloads are explicit base64 envelopes.
	$binary = base64_decode( $base64_data, true );
	if ( $binary === false ) {
		return new WP_Error( 'invalid_base64', __( 'data is not valid base64.', 'e2mconnect' ) );
	}

	$allowed_mimes = class_exists( 'WP_Font_Utils' ) && method_exists( 'WP_Font_Utils', 'get_allowed_font_mime_types' )
		? WP_Font_Utils::get_allowed_font_mime_types()
		: [ 'otf' => 'application/vnd.ms-opentype', 'ttf' => 'font/sfnt', 'woff' => 'font/woff', 'woff2' => 'font/woff2' ];

	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	if ( ! isset( $allowed_mimes[ $extension ] ) ) {
		return new WP_Error(
			'invalid_font_file_type',
			sprintf(
				/* translators: %1$s: rejected extension, %2$s: comma-joined allowed extensions */
				__( 'The file extension "%1$s" is not an allowed font type. Allowed: %2$s.', 'e2mconnect' ),
				$extension,
				implode( ', ', array_keys( $allowed_mimes ) )
			)
		);
	}

	if ( ! function_exists( 'wp_font_dir' ) ) {
		return new WP_Error( 'font_dir_unavailable', __( 'wp_font_dir() is unavailable on this WordPress core.', 'e2mconnect' ) );
	}
	$font_dir = wp_font_dir();
	if ( ! empty( $font_dir['error'] ) ) {
		return new WP_Error( 'font_dir_error', (string) $font_dir['error'] );
	}

	$target_path = trailingslashit( (string) $font_dir['path'] ) . wp_unique_filename( (string) $font_dir['path'], $filename );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write into the font uploads dir, same pattern as e2m/upload-media.
	$written = file_put_contents( $target_path, $binary );
	if ( $written === false ) {
		return new WP_Error( 'write_failed', __( 'Failed to write the font file to disk.', 'e2mconnect' ) );
	}

	$basename = basename( $target_path );
	// Core's own relative-path computation for the _wp_font_face_file meta
	// value lives in WP_REST_Font_Faces_Controller::relative_fonts_path() -
	// a PROTECTED method, not a global function (confirmed directly against
	// that class's source before assuming otherwise). Its logic is a
	// two-line str_starts_with/str_replace against wp_get_font_dir()
	// ['basedir'] - simple enough to reproduce locally rather than reach
	// into a protected method via Reflection, so this ability's stamped
	// meta stays byte-identical in shape to what core's own upload flow
	// would store without depending on an inaccessible internal.
	$relative_path = $basename;
	if ( str_starts_with( $target_path, (string) $font_dir['basedir'] ) ) {
		$relative_path = ltrim( str_replace( (string) $font_dir['basedir'], '', $target_path ), '/' );
	}

	return [
		'url'           => trailingslashit( (string) $font_dir['url'] ) . $basename,
		'relative_path' => $relative_path,
	];
}
