<?php
/**
 * E2M Connect MCP - Gutenberg Manage Synced Patterns
 *
 * CRUD on the `wp_block` custom post type - WordPress's Synced Pattern
 * (contrast with e2m/gutenberg-manage-patterns, which registers a
 * theme-file-backed pattern that is a plain, unlinked block-markup template,
 * never a post). Inserting a synced pattern into a page emits
 * `<!-- wp:block {"ref":<id>} /-->`, which core's render_block_core_block()
 * resolves live against this post at render time - editing the wp_block
 * post's content updates every page that references it, in place.
 *
 * `wp_block` is a real, core-registered post type (confirmed against
 * wp-includes/post.php: public=>false but show_in_rest=>true, rest_base
 * 'blocks', capability_type 'block' mapped through the ordinary edit_posts/
 * publish_posts capabilities) - CRUD goes through the ordinary
 * wp_insert_post()/wp_update_post()/get_post()/wp_delete_post() functions,
 * the same idiom e2m/gutenberg-manage-global-styles already uses for
 * wp_global_styles.
 *
 * Exposes `post_id` as both an output field (every action) and an optional
 * input field (update/delete) for exactly the same reason
 * manage-global-styles.php does: E2M_Safety_Gatekeeper::extract_target_post_id()
 * and E2M_Risk_Classifier::int_from() pattern-match on the literal INPUT key
 * `post_id` at the REST layer, before this ability's execute_callback runs -
 * passing it back from a prior "read"/"create" engages the automatic
 * pre-write snapshot + scoped DB backup for free. A caller that omits it
 * still gets wp_update_post()'s native post-revision safety net on "update",
 * just not the gatekeeper's snapshot.
 *
 * Pattern Overrides (WP 6.6+, block-bindings source `core/pattern-overrides`,
 * confirmed against wp-includes/block-bindings/pattern-overrides.php and
 * WP_Block::process_block_bindings() in class-wp-block.php) are supported on
 * the "create"/"update" actions via the `overridable_blocks` parameter: for
 * each entry, this ability stamps `metadata.name` + `metadata.bindings` onto
 * the matching block(s) in the parsed tree before re-serializing to
 * post_content. It does NOT write the per-instance override VALUES anywhere
 * - those live on the REFERENCING page's own `<!-- wp:block {"ref":...,
 * "content":{...}} /-->` attribute, a structurally different location this
 * ability has no reason to touch (see this ability's own
 * apply_overridable_blocks() docblock for the exact mechanism).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/gutenberg-manage-synced-patterns', [
	'label'       => __( '[Gutenberg] Manage Synced Patterns', 'e2mconnect' ),
	'description' => __( 'CRUD on wp_block (Synced Patterns) - reusable block content edited in one place, referenced from any page via {"ref":<id>}. Supports WP 6.6+ Pattern Overrides (metadata.bindings -> core/pattern-overrides) so a pattern\'s structure stays single-sourced while text/images vary per instance. Distinct from e2m/gutenberg-manage-patterns, which registers a plain unlinked template pattern, never a post.', 'e2mconnect' ),
	'category'    => 'e2m-gutenberg',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'             => [
				'type'        => 'string',
				'enum'        => [ 'create', 'read', 'update', 'delete', 'list' ],
				'description' => '"create" inserts a new wp_block post. "read" fetches one by post_id. "update" merges/replaces title/content/sync_status on an existing post. "delete" trashes (or, with force:true, permanently deletes) one. "list" returns all wp_block posts (title + id + sync_status only, not full content - use "read" per-post for content).',
			],
			'post_id'            => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Required for "read"/"delete". Optional for "update" (falls back to nothing resolvable - post_id is effectively required there too, listed as optional only to match the literal-input-key convention manage-global-styles.php documents; omitting it on "update" will simply fail with missing_post_id). Passing it back from a prior read/create lets the safety gatekeeper\'s automatic snapshot engage.',
			],
			'title'              => [ 'type' => 'string', 'description' => 'Required for "create". Optional for "update" (only changes it if given).' ],
			'content'            => [ 'type' => 'string', 'description' => 'Required for "create". Raw Gutenberg block markup. Optional for "update".' ],
			'sync_status'        => [
				'type'        => 'string',
				'enum'        => [ 'synced', 'unsynced' ],
				'default'     => 'synced',
				'description' => '"synced" (default) is a fully-synced pattern (core stores this as an EMPTY wp_pattern_sync_status meta value, not the literal string "synced" - this ability translates the friendly value for you). "unsynced" sets wp_pattern_sync_status to "unsynced" (core\'s own UI-level "standard pattern" concept - still a wp_block row, renders identically, the distinction is editor/UX only).',
			],
			'overridable_blocks' => [
				'type'        => 'array',
				'description' => 'Optional, "create"/"update" only. Each entry {block_index_path: int[], name: string, attributes?: string[]} stamps metadata.name=<name> and metadata.bindings on the block found by walking block_index_path (0-based child indices from the tree root, e.g. [0,1] = the tree\'s first block\'s second inner block) in the parsed content tree. attributes, if given, restricts bindings to those specific attribute keys; omitted means the "__default" shorthand (core expands it to every attribute core/pattern-overrides supports for that block type).',
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'block_index_path' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'name'             => [ 'type' => 'string' ],
						'attributes'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
			'force'              => [ 'type' => 'boolean', 'default' => false, 'description' => 'For "delete" only. false (default) moves to trash; true permanently deletes, bypassing trash.' ],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'     => [ 'type' => 'integer' ],
			'title'       => [ 'type' => 'string' ],
			'content'     => [ 'type' => 'string' ],
			'sync_status' => [ 'type' => 'string', 'description' => '"synced" or "unsynced" (translated back from the raw wp_pattern_sync_status meta value).' ],
			'patterns'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Present on "list" - [{post_id, title, sync_status}, ...].' ],
			'validation'  => [
				'type'       => 'object',
				'properties' => [
					'valid'    => [ 'type' => 'boolean' ],
					'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'errors'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_gutenberg_manage_synced_patterns_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Gutenberg: Manage Synced Patterns',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the gutenberg-manage-synced-patterns ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_manage_synced_patterns_ability( array $input ) {
	$action = isset( $input['action'] ) ? (string) $input['action'] : '';
	if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete', 'list' ], true ) ) {
		return new WP_Error( 'invalid_action', __( 'action must be one of: create, read, update, delete, list.', 'e2mconnect' ) );
	}

	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error( 'blocks_unavailable', __( 'This WordPress core does not support the block editor (parse_blocks/serialize_blocks missing).', 'e2mconnect' ) );
	}
	if ( ! post_type_exists( 'wp_block' ) ) {
		return new WP_Error( 'wp_block_unavailable', __( 'The wp_block post type is not registered on this WordPress core.', 'e2mconnect' ) );
	}

	switch ( $action ) {
		case 'list':
			return e2m_engine_gutenberg_list_synced_patterns();
		case 'create':
			return e2m_engine_gutenberg_create_synced_pattern( $input );
		case 'read':
			return e2m_engine_gutenberg_read_synced_pattern( $input );
		case 'update':
			return e2m_engine_gutenberg_update_synced_pattern( $input );
		case 'delete':
			return e2m_engine_gutenberg_delete_synced_pattern( $input );
		default:
			// Unreachable - $action was already validated above.
			return new WP_Error( 'invalid_action', __( 'Unhandled action.', 'e2mconnect' ) );
	}
}

/**
 * Translate the raw wp_pattern_sync_status meta value (''/'unsynced') into
 * the ability's own friendlier 'synced'/'unsynced' output value - core
 * stores "fully synced" as an ABSENT/empty meta value, not the literal
 * string "synced" (confirmed against post.php's meta registration: enum is
 * ['partial', 'unsynced'], "synced" is never a value core itself writes).
 *
 * @param int $post_id
 * @return string
 */
function e2m_engine_gutenberg_read_sync_status( int $post_id ): string {
	$raw = (string) get_post_meta( $post_id, 'wp_pattern_sync_status', true );
	return $raw === 'unsynced' ? 'unsynced' : 'synced';
}

/**
 * List all wp_block posts (title + id + sync_status only - not content, to
 * keep a "list" call cheap; use "read" per-post for content).
 *
 * @return array<string, mixed>
 */
function e2m_engine_gutenberg_list_synced_patterns(): array {
	$posts = get_posts( [
		'post_type'      => 'wp_block',
		'post_status'    => [ 'publish', 'draft', 'private' ],
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$patterns = [];
	foreach ( $posts as $post ) {
		$patterns[] = [
			'post_id'     => (int) $post->ID,
			'title'       => (string) $post->post_title,
			'sync_status' => e2m_engine_gutenberg_read_sync_status( (int) $post->ID ),
		];
	}

	return [ 'patterns' => $patterns ];
}

/**
 * Read one wp_block post by id.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_read_synced_pattern( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "read".', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'wp_block' ) {
		return new WP_Error( 'pattern_not_found', __( 'No wp_block post exists with the given post_id.', 'e2mconnect' ) );
	}

	return [
		'post_id'     => (int) $post->ID,
		'title'       => (string) $post->post_title,
		'content'     => (string) $post->post_content,
		'sync_status' => e2m_engine_gutenberg_read_sync_status( (int) $post->ID ),
		'validation'  => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Create a new wp_block post.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_create_synced_pattern( array $input ) {
	if ( empty( $input['title'] ) || ! is_string( $input['title'] ) ) {
		return new WP_Error( 'missing_title', __( 'title is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}
	if ( empty( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return new WP_Error( 'missing_content', __( 'content is required and must be a non-empty string for "create".', 'e2mconnect' ) );
	}

	$content    = e2m_engine_gutenberg_apply_overridable_blocks( (string) $input['content'], $input['overridable_blocks'] ?? [] );
	if ( is_wp_error( $content ) ) {
		return $content;
	}
	$validation = e2m_engine_gutenberg_validate_synced_pattern_content( $content );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'pattern_content_invalid',
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
			'post_type'    => 'wp_block',
			'post_title'   => wp_strip_all_tags( (string) $input['title'] ),
			'post_content' => wp_slash( $content ),
			'post_status'  => 'publish',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$sync_status = ( $input['sync_status'] ?? 'synced' ) === 'unsynced' ? 'unsynced' : 'synced';
	if ( $sync_status === 'unsynced' ) {
		update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
	}

	return [
		'post_id'     => (int) $post_id,
		'title'       => (string) $input['title'],
		'content'     => $content,
		'sync_status' => $sync_status,
		'validation'  => $validation,
	];
}

/**
 * Update an existing wp_block post - title/content/sync_status, whichever
 * are given. content, if given, fully replaces the existing post_content
 * (this ability does not merge block trees the way manage-theme-json.php
 * merges JSON documents - a block tree has no well-defined "merge" semantic
 * the way a settings object does; the caller must supply the complete
 * intended content).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_update_synced_pattern( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "update".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_block' ) {
		return new WP_Error( 'pattern_not_found', __( 'No wp_block post exists with the given post_id.', 'e2mconnect' ) );
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
		$content = e2m_engine_gutenberg_apply_overridable_blocks( (string) $input['content'], $input['overridable_blocks'] ?? [] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$validation = e2m_engine_gutenberg_validate_synced_pattern_content( $content );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'pattern_content_invalid',
				sprintf(
					/* translators: %s: joined validation error messages */
					__( 'content failed validation: %s', 'e2mconnect' ),
					implode( '; ', $validation['errors'] )
				),
				[ 'validation' => $validation ]
			);
		}
		$update_args['post_content'] = wp_slash( $content );
		$resulting_content           = $content;
	} elseif ( ! empty( $input['overridable_blocks'] ) ) {
		// overridable_blocks was given without a fresh content payload -
		// apply the binding stamps to the EXISTING content instead of
		// requiring the caller to resend the whole tree just to add bindings.
		$content = e2m_engine_gutenberg_apply_overridable_blocks( $resulting_content, $input['overridable_blocks'] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$update_args['post_content'] = wp_slash( $content );
		$resulting_content           = $content;
	}

	if ( count( $update_args ) > 1 ) {
		// Only dispatch wp_update_post when something besides ID actually
		// changed - an update call with just sync_status should not touch
		// post_content/post_title and trigger a spurious revision.
		$updated = wp_update_post( $update_args, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	$sync_status = e2m_engine_gutenberg_read_sync_status( $post_id );
	if ( isset( $input['sync_status'] ) ) {
		$sync_status = (string) $input['sync_status'] === 'unsynced' ? 'unsynced' : 'synced';
		if ( $sync_status === 'unsynced' ) {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		}
	}

	return [
		'post_id'     => $post_id,
		'title'       => isset( $update_args['post_title'] ) ? (string) $update_args['post_title'] : (string) $existing->post_title,
		'content'     => $resulting_content,
		'sync_status' => $sync_status,
		'validation'  => $validation,
	];
}

/**
 * Delete (trash by default, permanently with force:true) a wp_block post.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_gutenberg_delete_synced_pattern( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required for "delete".', 'e2mconnect' ) );
	}

	$existing = get_post( $post_id );
	if ( ! $existing || $existing->post_type !== 'wp_block' ) {
		return new WP_Error( 'pattern_not_found', __( 'No wp_block post exists with the given post_id.', 'e2mconnect' ) );
	}

	$force  = ! empty( $input['force'] );
	$result = wp_delete_post( $post_id, $force );
	if ( ! $result ) {
		return new WP_Error( 'pattern_delete_failed', __( 'Failed to delete the wp_block post.', 'e2mconnect' ) );
	}

	return [
		'post_id'    => $post_id,
		'validation' => [ 'valid' => true, 'warnings' => [], 'errors' => [] ],
	];
}

/**
 * Stamp metadata.name + metadata.bindings onto blocks located by their
 * index path in the parsed content tree, then re-serialize. This is what
 * makes a block within the synced pattern ELIGIBLE for Pattern Overrides -
 * it does not itself apply any override VALUE. A referencing page supplies
 * override values via its own `<!-- wp:block {"ref":<id>,"content":{...}}
 * /-->` attribute at insert time (core/block's own "content" attribute,
 * keyed by each inner block's metadata.name) - this ability never writes to
 * a referencing page, so it has no reason to touch that attribute.
 *
 * `attributes`, when given, becomes an explicit per-attribute bindings map
 * (`{attr: {source: 'core/pattern-overrides'}}` per entry) matching
 * WP_Block::process_block_bindings()'s literal-per-attribute shape.
 * Omitted, it becomes the WP 6.6+ `__default` shorthand
 * (`{__default: {source: 'core/pattern-overrides'}}`), which core itself
 * expands to every attribute core/pattern-overrides supports for that
 * block's registered type (confirmed against
 * get_block_bindings_supported_attributes()'s hardcoded map in
 * wp-includes/block-bindings.php) - this ability does not need its own copy
 * of that map since core expands the shorthand at render/edit time, not at
 * storage time.
 *
 * @param string $content
 * @param array<int, array<string, mixed>> $overridable_blocks
 * @return string|WP_Error
 */
function e2m_engine_gutenberg_apply_overridable_blocks( string $content, array $overridable_blocks ) {
	if ( empty( $overridable_blocks ) ) {
		return $content;
	}

	$blocks = parse_blocks( $content );

	foreach ( $overridable_blocks as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['name'] ) || ! isset( $entry['block_index_path'] ) || ! is_array( $entry['block_index_path'] ) ) {
			return new WP_Error( 'invalid_overridable_block', __( 'Each overridable_blocks entry needs block_index_path (int[]) and name (string).', 'e2mconnect' ) );
		}

		$path = array_map( 'intval', $entry['block_index_path'] );
		$name = (string) $entry['name'];

		$bindings = [];
		if ( ! empty( $entry['attributes'] ) && is_array( $entry['attributes'] ) ) {
			foreach ( $entry['attributes'] as $attribute_name ) {
				$bindings[ (string) $attribute_name ] = [ 'source' => 'core/pattern-overrides' ];
			}
		} else {
			$bindings['__default'] = [ 'source' => 'core/pattern-overrides' ];
		}

		$applied = e2m_engine_gutenberg_stamp_block_metadata( $blocks, $path, $name, $bindings );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
	}

	return serialize_blocks( $blocks );
}

/**
 * Walk a parsed block tree by index path (0-based child indices at each
 * depth) and merge {name, bindings} into the target block's
 * attrs['metadata']. Modifies $blocks in place (passed by reference) since
 * parse_blocks() returns a plain nested array, not objects.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @param int[] $path
 * @param string $name
 * @param array<string, mixed> $bindings
 * @return true|WP_Error
 */
function e2m_engine_gutenberg_stamp_block_metadata( array &$blocks, array $path, string $name, array $bindings ) {
	$cursor = &$blocks;
	$depth  = count( $path );

	for ( $i = 0; $i < $depth; $i++ ) {
		$index = $path[ $i ];
		if ( ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
			return new WP_Error(
				'block_index_path_not_found',
				sprintf(
					/* translators: %s: JSON-encoded index path */
					__( 'block_index_path %s does not resolve to a real block in the content tree.', 'e2mconnect' ),
					wp_json_encode( $path )
				)
			);
		}

		if ( $i === $depth - 1 ) {
			if ( ! isset( $cursor[ $index ]['attrs'] ) || ! is_array( $cursor[ $index ]['attrs'] ) ) {
				$cursor[ $index ]['attrs'] = [];
			}
			$existing_metadata               = $cursor[ $index ]['attrs']['metadata'] ?? [];
			$cursor[ $index ]['attrs']['metadata'] = array_merge(
				is_array( $existing_metadata ) ? $existing_metadata : [],
				[ 'name' => $name, 'bindings' => $bindings ]
			);
			return true;
		}

		if ( ! isset( $cursor[ $index ]['innerBlocks'] ) || ! is_array( $cursor[ $index ]['innerBlocks'] ) ) {
			return new WP_Error(
				'block_index_path_not_found',
				sprintf(
					/* translators: %s: JSON-encoded index path */
					__( 'block_index_path %s descends into a block with no innerBlocks at that depth.', 'e2mconnect' ),
					wp_json_encode( $path )
				)
			);
		}
		$cursor = &$cursor[ $index ]['innerBlocks'];
	}

	// Unreachable - depth 0 (empty path) is rejected by the caller's own
	// validation before this function is called (an empty path can't
	// identify a specific block to stamp).
	return new WP_Error( 'empty_block_index_path', __( 'block_index_path must not be empty.', 'e2mconnect' ) );
}

/**
 * This ability's own explicit structural validation for wp_block content -
 * conservative, mirrors e2m/gutenberg-validate-blocks' round-trip check
 * (parse then re-serialize must stably reproduce non-whitespace-different
 * markup) without duplicating that ability's full registry/attribute-schema
 * checks - a caller who wants the FULL validate-blocks gate (registry
 * resolution, attribute-schema conformance) should also call
 * e2m/gutenberg-validate-blocks with this content as block_markup before
 * create/update, the same "nothing else writes safely without it" relationship
 * that ability documents for every other Gutenberg-writing ability.
 *
 * @param string $content
 * @return array{valid: bool, warnings: string[], errors: string[]}
 */
function e2m_engine_gutenberg_validate_synced_pattern_content( string $content ): array {
	$errors   = [];
	$warnings = [];

	$parsed      = parse_blocks( $content );
	$reserialized = serialize_blocks( $parsed );
	if ( trim( (string) $reserialized ) === '' && trim( $content ) !== '' ) {
		$errors[] = 'content did not parse into any recognizable block - check for malformed block comment delimiters.';
	}

	$warnings[] = 'This ability checks parse/serialize round-trip stability only. Call e2m/gutenberg-validate-blocks with this content as block_markup for the full registry-resolution and attribute-schema gate before create/update on a page that will actually render it.';

	return [
		'valid'    => empty( $errors ),
		'warnings' => $warnings,
		'errors'   => $errors,
	];
}
