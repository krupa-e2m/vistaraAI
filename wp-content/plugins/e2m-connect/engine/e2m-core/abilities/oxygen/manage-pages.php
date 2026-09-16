<?php
/**
 * E2M Connect MCP – Oxygen Manage Pages
 *
 * Read and write Oxygen Builder page content. Oxygen stores its component
 * tree as JSON in the post meta key `ct_builder_json` (Oxygen 3/4) or as
 * shortcodes in post_content (older Oxygen).
 *
 * Oxygen 6 (re-released as a separate product) stores content differently —
 * the ability detects version and handles both.
 *
 * Actions:
 *   read        — get the Oxygen component tree for a post
 *   write       — replace the full component tree for a post
 *   get_version — detect which Oxygen version is active (3/4 vs 6)
 *   list_pages  — list posts built with Oxygen Builder
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/oxygen-manage-pages', [
	'label'       => __( '[Oxygen] Manage Pages', 'e2mconnect' ),
	'description' => 'Read and write Oxygen Builder component trees on posts/pages. Detects Oxygen version automatically. Supports read, write, get_version, and list_pages.',
	'category'    => 'e2m-oxygen',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'get_version', 'list_pages' ],
				'description' => 'read — get component tree; write — set component tree; get_version — detect Oxygen version; list_pages — list Oxygen-built posts.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID. Required for read and write.',
			],
			'components' => [
				'type'        => 'array',
				'description' => 'Full component tree to write. Array of Oxygen component objects.',
				'items'       => [ 'type' => 'object' ],
			],
			'per_page' => [ 'type' => 'integer', 'description' => 'Max results for list_pages. Default: 20.', 'default' => 20 ],
			'post_type' => [ 'type' => 'string', 'description' => 'Filter list_pages by post type. Default: any.', 'default' => 'any' ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'post_id'    => [ 'type' => 'integer' ],
			'components' => [ 'type' => 'array' ],
			'version'    => [ 'type' => 'string' ],
			'storage'    => [ 'type' => 'string' ],
			'pages'      => [ 'type' => 'array' ],
			'total'      => [ 'type' => 'integer' ],
			'saved'      => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_oxygen_manage_pages',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Oxygen: Manage Pages',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute oxygen-manage-pages ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_oxygen_manage_pages( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_oxygen() ) {
		return new WP_Error( 'oxygen_missing', __( 'Oxygen Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Read ──────────────────────────────────────────────────────────────
		case 'read':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for read.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'e2mconnect' ) );
			}

			[ $components, $storage ] = e2m_engine_oxygen_read_components( $post_id );

			return [
				'action'     => 'read',
				'post_id'    => $post_id,
				'components' => $components,
				'storage'    => $storage,
				'version'    => e2m_engine_oxygen_detect_version(),
			];

		// ── Write ─────────────────────────────────────────────────────────────
		case 'write':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for write.', 'e2mconnect' ) );
			}
			if ( ! isset( $input['components'] ) || ! is_array( $input['components'] ) ) {
				return new WP_Error( 'missing_components', __( '"components" array is required for write.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'e2mconnect' ) );
			}

			e2m_engine_oxygen_write_components( $post_id, $input['components'] );

			return [
				'action'  => 'write',
				'post_id' => $post_id,
				'saved'   => true,
				'storage' => e2m_engine_oxygen_storage_key(),
			];

		// ── Get version ────────────────────────────────────────────────────────
		case 'get_version':
			return [
				'action'         => 'get_version',
				'version'        => e2m_engine_oxygen_detect_version(),
				'storage'        => e2m_engine_oxygen_storage_key(),
				'constant'       => defined( 'CT_VERSION' ) ? CT_VERSION : ( defined( 'BREAKDANCE_VERSION' ) ? BREAKDANCE_VERSION : 'unknown' ),
				'oxygen_6'       => e2m_engine_oxygen_is_v6(),
			];

		// ── List pages ────────────────────────────────────────────────────────
		case 'list_pages':
			$per_page  = max( 1, (int) ( $input['per_page'] ?? 20 ) );
			$post_type = sanitize_key( $input['post_type'] ?? 'any' );

			// Find posts that have Oxygen builder data.
			$storage_key = e2m_engine_oxygen_storage_key();
			$query = new WP_Query( [
				'post_type'      => $post_type === 'any' ? [ 'post', 'page' ] : $post_type,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => $per_page,
				'meta_query'     => [
					[
						'key'     => $storage_key,
						'compare' => 'EXISTS',
					],
				],
			] );

			$pages = [];
			foreach ( $query->posts as $p ) {
				$pages[] = [
					'post_id'    => $p->ID,
					'title'      => $p->post_title,
					'post_type'  => $p->post_type,
					'status'     => $p->post_status,
					'edit_url'   => get_edit_post_link( $p->ID, 'raw' ),
				];
			}

			return [
				'action' => 'list_pages',
				'pages'  => $pages,
				'total'  => (int) $query->found_posts,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: read, write, get_version, list_pages.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Oxygen version + storage helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Detect whether Oxygen 6 (Breakdance-based, mode === 'oxygen') is active.
 */
function e2m_engine_oxygen_is_v6(): bool {
	// ONLY match when BREAKDANCE_MODE is explicitly 'oxygen'.
	// Do NOT check __BREAKDANCE_VERSION alone — that constant is also defined when
	// Breakdance Builder is active, which would cause a false-positive and make
	// Oxygen Classic write to the wrong meta key (breakdance_data instead of ct_builder_json).
	return defined( 'BREAKDANCE_MODE' ) && BREAKDANCE_MODE === 'oxygen';
}

/**
 * Detect Oxygen version string.
 */
function e2m_engine_oxygen_detect_version(): string {
	if ( e2m_engine_oxygen_is_v6() ) {
		$v = defined( '__BREAKDANCE_VERSION' ) ? __BREAKDANCE_VERSION : 'unknown';
		return '6.x (Breakdance/Oxygen 6) — ' . $v;
	}
	if ( defined( 'CT_VERSION' ) ) {
		return '3/4.x — ' . CT_VERSION;
	}
	return 'unknown';
}

/**
 * Get the post meta key used to store Oxygen components.
 */
function e2m_engine_oxygen_storage_key(): string {
	if ( e2m_engine_oxygen_is_v6() ) {
		return 'breakdance_data';
	}
	return 'ct_builder_json';
}

/**
 * Read Oxygen components from a post.
 *
 * @param int $post_id
 * @return array{0: array, 1: string}  [components, storage_key]
 */
function e2m_engine_oxygen_read_components( int $post_id ): array {
	$key = e2m_engine_oxygen_storage_key();
	$raw = get_post_meta( $post_id, $key, true );

	if ( $raw ) {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
		return [ is_array( $decoded ) ? $decoded : [], $key ];
	}

	// Fallback: older Oxygen stored shortcodes in post_content.
	$post    = get_post( $post_id );
	$content = $post->post_content ?? '';
	if ( str_contains( $content, '[ct_section' ) || str_contains( $content, '[oxy_section' ) ) {
		return [ [ [ 'type' => 'raw_shortcode', 'content' => $content ] ], 'post_content' ];
	}

	return [ [], $key ];
}

/**
 * Write Oxygen components to a post.
 * Saves the JSON tree AND generates ct_builder_shortcodes for frontend rendering.
 *
 * @param int   $post_id
 * @param array $components
 */
function e2m_engine_oxygen_write_components( int $post_id, array $components ): void {
	$key = e2m_engine_oxygen_storage_key();

	// Save the JSON tree (wp_slash compensates for WP's auto-unslash on save).
	update_post_meta( $post_id, $key, wp_slash( wp_json_encode( $components ) ) );

	// CRITICAL: Oxygen v3/4 renders from ct_builder_shortcodes, not ct_builder_json.
	// Without this key the frontend shows blank content.
	if ( ! e2m_engine_oxygen_is_v6() ) {
		$shortcodes = e2m_engine_oxygen_tree_to_shortcodes( $components );
		update_post_meta( $post_id, 'ct_builder_shortcodes', wp_slash( $shortcodes ) );

		// Trigger Oxygen's CSS/stylesheet regeneration if the hook is available.
		if ( function_exists( 'oxygen_vsb_register_user_stylesheets' ) ) {
			do_action( 'oxygen_vsb_save_user_stylesheets', $post_id );
		}
	}

	// Mark post as built with Oxygen.
	update_post_meta( $post_id, 'ct_other_template', false );

	// Flush any page caches.
	clean_post_cache( $post_id );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
}

/**
 * Convert a E2M Connect Oxygen component tree to Oxygen v3/4 shortcode markup.
 *
 * Oxygen stores its frontend-renderable content as a string of nested shortcodes
 * in ct_builder_shortcodes. Each component becomes [type id="N" options="SERIALIZED"]
 * with its children nested inside.
 *
 * @param array $components  Array of component nodes from the JSON tree.
 * @return string            Oxygen shortcode string.
 */
function e2m_engine_oxygen_tree_to_shortcodes( array $components ): string {
	// Self-closing component types (no inner content / children rendered inline).
	static $self_closing = [
		'ct_image', 'oxy_image', 'ct_video', 'oxy_video',
		'ct_button', 'oxy_button', 'ct_spacer', 'oxy_spacer',
		'ct_icon', 'oxy_icon', 'ct_divider', 'oxy_divider',
		'ct_shortcode', 'oxy_shortcode', 'ct_code_block',
	];

	$output = '';
	foreach ( $components as $component ) {
		$type = (string) ( $component['type'] ?? '' );
		if ( $type === '' ) {
			continue;
		}

		// Raw shortcode passthrough (read from post_content fallback).
		if ( $type === 'raw_shortcode' ) {
			$output .= (string) ( $component['content'] ?? '' );
			continue;
		}

		$id      = (string) ( $component['id'] ?? '' );
		$options = isset( $component['options'] ) && is_array( $component['options'] )
			? serialize( $component['options'] )
			: 'a:0:{}';

		// Encode options for use inside an HTML attribute (Oxygen's own approach).
		$attr = ' id="' . esc_attr( $id ) . '" options="' . esc_attr( $options ) . '"';

		$children_html = e2m_engine_oxygen_tree_to_shortcodes( $component['children'] ?? [] );

		if ( in_array( $type, $self_closing, true ) && $children_html === '' ) {
			$output .= "[{$type}{$attr}]";
		} else {
			$output .= "[{$type}{$attr}]{$children_html}[/{$type}]";
		}
	}
	return $output;
}
