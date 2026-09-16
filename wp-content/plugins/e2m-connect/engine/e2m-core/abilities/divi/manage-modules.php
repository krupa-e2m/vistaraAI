<?php
/**
 * E2M Connect MCP – Divi Manage Modules
 *
 * Work with Divi 5 module schemas and perform targeted module patches
 * directly on a live post without rebuilding the whole page.
 *
 * Actions:
 *   get_schema        — get simplified settings schema for a Divi 5 module type
 *   list_schemas      — list schemas for all known Divi 5 module types
 *   patch             — patch a Divi 5 module's attrs by _nodeId (merge / set / unset)
 *   materialize_ids   — back-fill missing _nodeId UUIDs on a page (pre-v6.6.1 pages)
 *
 * Requires Divi 5 (block-based builder). patch and materialize_ids require
 * Respira_API; get_schema / list_schemas work with or without Respira.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/divi-manage-modules', [
	'label'       => __( '[Divi] Manage Modules', 'e2mconnect' ),
	'description' => 'Divi 5 module operations: get_schema (settings schema for a module type), list_schemas (all module schemas), patch (update a live module by _nodeId), materialize_ids (back-fill missing _nodeId UUIDs).',
	'category'    => 'e2m-divi',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'get_schema', 'list_schemas', 'patch', 'materialize_ids' ],
				'description' => 'Operation to perform.',
			],
			// get_schema
			'slug' => [
				'type'        => 'string',
				'description' => 'Module type slug for get_schema, e.g. "divi/button", "divi/heading".',
			],
			// patch
			'post_id' => [
				'type'        => 'integer',
				'description' => 'Post ID. Required for patch and materialize_ids.',
			],
			'_nodeId' => [
				'type'        => 'string',
				'description' => 'UUID of the target Divi 5 module node. Required for patch.',
			],
			'attrs_patch' => [
				'type'        => 'object',
				'description' => 'Patch payload for patch action. Keys: merge (object — simplified settings via complexify), set (object — raw attr key→value), unset (array — attr keys to remove).',
				'properties'  => [
					'merge' => [ 'type' => 'object', 'additionalProperties' => true ],
					'set'   => [ 'type' => 'object', 'additionalProperties' => true ],
					'unset' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
				'additionalProperties' => false,
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'   => [ 'type' => 'string' ],
			'slug'     => [ 'type' => 'string' ],
			'schema'   => [ 'type' => 'object' ],
			'schemas'  => [ 'type' => 'object' ],
			'post_id'  => [ 'type' => 'integer' ],
			'_nodeId'  => [ 'type' => 'string' ],
			'patched'  => [ 'type' => 'boolean' ],
			'minted'   => [ 'type' => 'integer' ],
			'saved'    => [ 'type' => 'boolean' ],
			'warnings' => [ 'type' => 'array' ],
		],
	],

	'execute_callback'    => 'e2m_engine_divi_manage_modules',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Divi: Manage Modules',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute divi-manage-modules ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_manage_modules( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_divi() ) {
		return new WP_Error( 'divi_missing', __( 'Divi theme or Divi Builder plugin is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── Get schema ────────────────────────────────────────────────────────
		case 'get_schema':
			if ( empty( $input['slug'] ) ) {
				return new WP_Error( 'missing_slug', __( '"slug" is required for get_schema.', 'e2mconnect' ) );
			}
			$slug = sanitize_text_field( $input['slug'] );

			if ( class_exists( 'Respira_Divi_Module_Schema' ) ) {
				$schema = Respira_Divi_Module_Schema::get_module_schema( $slug );
				return [
					'action'    => 'get_schema',
					'slug'      => $slug,
					'schema'    => $schema ?: [],
					'available' => ! empty( $schema ),
				];
			}
			// Native fallback — return empty schema with note.
			return [
				'action'    => 'get_schema',
				'slug'      => $slug,
				'schema'    => [],
				'available' => false,
				'note'      => 'Divi module schema intelligence not loaded. Ensure the Respira_Divi_Module_Schema class is available.',
			];

		// ── List all schemas ──────────────────────────────────────────────────
		case 'list_schemas':
			if ( class_exists( 'Respira_Divi_Module_Schema' ) ) {
				$schemas = Respira_Divi_Module_Schema::get_all_schemas();
				return [
					'action'    => 'list_schemas',
					'schemas'   => is_array( $schemas ) ? $schemas : [],
					'count'     => is_array( $schemas ) ? count( $schemas ) : 0,
					'available' => ! empty( $schemas ),
				];
			}
			return [
				'action'    => 'list_schemas',
				'schemas'   => [],
				'count'     => 0,
				'available' => false,
				'note'      => 'Divi module schema intelligence not loaded.',
			];

		// ── Patch module attrs ────────────────────────────────────────────────
		case 'patch':
			if ( empty( $input['post_id'] ) || empty( $input['_nodeId'] ) || empty( $input['attrs_patch'] ) ) {
				return new WP_Error( 'missing_fields', __( 'post_id, _nodeId, and attrs_patch are required for patch.', 'e2mconnect' ) );
			}
			return e2m_engine_divi_patch_module( $input );

		// ── Materialize node IDs ──────────────────────────────────────────────
		case 'materialize_ids':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for materialize_ids.', 'e2mconnect' ) );
			}
			return e2m_engine_divi_materialize_node_ids( (int) $input['post_id'] );

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: get_schema, list_schemas, patch, materialize_ids.', 'e2mconnect' ) );
	}
}

/**
 * Patch a Divi 5 module's attributes by _nodeId.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_patch_module( array $input ): array|WP_Error {
	$post_id     = (int) $input['post_id'];
	$node_id     = sanitize_text_field( $input['_nodeId'] );
	$attrs_patch = is_array( $input['attrs_patch'] ) ? $input['attrs_patch'] : [];

	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post_id — post not found.', 'e2mconnect' ) );
	}

	if ( class_exists( 'Respira_API' ) ) {
		$request = new WP_REST_Request( 'POST', '/respira/v1/divi/modules/patch' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( '_nodeId', $node_id );
		$request->set_param( 'attrs_patch', $attrs_patch );
		$request->set_param( '_respira_key_data', [ 'id' => 0, 'user_id' => get_current_user_id() ] );

		$api      = new Respira_API();
		$response = $api->patch_divi_module( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		return array_merge( [ 'action' => 'patch' ], is_array( $data ) ? $data : [] );
	}

	// Native fallback — direct block tree manipulation for Divi 5.
	return e2m_engine_divi_patch_module_native( $post_id, $node_id, $attrs_patch );
}

/**
 * Materialize _nodeId UUIDs on a Divi 5 page.
 *
 * @param int $post_id
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_materialize_node_ids( int $post_id ): array|WP_Error {
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post_id — post not found.', 'e2mconnect' ) );
	}

	if ( class_exists( 'Respira_API' ) ) {
		$request = new WP_REST_Request( 'POST', '/respira/v1/divi/modules/materialize-node-ids' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( '_respira_key_data', [ 'id' => 0, 'user_id' => get_current_user_id() ] );

		$api      = new Respira_API();
		$response = $api->materialize_divi_node_ids( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		return array_merge( [ 'action' => 'materialize_ids' ], is_array( $data ) ? $data : [] );
	}

	// Native fallback — walk blocks and add _nodeId where missing.
	return e2m_engine_divi_materialize_ids_native( $post_id );
}

/**
 * Native fallback: patch a Divi 5 block by _nodeId using WP block API.
 *
 * @param int                  $post_id
 * @param string               $node_id
 * @param array<string,mixed>  $attrs_patch
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_patch_module_native( int $post_id, string $node_id, array $attrs_patch ): array|WP_Error {
	$post = get_post( $post_id );
	if ( ! $post || empty( $post->post_content ) ) {
		return new WP_Error( 'no_content', __( 'Post has no content to patch.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error( 'blocks_unavailable', __( 'WordPress block parsing functions not available.', 'e2mconnect' ) );
	}

	$blocks  = parse_blocks( $post->post_content );
	$patched = false;
	$result  = e2m_engine_divi_walk_blocks_patch( $blocks, $node_id, $attrs_patch, $patched );

	if ( ! $patched ) {
		return new WP_Error( 'node_not_found', sprintf( __( 'No Divi 5 block with _nodeId "%s" found on this post.', 'e2mconnect' ), $node_id ) );
	}

	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		[ 'post_content' => serialize_blocks( $result ) ],
		[ 'ID' => $post_id ],
		[ '%s' ],
		[ '%d' ]
	);
	clean_post_cache( $post_id );

	return [
		'action'  => 'patch',
		'post_id' => $post_id,
		'_nodeId' => $node_id,
		'patched' => true,
	];
}

/**
 * Walk Divi 5 blocks recursively and patch the matching _nodeId.
 *
 * @param array<int,array>    $blocks
 * @param string              $node_id
 * @param array<string,mixed> $attrs_patch
 * @param bool                $patched   (by reference)
 * @return array<int,array>
 */
function e2m_engine_divi_walk_blocks_patch( array $blocks, string $node_id, array $attrs_patch, bool &$patched ): array {
	foreach ( $blocks as &$block ) {
		if ( ! empty( $block['attrs']['_nodeId'] ) && $block['attrs']['_nodeId'] === $node_id ) {
			// Apply patch.
			if ( isset( $attrs_patch['set'] ) && is_array( $attrs_patch['set'] ) ) {
				$block['attrs'] = array_merge( $block['attrs'], $attrs_patch['set'] );
			}
			if ( isset( $attrs_patch['merge'] ) && is_array( $attrs_patch['merge'] ) ) {
				$block['attrs'] = array_merge( $block['attrs'], $attrs_patch['merge'] );
			}
			if ( isset( $attrs_patch['unset'] ) && is_array( $attrs_patch['unset'] ) ) {
				foreach ( $attrs_patch['unset'] as $key ) {
					unset( $block['attrs'][ $key ] );
				}
			}
			$patched = true;
			break;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = e2m_engine_divi_walk_blocks_patch( $block['innerBlocks'], $node_id, $attrs_patch, $patched );
			if ( $patched ) {
				break;
			}
		}
	}
	unset( $block );
	return $blocks;
}

/**
 * Native fallback: mint _nodeId UUIDs for all Divi 5 blocks missing one.
 *
 * @param int $post_id
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_materialize_ids_native( int $post_id ): array|WP_Error {
	$post = get_post( $post_id );
	if ( ! $post || empty( $post->post_content ) ) {
		return new WP_Error( 'no_content', __( 'Post has no content to process.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error( 'blocks_unavailable', __( 'WordPress block parsing functions not available.', 'e2mconnect' ) );
	}

	$blocks   = parse_blocks( $post->post_content );
	$minted   = 0;
	$existing = 0;
	$result   = e2m_engine_divi_walk_blocks_materialize( $blocks, $minted, $existing );

	if ( $minted > 0 ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => serialize_blocks( $result ) ],
			[ 'ID' => $post_id ],
			[ '%s' ],
			[ '%d' ]
		);
		clean_post_cache( $post_id );
	}

	return [
		'action'         => 'materialize_ids',
		'post_id'        => $post_id,
		'minted'         => $minted,
		'already_had_id' => $existing,
		'saved'          => $minted > 0,
	];
}

/**
 * Walk blocks recursively and mint _nodeId where missing.
 *
 * @param array<int,array> $blocks
 * @param int $minted   (by reference)
 * @param int $existing (by reference)
 * @return array<int,array>
 */
function e2m_engine_divi_walk_blocks_materialize( array $blocks, int &$minted, int &$existing ): array {
	foreach ( $blocks as &$block ) {
		if ( ! empty( $block['blockName'] ) && str_starts_with( $block['blockName'], 'divi/' ) ) {
			if ( empty( $block['attrs']['_nodeId'] ) ) {
				$block['attrs']['_nodeId'] = e2m_engine_divi_mint_uuid();
				$minted++;
			} else {
				$existing++;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = e2m_engine_divi_walk_blocks_materialize( $block['innerBlocks'], $minted, $existing );
		}
	}
	unset( $block );
	return $blocks;
}
