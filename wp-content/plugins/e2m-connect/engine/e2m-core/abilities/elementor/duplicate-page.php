<?php
/**
 * E2M Connect MCP - Elementor Duplicate Page
 *
 * Pixel-perfect clone of an existing Elementor page. Copies the full
 * `_elementor_data` tree plus every page-level meta key Elementor uses
 * (page settings, template type, edit mode, version, Pro version, page
 * template) so the new page renders identically to the source.
 *
 * Use case: landing-page factories where each new feature gets a fresh
 * page that starts as a 1:1 copy of an existing template, then has its
 * content swapped via update-element / update-page.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-duplicate-page', [
	'label'       => __( '[Elementor] Duplicate Page', 'e2mconnect' ),
	'description' => 'Creates a pixel-perfect clone of an existing Elementor page. Copies the full Elementor design tree plus page-level settings (custom CSS, colors, page template) so the new page renders identically to the source. After cloning, use update-element / update-page to change feature-specific content while preserving the design.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'source_post_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'The post/page ID whose Elementor design should be cloned.',
			],
			'new_title' => [
				'type'        => 'string',
				'minLength'   => 1,
				'description' => 'Title for the new page.',
			],
			'new_slug' => [
				'type'        => 'string',
				'description' => 'Optional slug for the new page. Defaults to a sanitized version of new_title.',
			],
			'status' => [
				'type'        => 'string',
				'enum'        => [ 'draft', 'publish', 'pending', 'private' ],
				'default'     => 'draft',
				'description' => 'Post status for the new page.',
			],
			'post_type' => [
				'type'        => 'string',
				'default'     => 'page',
				'description' => 'Post type of the new entry. Usually "page" for landing pages.',
			],
			'copy_settings' => [
				'type'        => 'boolean',
				'default'     => true,
				'description' => 'Copy page-level Elementor settings (custom CSS, color overrides, page layout). Set false to inherit fresh defaults.',
			],
			'copy_featured_image' => [
				'type'        => 'boolean',
				'default'     => true,
				'description' => 'Copy the source page\'s featured image to the new page.',
			],
			'copy_page_template' => [
				'type'        => 'boolean',
				'default'     => true,
				'description' => 'Copy the WordPress page template (Elementor Canvas, Full Width, etc.). Strongly recommended for design fidelity.',
			],
		],
		'required'             => [ 'source_post_id', 'new_title' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'new_post_id'  => [ 'type' => 'integer', 'description' => 'ID of the freshly created page.' ],
			'source_id'    => [ 'type' => 'integer', 'description' => 'ID that was cloned from.' ],
			'title'        => [ 'type' => 'string' ],
			'status'       => [ 'type' => 'string' ],
			'edit_url'     => [ 'type' => 'string', 'description' => 'WP-admin edit URL.' ],
			'elementor_edit_url' => [ 'type' => 'string', 'description' => 'Direct Elementor builder URL for the new page.' ],
			'permalink'    => [ 'type' => 'string' ],
			'meta_copied'  => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'List of meta keys that were copied from the source.',
			],
		],
		'required' => [ 'new_post_id', 'source_id', 'title', 'status', 'edit_url', 'permalink', 'meta_copied' ],
	],

	'execute_callback'    => 'e2m_engine_elementor_duplicate_page_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		// Tier 'essential' so the ability is visible in the Minimal profile too —
		// landing-page cloning is a daily operation for users on this workflow.
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Elementor: Duplicate Page',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-duplicate-page ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_duplicate_page_ability( array $input ) {
	// Elementor must be active.
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	// -- Input parsing ------------------------------------------------------
	$source_id            = isset( $input['source_post_id'] ) ? (int) $input['source_post_id'] : 0;
	$new_title            = isset( $input['new_title'] ) ? trim( sanitize_text_field( (string) $input['new_title'] ) ) : '';
	$new_slug             = isset( $input['new_slug'] ) ? sanitize_title( (string) $input['new_slug'] ) : '';
	$status               = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
	$post_type            = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'page';
	$copy_settings        = ! isset( $input['copy_settings'] ) || (bool) $input['copy_settings'];
	$copy_featured_image  = ! isset( $input['copy_featured_image'] ) || (bool) $input['copy_featured_image'];
	$copy_page_template   = ! isset( $input['copy_page_template'] ) || (bool) $input['copy_page_template'];

	if ( $source_id <= 0 ) {
		return new WP_Error( 'invalid_source_id', __( 'A valid source_post_id is required.', 'e2mconnect' ) );
	}
	if ( $new_title === '' ) {
		return new WP_Error( 'invalid_title', __( 'new_title is required.', 'e2mconnect' ) );
	}

	$source_post = get_post( $source_id );
	if ( ! $source_post ) {
		return new WP_Error( 'source_not_found', __( 'Source page not found.', 'e2mconnect' ) );
	}

	// Confirm the source actually has Elementor content. If it doesn't, the
	// caller probably picked the wrong tool — fail loudly so they don't end
	// up with an empty Elementor wrapper around a classic post.
	$source_data_raw = get_post_meta( $source_id, '_elementor_data', true );
	if ( empty( $source_data_raw ) ) {
		return new WP_Error(
			'source_not_elementor',
			__( 'The source page does not have Elementor content. Use e2m/create-page or check the post ID.', 'e2mconnect' )
		);
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to create pages.', 'e2mconnect' ) );
	}

	if ( ! in_array( $status, [ 'draft', 'publish', 'pending', 'private' ], true ) ) {
		$status = 'draft';
	}

	// -- 1. Create the new post ---------------------------------------------
	$insert_args = [
		'post_type'    => $post_type,
		'post_title'   => $new_title,
		'post_status'  => $status,
		'post_author'  => get_current_user_id(),
		// We intentionally do NOT copy post_content. Elementor renders from
		// _elementor_data, and copying classic content would create a
		// duplicate render in some themes.
		'post_content' => '',
		'post_excerpt' => (string) $source_post->post_excerpt,
		'menu_order'   => (int) $source_post->menu_order,
	];

	if ( $new_slug !== '' ) {
		$insert_args['post_name'] = $new_slug;
	}

	$new_post_id = wp_insert_post( $insert_args, true );
	if ( is_wp_error( $new_post_id ) ) {
		return $new_post_id;
	}
	$new_post_id = (int) $new_post_id;

	// -- 2. Copy Elementor + page-level meta keys ---------------------------
	//
	// Order matters here. _elementor_data is the design tree. Without
	// _elementor_edit_mode = 'builder', Elementor refuses to render the
	// design even if _elementor_data is present. The other keys keep
	// version/template parity so Elementor doesn't flag the new page as
	// "outdated" or fall back to compatibility paths.

	$meta_copied = [];

	// _elementor_data — the design tree itself. Always copied.
	// IMPORTANT: get_post_meta(...true) auto-unslashes; we re-slash because
	// update_post_meta auto-unslashes again. Without the wp_slash() the
	// JSON gets corrupted on quotes.
	$elementor_data = $source_data_raw;
	if ( ! is_string( $elementor_data ) ) {
		$elementor_data = wp_json_encode( $elementor_data );
	}
	update_post_meta( $new_post_id, '_elementor_data', wp_slash( $elementor_data ) );
	$meta_copied[] = '_elementor_data';

	// Required companions — Elementor refuses to render without them.
	$required_keys = [
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_pro_version',
	];
	foreach ( $required_keys as $key ) {
		$val = get_post_meta( $source_id, $key, true );
		if ( $val !== '' && $val !== null && $val !== false ) {
			update_post_meta( $new_post_id, $key, $val );
			$meta_copied[] = $key;
		}
	}

	// Defensive: if _elementor_edit_mode wasn't set on source, force it on
	// the clone so Elementor still renders the design.
	if ( ! in_array( '_elementor_edit_mode', $meta_copied, true ) ) {
		update_post_meta( $new_post_id, '_elementor_edit_mode', 'builder' );
		$meta_copied[] = '_elementor_edit_mode';
	}

	// Page settings (custom CSS, color overrides, page layout). Optional.
	if ( $copy_settings ) {
		$page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
		if ( ! empty( $page_settings ) ) {
			update_post_meta( $new_post_id, '_elementor_page_settings', $page_settings );
			$meta_copied[] = '_elementor_page_settings';
		}
	}

	// WP page template (Elementor Canvas / Full Width / Default). This is
	// the single biggest cause of "duplicated page looks broken" — the
	// design assumes Canvas but the new page falls back to Default.
	if ( $copy_page_template ) {
		$page_template = get_post_meta( $source_id, '_wp_page_template', true );
		if ( ! empty( $page_template ) ) {
			update_post_meta( $new_post_id, '_wp_page_template', $page_template );
			$meta_copied[] = '_wp_page_template';
		}
	}

	// Featured image.
	if ( $copy_featured_image ) {
		$thumb_id = (int) get_post_thumbnail_id( $source_id );
		if ( $thumb_id > 0 ) {
			set_post_thumbnail( $new_post_id, $thumb_id );
			$meta_copied[] = '_thumbnail_id';
		}
	}

	// -- 3. Refresh Elementor's CSS cache for the new post ------------------
	//
	// Elementor caches per-post CSS in _elementor_css meta + a file on
	// disk. We deliberately do NOT copy the source's _elementor_css; we
	// trigger a fresh build instead so widget-id-keyed selectors line up
	// with the new post.
	if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
		try {
			$post_css = new \Elementor\Core\Files\CSS\Post( $new_post_id );
			$post_css->update();
		} catch ( \Throwable $e ) {
			// Non-fatal — Elementor will lazy-build on first front-end view.
			// We swallow so the duplicate still succeeds.
		}
	}

	// Clear Elementor's global cache so any cross-post CSS regenerates.
	if (
		isset( \Elementor\Plugin::$instance->files_manager )
		&& method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' )
	) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	// -- 4. Build response --------------------------------------------------
	$elementor_edit_url = add_query_arg(
		[
			'post'   => $new_post_id,
			'action' => 'elementor',
		],
		admin_url( 'post.php' )
	);

	return [
		'new_post_id'        => $new_post_id,
		'source_id'          => $source_id,
		'title'              => $new_title,
		'status'             => get_post_status( $new_post_id ) ?: $status,
		'edit_url'           => (string) get_edit_post_link( $new_post_id, 'raw' ),
		'elementor_edit_url' => (string) $elementor_edit_url,
		'permalink'          => (string) get_permalink( $new_post_id ),
		'meta_copied'        => $meta_copied,
	];
}
