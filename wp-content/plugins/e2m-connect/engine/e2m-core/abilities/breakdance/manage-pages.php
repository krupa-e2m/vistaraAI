<?php
/**
 * E2M Connect MCP – Breakdance Manage Pages
 *
 * Read and write Breakdance Builder page content. Breakdance stores its
 * element tree as JSON in the post meta key `_breakdance_data`.
 * Multiple storage formats are handled transparently:
 *   - Plain JSON array of elements
 *   - {tree_json_string:"..."} wrapper (v2.6+)
 *   - {root:{children:[...]}} or {type:"root",children:[...]} wrappers
 *
 * Actions:
 *   read        — get the Breakdance element tree for a post
 *   write       — replace the full element tree for a post
 *   list_pages  — list posts built with Breakdance
 *   get_version — detect which Breakdance version is active
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/breakdance-manage-pages', [
	'label'       => __( '[Breakdance] Manage Pages', 'e2mconnect' ),
	'description' => 'Read and write Breakdance Builder element trees on posts/pages. Supports read, write, list_pages, and get_version.',
	'category'    => 'e2m-breakdance',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'read', 'write', 'list_pages', 'get_version' ],
				'description' => 'read — get element tree; write — set element tree; list_pages — list Breakdance-built posts; get_version — detect Breakdance version.',
			],
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID. Required for read and write.',
			],
			'content' => [
				'description' => 'Element tree (array or JSON string) to write. Required for write.',
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
			'action'  => [ 'type' => 'string' ],
			'post_id' => [ 'type' => 'integer' ],
			'content' => [ 'type' => 'array', 'description' => 'Normalized element tree.' ],
			'version' => [ 'type' => 'string' ],
			'pages'   => [ 'type' => 'array' ],
			'total'   => [ 'type' => 'integer' ],
			'saved'   => [ 'type' => 'boolean' ],
			'errors'  => [ 'type' => 'array' ],
		],
	],

	'execute_callback'    => 'e2m_engine_breakdance_manage_pages',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Breakdance: Manage Pages',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute breakdance-manage-pages ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_breakdance_manage_pages( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_breakdance() ) {
		return new WP_Error( 'breakdance_missing', __( 'Breakdance Builder is not active on this site.', 'e2mconnect' ) );
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

			$raw  = get_post_meta( $post_id, '_breakdance_data', true );
			$tree = e2m_engine_breakdance_decode_tree( $raw );

			return [
				'action'  => 'read',
				'post_id' => $post_id,
				'content' => $tree,
				'version' => defined( 'BREAKDANCE_PLUGIN_VERSION' ) ? BREAKDANCE_PLUGIN_VERSION
					: ( defined( '__BREAKDANCE_VERSION' ) ? __BREAKDANCE_VERSION : 'unknown' ),
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
				return new WP_Error( 'invalid_content', __( 'content must be an array or JSON string.', 'e2mconnect' ) );
			}

			// Normalise the incoming tree (handle wrapped formats).
			$content = e2m_engine_breakdance_decode_tree( $content );

			// Auto-assign integer IDs to any elements missing them.
			$counter = 1;
			$content = e2m_engine_breakdance_auto_id( $content, $counter );

			// Optional Respira validation.
			$validation_errors = [];
			if ( class_exists( 'Respira_Breakdance_Validator' ) ) {
				$validator = new Respira_Breakdance_Validator();
				$result    = $validator->validate_layout( $content );
				if ( ! empty( $result['errors'] ) ) {
					$validation_errors = $result['errors'];
				}
			}

			// Breakdance 2.6+ stores data in a {tree_json_string:"..."} wrapper.
			// Writing a plain JSON array causes a WordPress critical error when the
			// Breakdance editor loads the page, because BD's loader expects the wrapper.
			// We always write the wrapper format so BD's own reader gets what it expects.
			// Our decode_tree() already handles both formats on read.
			$json_tree = wp_json_encode( $content );
			$json      = wp_json_encode( [ 'tree_json_string' => $json_tree ] );
			update_post_meta( $post_id, '_breakdance_data', wp_slash( $json ) );

			// Clear Breakdance page cache.
			if ( has_action( 'breakdance_invalidate_caches' ) ) {
				do_action( 'breakdance_invalidate_caches', $post_id );
			}
			delete_transient( "breakdance_page_{$post_id}" );

			// Flush WordPress object cache for this post.
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

			// Include Breakdance-specific post types too.
			$query_post_type = ( $post_type === 'any' )
				? [ 'post', 'page', 'breakdance_template', 'breakdance_global_block' ]
				: $post_type;

			$query_args = [
				'post_type'      => $query_post_type,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => [
					[
						'key'     => '_breakdance_data',
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
			$version = 'unknown';
			if ( defined( 'BREAKDANCE_PLUGIN_VERSION' ) ) {
				$version = BREAKDANCE_PLUGIN_VERSION;
			} else {
				// Try reading from plugin file header.
				$plugin_file = WP_PLUGIN_DIR . '/breakdance/plugin.php';
				if ( file_exists( $plugin_file ) ) {
					$headers = get_file_data( $plugin_file, [ 'Version' => 'Version' ] );
					$version = $headers['Version'] ?? 'unknown';
				}
			}

			return [
				'action'               => 'get_version',
				'version'              => $version,
				'constant'             => 'BREAKDANCE_PLUGIN_VERSION',
				'breakdance_class'     => class_exists( 'Breakdance\\Plugin' ),
				'breakdance_init_fn'   => function_exists( 'breakdance_init' ),
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: read, write, list_pages, get_version.', 'e2mconnect' ) );
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Storage format helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Decode a raw _breakdance_data value into a flat element tree array.
 *
 * Handles all known storage formats:
 *   - JSON string
 *   - Already-decoded array
 *   - {tree_json_string:"..."} wrapper (v2.6+)
 *   - {root:{children:[...]}} wrapper
 *   - {type:"root",children:[...]} wrapper
 *
 * @param mixed $raw  Raw value from get_post_meta().
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_breakdance_decode_tree( mixed $raw ): array {
	// Decode JSON string.
	if ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			$raw = $decoded;
		} else {
			// Not valid JSON — return empty.
			return [];
		}
	}

	if ( ! is_array( $raw ) ) {
		return [];
	}

	// Unwrap {tree_json_string:"..."} format.
	if ( isset( $raw['tree_json_string'] ) && is_string( $raw['tree_json_string'] ) ) {
		$decoded = json_decode( $raw['tree_json_string'], true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			$raw = $decoded;
		}
	}

	// Unwrap {root:{children:[...]}} format.
	if ( isset( $raw['root'] ) && is_array( $raw['root'] ) ) {
		$raw = $raw['root']['children'] ?? $raw['root'];
	}

	// Unwrap {type:"root",children:[...]} format.
	if ( isset( $raw['type'] ) && $raw['type'] === 'root' && isset( $raw['children'] ) ) {
		$raw = $raw['children'];
	}

	return is_array( $raw ) ? array_values( $raw ) : [];
}

/**
 * Recursively assign sequential integer IDs to elements missing them.
 *
 * @param array<int,array<string,mixed>> $elements
 * @param int                            $counter   Running ID counter (by reference).
 * @return array<int,array<string,mixed>>
 */
function e2m_engine_breakdance_auto_id( array $elements, int &$counter = 1 ): array {
	$result = [];
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			$result[] = $element;
			continue;
		}
		if ( ! isset( $element['id'] ) || $element['id'] === 0 || $element['id'] === '' ) {
			$element['id'] = $counter++;
		} else {
			$counter++;
		}
		if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
			$element['children'] = e2m_engine_breakdance_auto_id( $element['children'], $counter );
		}
		$result[] = $element;
	}
	return $result;
}
