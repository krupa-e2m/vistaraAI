<?php
/**
 * E2M Connect MCP – WPBakery Manage Pages
 *
 * Read and write WPBakery Page Builder content on WordPress posts/pages.
 * WPBakery stores its shortcode tree directly in post_content; this ability
 * parses shortcode strings into the E2M Connect tree format for reading, and
 * serialises tree arrays back to shortcode strings for writing.
 *
 * Actions:
 *   read        — get parsed WPBakery shortcode tree from a post
 *   write       — write a tree array or raw shortcode string to a post
 *   list_pages  — list posts/pages that contain WPBakery content
 *   get_version — detect installed WPBakery version
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/wpbakery-manage-pages', [
	'label'       => __( '[WPBakery] Manage Pages', 'e2mconnect' ),
	'description' => 'Read and write WPBakery shortcode trees on posts/pages. Supports read (returns tree array), write (accepts tree or raw shortcode string), list_pages, and get_version.',
	'category'    => 'e2m-wpbakery',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'list_pages', 'get_version' ],
				'description' => 'read — get shortcode tree; write — set shortcode tree; list_pages — list WPBakery posts; get_version — detect WPBakery version.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID. Required for read and write.',
			],
			'content' => [
				'description' => 'Tree array [{type, attributes, content, children}] OR a raw WPBakery shortcode string. Required for write.',
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'Post type filter for list_pages. Default: any.',
				'default'     => 'any',
			],
			'per_page' => [
				'type'        => 'integer',
				'description' => 'Max results for list_pages. Default: 20.',
				'default'     => 20,
			],
			'page' => [
				'type'        => 'integer',
				'description' => 'Page number for list_pages pagination. Default: 1.',
				'default'     => 1,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'post_id'  => [ 'type' => 'integer' ],
			'tree'     => [ 'type' => 'array' ],
			'raw'      => [ 'type' => 'string' ],
			'pages'    => [ 'type' => 'array' ],
			'total'    => [ 'type' => 'integer' ],
			'saved'    => [ 'type' => 'boolean' ],
			'version'  => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_wpbakery_manage_pages',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'WPBakery: Manage Pages',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute wpbakery-manage-pages ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_wpbakery_manage_pages( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_wpbakery() ) {
		return new WP_Error( 'wpbakery_missing', __( 'WPBakery Page Builder is not active on this site.', 'e2mconnect' ) );
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

			$raw  = (string) $post->post_content;
			$tree = e2m_engine_wpbakery_parse_shortcode_string( $raw );

			return [
				'action'  => 'read',
				'post_id' => $post_id,
				'tree'    => $tree,
				'raw'     => $raw,
				'version' => e2m_engine_wpbakery_get_version_string(),
			];

		// ── Write ─────────────────────────────────────────────────────────────
		case 'write':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for write.', 'e2mconnect' ) );
			}
			if ( ! isset( $input['content'] ) ) {
				return new WP_Error( 'missing_content', __( '"content" (tree array or shortcode string) is required for write.', 'e2mconnect' ) );
			}

			$post_id = (int) $input['post_id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'e2mconnect' ) );
			}

			$content = $input['content'];

			// Convert tree array to shortcode string if needed.
			if ( is_array( $content ) ) {
				$shortcode_string = e2m_engine_wpbakery_tree_to_shortcodes( $content );
			} else {
				$shortcode_string = (string) $content;
			}

			// Persist via wp_update_post.
			$result = wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $shortcode_string,
			], true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Mark post as WPBakery-managed.
			update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );

			// Flush caches.
			clean_post_cache( $post_id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $post_id );
			}

			return [
				'action'  => 'write',
				'post_id' => $post_id,
				'saved'   => true,
			];

		// ── List pages ────────────────────────────────────────────────────────
		case 'list_pages':
			$per_page  = max( 1, (int) ( $input['per_page'] ?? 20 ) );
			$page_num  = max( 1, (int) ( $input['page'] ?? 1 ) );
			$post_type = sanitize_text_field( $input['post_type'] ?? 'any' );

			// Find posts that have WPBakery content via meta or content pattern.
			// Primary detection: _wpb_vc_js_status meta (most reliable).
			$query_args = [
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => $per_page,
				'paged'          => $page_num,
				'meta_query'     => [
					[
						'key'     => '_wpb_vc_js_status',
						'value'   => 'true',
						'compare' => '=',
					],
				],
			];

			if ( $post_type !== 'any' ) {
				$query_args['post_type'] = sanitize_key( $post_type );
			} else {
				$query_args['post_type'] = [ 'post', 'page' ];
			}

			$query = new WP_Query( $query_args );

			// If no meta-tagged posts found, fall back to content search.
			if ( $query->found_posts === 0 ) {
				unset( $query_args['meta_query'] );
				add_filter( 'posts_where', 'e2m_engine_wpbakery_content_like_filter', 10, 2 );
				$query = new WP_Query( $query_args );
				remove_filter( 'posts_where', 'e2m_engine_wpbakery_content_like_filter', 10 );
			}

			$pages = [];
			foreach ( $query->posts as $p ) {
				$pages[] = [
					'post_id'   => $p->ID,
					'title'     => $p->post_title,
					'post_type' => $p->post_type,
					'status'    => $p->post_status,
					'edit_url'  => get_edit_post_link( $p->ID, 'raw' ),
				];
			}

			return [
				'action' => 'list_pages',
				'pages'  => $pages,
				'total'  => (int) $query->found_posts,
			];

		// ── Get version ────────────────────────────────────────────────────────
		case 'get_version':
			return [
				'action'  => 'get_version',
				'version' => e2m_engine_wpbakery_get_version_string(),
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: read, write, list_pages, get_version.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * posts_where filter: add LIKE '%[vc_%' condition.
 *
 * @param string   $where
 * @param WP_Query $q
 * @return string
 */
function e2m_engine_wpbakery_content_like_filter( string $where, WP_Query $q ): string {
	global $wpdb;
	$where .= $wpdb->prepare( ' AND ' . $wpdb->posts . '.post_content LIKE %s', '%[vc_%' );
	return $where;
}

/**
 * Return the installed WPBakery version string.
 */
function e2m_engine_wpbakery_get_version_string(): string {
	if ( defined( 'WPB_VC_VERSION' ) ) {
		return WPB_VC_VERSION;
	}
	return 'unknown';
}

/**
 * Convert a E2M Connect tree array back to a WPBakery shortcode string.
 *
 * @param array<int,array<string,mixed>> $tree
 * @return string
 */
function e2m_engine_wpbakery_tree_to_shortcodes( array $tree ): string {
	$output = '';

	// Self-closing shortcodes — those that never wrap content or children.
	$self_closing = [
		'vc_spacer', 'vc_separator', 'vc_single_image', 'vc_video', 'vc_gmaps',
		'vc_facebook', 'vc_tweetmeme', 'vc_googleplus', 'vc_pinterest',
		'vc_wp_search', 'vc_wp_meta',
	];

	foreach ( $tree as $node ) {
		if ( ! is_array( $node ) || empty( $node['type'] ) ) {
			continue;
		}

		$type       = (string) $node['type'];
		$attributes = is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [];
		$content    = (string) ( $node['content'] ?? '' );
		$children   = is_array( $node['children'] ?? null ) ? $node['children'] : [];

		$attrs_string = e2m_engine_wpbakery_format_shortcode_attrs( $attributes );
		$open_tag     = $attrs_string !== '' ? "[{$type} {$attrs_string}]" : "[{$type}]";

		$has_inner = ! empty( $children ) || $content !== '';

		if ( ! $has_inner && in_array( $type, $self_closing, true ) ) {
			// Emit self-closing.
			$attrs_string = e2m_engine_wpbakery_format_shortcode_attrs( $attributes );
			$output .= $attrs_string !== '' ? "[{$type} {$attrs_string}]" : "[{$type}]";
			$output .= "\n";
			continue;
		}

		// Opening tag.
		$output .= $open_tag . "\n";

		// vc_raw_html: content is base64-encoded.
		if ( $type === 'vc_raw_html' && $content !== '' ) {
			$output .= base64_encode( $content );
		} elseif ( $type === 'vc_raw_js' && $content !== '' ) {
			$output .= base64_encode( $content );
		} elseif ( ! empty( $children ) ) {
			$output .= e2m_engine_wpbakery_tree_to_shortcodes( $children );
		} elseif ( $content !== '' ) {
			$output .= $content . "\n";
		}

		// Closing tag.
		$output .= "[/{$type}]\n";
	}

	return $output;
}

/**
 * Convert an attributes array to a `key="value"` attribute string.
 *
 * @param array<string,mixed> $attributes
 * @return string
 */
function e2m_engine_wpbakery_format_shortcode_attrs( array $attributes ): string {
	if ( empty( $attributes ) ) {
		return '';
	}
	$parts = [];
	foreach ( $attributes as $key => $value ) {
		$key   = sanitize_key( (string) $key );
		$value = (string) $value;
		// Escape inner double-quotes.
		$value   = str_replace( '"', '&quot;', $value );
		$parts[] = "{$key}=\"{$value}\"";
	}
	return implode( ' ', $parts );
}
