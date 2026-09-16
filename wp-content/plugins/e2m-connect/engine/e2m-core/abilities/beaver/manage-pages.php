<?php
/**
 * E2M Connect MCP – Beaver Builder Manage Pages
 *
 * Read and write Beaver Builder page content. BB stores its component
 * tree as PHP-serialized stdClass data in the post meta key
 * `_fl_builder_data` (a flat associative node map keyed by node IDs).
 *
 * Actions:
 *   read        — get the BB flat node map for a post
 *   write       — replace the full BB node map for a post
 *   list_pages  — list posts built with Beaver Builder
 *   get_version — detect which BB version is active
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/beaver-manage-pages', [
	'label'       => __( '[Beaver] Manage Pages', 'e2mconnect' ),
	'description' => 'Read and write Beaver Builder flat node maps on posts/pages. Supports read, write, list_pages, and get_version.',
	'category'    => 'e2m-beaver',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'list_pages', 'get_version' ],
				'description' => 'read — get node map; write — set node map; list_pages — list BB-built posts; get_version — detect BB version.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID. Required for read and write.',
			],
			'content' => [
				'description' => 'Flat node map (array or JSON string) to write. Required for write.',
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
			'post_type' => [
				'type'        => 'string',
				'description' => 'Filter list_pages by post type. Default: any.',
				'default'     => 'any',
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
			'content'  => [ 'type' => 'object', 'description' => 'Flat node map (JSON-safe arrays).' ],
			'version'  => [ 'type' => 'string' ],
			'pages'    => [ 'type' => 'array' ],
			'total'    => [ 'type' => 'integer' ],
			'saved'    => [ 'type' => 'boolean' ],
			'errors'   => [ 'type' => 'array' ],
		],
	],

	'execute_callback'    => 'e2m_engine_beaver_manage_pages',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Beaver: Manage Pages',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute beaver-manage-pages ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_beaver_manage_pages( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_beaver() ) {
		return new WP_Error( 'beaver_missing', __( 'Beaver Builder is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Read ──────────────────────────────────────────────────────────────
		case 'read':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for read.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'e2mconnect' ) );
			}

			$raw     = get_post_meta( $post_id, '_fl_builder_data', true );
			$content = e2m_engine_beaver_nodes_to_arrays( $raw );

			return [
				'action'   => 'read',
				'post_id'  => $post_id,
				'content'  => is_array( $content ) ? $content : [],
				'enabled'  => get_post_meta( $post_id, '_fl_builder_enabled', true ) === '1',
				'version'  => defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : 'unknown',
			];

		// ── Write ─────────────────────────────────────────────────────────────
		case 'write':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for write.', 'e2mconnect' ) );
			}
			if ( ! isset( $input['content'] ) ) {
				return new WP_Error( 'missing_content', __( '"content" is required for write.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'e2mconnect' ) );
			}

			// Decode JSON string if given.
			$content = $input['content'];
			if ( is_string( $content ) ) {
				$content = json_decode( $content, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error( 'invalid_json', __( 'content is not valid JSON.', 'e2mconnect' ) );
				}
			}

			if ( ! is_array( $content ) ) {
				return new WP_Error( 'invalid_content', __( 'content must be an array or JSON object.', 'e2mconnect' ) );
			}

			// Validate with Respira if available.
			$validation_errors = [];
			if ( class_exists( 'Respira_Beaver_Validator' ) ) {
				$validator = new Respira_Beaver_Validator();
				$result    = $validator->validate_layout( $content );
				if ( ! empty( $result['errors'] ) ) {
					$validation_errors = $result['errors'];
				}
			}

			// Normalize module type names: BB v2+ uses 'fl-heading' not 'heading'.
			$content = e2m_engine_beaver_normalize_module_types( $content );

			// Convert arrays to BB stdClass node format.
			$node_map = e2m_engine_beaver_arrays_to_nodes( $content );

			// Save to all three BB meta keys:
			// _fl_builder_data          = published layout (rendered on frontend)
			// _fl_builder_data_published = duplicate kept by BB v2.8+ for rollback
			// _fl_builder_draft          = working draft (shown in editor before publish)
			update_post_meta( $post_id, '_fl_builder_data', $node_map );
			update_post_meta( $post_id, '_fl_builder_data_published', $node_map );
			update_post_meta( $post_id, '_fl_builder_draft', $node_map );

			// Mark the page as BB-enabled.
			update_post_meta( $post_id, '_fl_builder_enabled', '1' );

			// Tell BB to re-render the CSS/HTML. This prevents stale asset caches
			// from serving the old (empty) layout on the frontend.
			if ( class_exists( 'FLBuilder' ) && method_exists( 'FLBuilder', 'delete_all_asset_cache' ) ) {
				FLBuilder::delete_all_asset_cache( $post_id );
			}
			// For BB v2.8+: clear the rendered layout transient.
			delete_transient( 'fl_builder_layout_' . $post_id );
			delete_post_meta( $post_id, '_fl_builder_css' );
			delete_post_meta( $post_id, '_fl_builder_css_global' );

			// Flush WordPress page cache.
			clean_post_cache( $post_id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $post_id );
			}

			return [
				'action'  => 'write',
				'post_id' => $post_id,
				'saved'   => true,
				'errors'  => $validation_errors,
			];

		// ── List pages ────────────────────────────────────────────────────────
		case 'list_pages':
			$per_page  = max( 1, (int) ( $input['per_page'] ?? 20 ) );
			$page      = max( 1, (int) ( $input['page'] ?? 1 ) );
			$post_type = sanitize_text_field( $input['post_type'] ?? 'any' );

			$query_args = [
				'post_type'      => $post_type === 'any' ? [ 'post', 'page' ] : $post_type,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => [
					[
						'key'     => '_fl_builder_data',
						'compare' => 'EXISTS',
					],
				],
			];

			$query = new WP_Query( $query_args );

			$pages = [];
			foreach ( $query->posts as $p ) {
				$pages[] = [
					'post_id'   => $p->ID,
					'title'     => $p->post_title,
					'post_type' => $p->post_type,
					'status'    => $p->post_status,
					'enabled'   => get_post_meta( $p->ID, '_fl_builder_enabled', true ) === '1',
					'edit_url'  => get_edit_post_link( $p->ID, 'raw' ),
				];
			}

			return [
				'action' => 'list_pages',
				'pages'  => $pages,
				'total'  => (int) $query->found_posts,
				'page'   => $page,
			];

		// ── Get version ───────────────────────────────────────────────────────
		case 'get_version':
			return [
				'action'  => 'get_version',
				'version' => defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : 'unknown',
				'constant' => 'FL_BUILDER_VERSION',
				'fl_builder_class'      => class_exists( 'FLBuilder' ),
				'fl_builder_model_class' => class_exists( 'FLBuilderModel' ),
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: read, write, list_pages, get_version.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Node conversion helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Recursively convert stdClass objects to arrays (for JSON-safe output).
 *
 * @param mixed $data
 * @return mixed
 */
function e2m_engine_beaver_nodes_to_arrays( mixed $data ): mixed {
	if ( $data instanceof stdClass ) {
		$data = (array) $data;
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) {
			$data[ $k ] = e2m_engine_beaver_nodes_to_arrays( $v );
		}
	}
	return $data;
}

/**
 * Convert a flat node map (arrays) to BB stdClass format for storage.
 *
 * BB expects `_fl_builder_data` to be a flat associative map keyed by
 * 13-char node IDs where each value is a stdClass with node, type,
 * parent, position, settings properties. The settings property must
 * be stdClass too — EXCEPT for typography sub-fields which must stay
 * as PHP arrays (BB crashes if they're stdClass).
 *
 * @param array<string,mixed> $flat  Flat node map (arrays).
 * @return array<string,stdClass>    BB-ready node map.
 */
function e2m_engine_beaver_arrays_to_nodes( array $flat ): array {
	// Typography sub-keys that MUST remain as PHP arrays, not stdClass.
	$typography_keys = [ 'font_size', 'line_height', 'letter_spacing', 'text_shadow', 'text_transform' ];

	$node_map = [];

	foreach ( $flat as $key => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		// Determine the node ID: prefer 'node' property, else use array key.
		$node_id = $item['node'] ?? ( is_string( $key ) ? $key : e2m_engine_beaver_generate_node_id() );

		$node           = new stdClass();
		$node->node     = $node_id;
		$node->type     = $item['type'] ?? 'module';
		$node->parent   = $item['parent'] ?? null;
		$node->position = isset( $item['position'] ) ? (int) $item['position'] : 0;

		// Convert settings array to stdClass, but protect typography sub-fields.
		if ( isset( $item['settings'] ) && is_array( $item['settings'] ) ) {
			$settings_obj = new stdClass();
			foreach ( $item['settings'] as $prop => $val ) {
				if ( in_array( $prop, $typography_keys, true ) ) {
					// Keep typography fields as PHP arrays — BB requires this.
					$settings_obj->$prop = is_array( $val ) ? $val : [ $val ];
				} elseif ( is_array( $val ) ) {
					// Nested arrays that are not typography stay as arrays.
					$settings_obj->$prop = $val;
				} else {
					$settings_obj->$prop = $val;
				}
			}
			$node->settings = $settings_obj;
		} else {
			$node->settings = new stdClass();
		}

		$node_map[ $node_id ] = $node;
	}

	return $node_map;
}

/**
 * Normalize short module type names to Beaver Builder's internal fl- prefixed slugs.
 *
 * BB v2+ uses 'fl-heading' internally; older or user-supplied data may use 'heading'.
 * This normalises the `type` field on every module node in the flat map.
 *
 * @param array<string,mixed> $flat  Flat node map (arrays).
 * @return array<string,mixed>
 */
function e2m_engine_beaver_normalize_module_types( array $flat ): array {
	$map = [
		'heading'        => 'fl-heading',
		'html'           => 'fl-html',
		'button'         => 'fl-button',
		'photo'          => 'fl-photo',
		'video'          => 'fl-video',
		'icon'           => 'fl-icon',
		'separator'      => 'fl-separator',
		'callout'        => 'fl-callout',
		'text'           => 'fl-rich-text',
		'rich-text'      => 'fl-rich-text',
		'posts'          => 'fl-post-grid',
		'post-grid'      => 'fl-post-grid',
		'accordion'      => 'fl-accordion',
		'tabs'           => 'fl-tabs',
		'map'            => 'fl-map',
		'slideshow'      => 'fl-slideshow',
		'gallery'        => 'fl-gallery',
		'numbers'        => 'fl-numbers',
		'bar-chart'      => 'fl-bar-chart',
		'countdown'      => 'fl-countdown',
		'subscribe-form' => 'fl-subscribe-form',
		'contact-form'   => 'fl-contact-form',
		'pricing-table'  => 'fl-pricing-table',
		'testimonials'   => 'fl-testimonials',
		'social-buttons' => 'fl-social-buttons',
		'widget'         => 'fl-widget',
	];

	foreach ( $flat as $key => &$item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		// Normalise the top-level 'type' field on each node.
		if ( isset( $item['type'] ) && is_string( $item['type'] ) ) {
			$t = $item['type'];
			if ( isset( $map[ $t ] ) ) {
				$item['type'] = $map[ $t ];
			}
		}
	}
	unset( $item );

	return $flat;
}

/**
 * Generate a Beaver Builder compatible 13-character alphanumeric node ID.
 *
 * @return string
 */
function e2m_engine_beaver_generate_node_id(): string {
	return substr( str_replace( '.', '', uniqid( '', true ) ), -13 );
}
