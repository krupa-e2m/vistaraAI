<?php
/**
 * E2M Connect MCP – Divi Manage Global Modules
 *
 * Full CRUD for Divi global modules (et_pb_layout CPT) plus
 * inline resolution of global_module references in a content tree.
 *
 * Actions:
 *   list       — list all Divi global modules (et_pb_layout posts)
 *   get        — get a single global module with its parsed content
 *   create     — create a new global module post
 *   update     — update title / content of an existing global module
 *   delete     — trash a global module post
 *   resolve    — expand global_module="ID" refs in a provided module tree
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/divi-manage-global-modules', [
	'label'       => __( '[Divi] Manage Global Modules', 'e2mconnect' ),
	'description' => 'Full CRUD for Divi global modules (et_pb_layout CPT): list, get, create, update, delete, and resolve global_module references inline in a content tree.',
	'category'    => 'e2m-divi',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete', 'resolve' ],
				'description' => 'Operation to perform.',
			],
			// list
			'per_page' => [ 'type' => 'integer', 'description' => 'Max results for list. Default: 50.', 'default' => 50 ],
			'search'   => [ 'type' => 'string', 'description' => 'Title search term for list.' ],
			// get / update / delete
			'post_id'  => [ 'type' => 'integer', 'description' => 'Global module post ID. Required for get, update, delete.' ],
			// create / update
			'title'   => [ 'type' => 'string', 'description' => 'Module title. Required for create.' ],
			'content' => [ 'type' => 'string', 'description' => 'Module shortcode or block content.' ],
			'layout_type' => [
				'type'        => 'string',
				'enum'        => [ 'module', 'row', 'section', 'fullwidth_module', 'fullwidth_section' ],
				'description' => 'Divi layout type meta (_et_pb_layout_type). Default: module.',
				'default'     => 'module',
			],
			// resolve
			'modules' => [
				'type'        => 'array',
				'description' => 'Module tree (from extract_builder_content) for the resolve action.',
				'items'       => [ 'type' => 'object' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'         => [ 'type' => 'string' ],
			'global_modules' => [ 'type' => 'array' ],
			'total'          => [ 'type' => 'integer' ],
			'post_id'        => [ 'type' => 'integer' ],
			'title'          => [ 'type' => 'string' ],
			'content'        => [ 'description' => 'Module content or parsed structure.' ],
			'created'        => [ 'type' => 'boolean' ],
			'updated'        => [ 'type' => 'boolean' ],
			'deleted'        => [ 'type' => 'boolean' ],
			'modules'        => [ 'type' => 'array' ],
			'resolved'       => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_divi_manage_global_modules',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Divi: Manage Global Modules',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute divi-manage-global-modules ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_manage_global_modules( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_divi() ) {
		return new WP_Error( 'divi_missing', __( 'Divi theme or Divi Builder plugin is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$per_page = max( 1, (int) ( $input['per_page'] ?? 50 ) );
			$search   = sanitize_text_field( $input['search'] ?? '' );

			$args = [
				'post_type'      => 'et_pb_layout',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'orderby'        => 'title',
				'order'          => 'ASC',
			];
			if ( $search !== '' ) {
				$args['s'] = $search;
			}

			$query   = new WP_Query( $args );
			$modules = [];
			foreach ( $query->posts as $post ) {
				$modules[] = [
					'post_id'     => $post->ID,
					'title'       => $post->post_title,
					'slug'        => $post->post_name,
					'layout_type' => get_post_meta( $post->ID, '_et_pb_layout_type', true ) ?: 'module',
					'modified'    => $post->post_modified,
				];
			}
			return [
				'action'         => 'list',
				'global_modules' => $modules,
				'total'          => (int) $query->found_posts,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for get.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', __( 'Global module post not found.', 'e2mconnect' ) );
			}
			if ( 'et_pb_layout' !== $post->post_type ) {
				return new WP_Error( 'wrong_type', __( 'The specified post is not a Divi global module (et_pb_layout).', 'e2mconnect' ) );
			}

			$content = $post->post_content;
			// Use Respira_Divi_Global_Modules if available for parsed structure.
			if ( class_exists( 'Respira_Divi_Global_Modules' ) ) {
				$parsed = Respira_Divi_Global_Modules::get_global_module_content( $post_id );
				if ( $parsed !== false ) {
					$content = $parsed;
				}
			}

			return [
				'action'      => 'get',
				'post_id'     => $post_id,
				'title'       => $post->post_title,
				'slug'        => $post->post_name,
				'layout_type' => get_post_meta( $post_id, '_et_pb_layout_type', true ) ?: 'module',
				'content'     => $content,
				'modified'    => $post->post_modified,
			];

		// ── Create ────────────────────────────────────────────────────────────
		case 'create':
			if ( empty( $input['title'] ) ) {
				return new WP_Error( 'missing_title', __( 'title is required for create.', 'e2mconnect' ) );
			}
			$post_id = wp_insert_post( [
				'post_type'    => 'et_pb_layout',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $input['title'] ),
				'post_content' => wp_kses_post( $input['content'] ?? '' ),
			], true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$layout_type = in_array( $input['layout_type'] ?? 'module', [ 'module', 'row', 'section', 'fullwidth_module', 'fullwidth_section' ], true )
				? $input['layout_type']
				: 'module';
			update_post_meta( $post_id, '_et_pb_layout_type', $layout_type );
			// Mark as a global module.
			update_post_meta( $post_id, '_et_pb_built_for_post_type', 'page' );

			return [
				'action'  => 'create',
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'created' => true,
			];

		// ── Update ────────────────────────────────────────────────────────────
		case 'update':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for update.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Global module not found.', 'e2mconnect' ) );
			}

			$update_data = [ 'ID' => $post_id ];
			if ( ! empty( $input['title'] ) ) {
				$update_data['post_title'] = sanitize_text_field( $input['title'] );
			}
			if ( isset( $input['content'] ) ) {
				$update_data['post_content'] = wp_kses_post( $input['content'] );
			}
			$result = wp_update_post( $update_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $input['layout_type'] ) ) {
				update_post_meta( $post_id, '_et_pb_layout_type', sanitize_key( $input['layout_type'] ) );
			}

			return [
				'action'  => 'update',
				'post_id' => $post_id,
				'updated' => true,
			];

		// ── Delete ────────────────────────────────────────────────────────────
		case 'delete':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for delete.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Global module not found.', 'e2mconnect' ) );
			}

			$result = wp_trash_post( $post_id );
			if ( ! $result ) {
				return new WP_Error( 'delete_failed', __( 'Failed to trash the global module.', 'e2mconnect' ) );
			}
			return [
				'action'  => 'delete',
				'post_id' => $post_id,
				'deleted' => true,
			];

		// ── Resolve global refs ────────────────────────────────────────────────
		case 'resolve':
			if ( ! isset( $input['modules'] ) || ! is_array( $input['modules'] ) ) {
				return new WP_Error( 'missing_modules', __( '"modules" array is required for resolve.', 'e2mconnect' ) );
			}
			$modules = $input['modules'];

			if ( class_exists( 'Respira_Divi_Global_Modules' ) ) {
				$resolved = Respira_Divi_Global_Modules::resolve_global_modules( $modules );
			} else {
				$resolved = e2m_engine_divi_resolve_global_refs_native( $modules );
			}

			$count = e2m_engine_divi_count_resolved_refs( $modules, $resolved );

			return [
				'action'   => 'resolve',
				'modules'  => $resolved,
				'resolved' => $count,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, create, update, delete, resolve.', 'e2mconnect' ) );
	}
}

/**
 * Native fallback: resolve global_module refs by fetching post content directly.
 *
 * @param array<int,array> $modules
 * @return array<int,array>
 */
function e2m_engine_divi_resolve_global_refs_native( array $modules ): array {
	$resolved = [];
	foreach ( $modules as $module ) {
		$attrs     = $module['attributes'] ?? $module['attrs'] ?? [];
		$global_id = ! empty( $attrs['global_module'] ) && is_numeric( $attrs['global_module'] )
			? (int) $attrs['global_module']
			: null;

		if ( $global_id ) {
			$post = get_post( $global_id );
			if ( $post && $post->post_content ) {
				// Replace global_module ref with raw content.
				unset( $module['attributes']['global_module'], $module['attrs']['global_module'] );
				$module['content']   = $post->post_content;
				$module['_resolved_from'] = $global_id;
			}
		}

		if ( ! empty( $module['children'] ) ) {
			$module['children'] = e2m_engine_divi_resolve_global_refs_native( $module['children'] );
		}

		$resolved[] = $module;
	}
	return $resolved;
}

/**
 * Count how many global_module refs were resolved.
 *
 * @param array<int,array> $original
 * @param array<int,array> $resolved
 * @return int
 */
function e2m_engine_divi_count_resolved_refs( array $original, array $resolved ): int {
	$count = 0;
	foreach ( $original as $i => $mod ) {
		$orig_attrs = $mod['attributes'] ?? $mod['attrs'] ?? [];
		$res_attrs  = $resolved[ $i ]['attributes'] ?? $resolved[ $i ]['attrs'] ?? [];
		if ( ! empty( $orig_attrs['global_module'] ) && empty( $res_attrs['global_module'] ) ) {
			$count++;
		}
		$orig_children = $mod['children'] ?? [];
		$res_children  = $resolved[ $i ]['children'] ?? [];
		if ( ! empty( $orig_children ) ) {
			$count += e2m_engine_divi_count_resolved_refs( $orig_children, $res_children );
		}
	}
	return $count;
}
