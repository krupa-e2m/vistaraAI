<?php
/**
 * E2M Connect MCP – Divi Manage Theme Builder
 *
 * Programmatically manage Divi Theme Builder layouts (et_header_layout,
 * et_footer_layout, et_body_layout CPTs) — the same layouts a human creates
 * via Divi → Theme Builder in the WP admin.
 *
 * Actions:
 *   list    — list all Theme Builder layouts (all kinds or filtered)
 *   get     — get a single layout with its content and assignments
 *   create  — create a new header / footer / body layout
 *   update  — update title, content, or assignments of an existing layout
 *   delete  — trash a Theme Builder layout
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/divi-manage-theme-builder', [
	'label'       => __( '[Divi] Manage Theme Builder', 'e2mconnect' ),
	'description' => 'Full CRUD for Divi Theme Builder layouts: list, get, create, update, delete. Supports header, footer, and body layout types with display-condition assignments.',
	'category'    => 'e2m-divi',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			// list
			'kind' => [
				'type'        => 'string',
				'enum'        => [ 'header', 'footer', 'body', 'all' ],
				'description' => 'Layout kind to filter on. "all" returns all kinds. Default: all.',
				'default'     => 'all',
			],
			// get / update / delete
			'post_id' => [ 'type' => 'integer', 'description' => 'Layout post ID. Required for get, update, delete.' ],
			// create / update
			'name'        => [ 'type' => 'string', 'description' => 'Layout name/title. Required for create.' ],
			'content'     => [ 'type' => 'string', 'description' => 'Shortcode or block content for the layout.' ],
			'assignments' => [
				'type'                 => 'object',
				'description'          => 'Display-condition assignments object. See Divi Theme Builder docs for structure.',
				'additionalProperties' => true,
			],
			'structure' => [
				'description' => 'Optional page builder structure (array) to inject into the new layout.',
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'  => [ 'type' => 'string' ],
			'layouts' => [ 'type' => 'array' ],
			'total'   => [ 'type' => 'integer' ],
			'post_id' => [ 'type' => 'integer' ],
			'kind'    => [ 'type' => 'string' ],
			'name'    => [ 'type' => 'string' ],
			'content' => [ 'description' => 'Layout content.' ],
			'created' => [ 'type' => 'boolean' ],
			'updated' => [ 'type' => 'boolean' ],
			'deleted' => [ 'type' => 'boolean' ],
			'warnings'=> [ 'type' => 'array' ],
		],
	],

	'execute_callback'    => 'e2m_engine_divi_manage_theme_builder',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Divi: Manage Theme Builder',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute divi-manage-theme-builder ability.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_engine_divi_manage_theme_builder( array $input ): array|WP_Error {
	if ( ! e2m_engine_has_divi() ) {
		return new WP_Error( 'divi_missing', __( 'Divi theme or Divi Builder plugin is not active on this site.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] );

	switch ( $action ) {

		// ── List ──────────────────────────────────────────────────────────────
		case 'list':
			$kind_filter = $input['kind'] ?? 'all';
			$post_types  = [];

			if ( $kind_filter === 'all' || $kind_filter === 'header' ) {
				$post_types[] = 'et_header_layout';
			}
			if ( $kind_filter === 'all' || $kind_filter === 'footer' ) {
				$post_types[] = 'et_footer_layout';
			}
			if ( $kind_filter === 'all' || $kind_filter === 'body' ) {
				$post_types[] = 'et_body_layout';
			}

			if ( empty( $post_types ) ) {
				return new WP_Error( 'invalid_kind', __( 'kind must be "header", "footer", "body", or "all".', 'e2mconnect' ) );
			}

			$query   = new WP_Query( [
				'post_type'      => $post_types,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			$layouts = [];
			foreach ( $query->posts as $post ) {
				$layouts[] = [
					'post_id'  => $post->ID,
					'name'     => $post->post_title,
					'kind'     => e2m_engine_divi_layout_post_type_to_kind( $post->post_type ),
					'status'   => $post->post_status,
					'modified' => $post->post_modified,
				];
			}
			return [
				'action'  => 'list',
				'layouts' => $layouts,
				'total'   => (int) $query->found_posts,
			];

		// ── Get ───────────────────────────────────────────────────────────────
		case 'get':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for get.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', __( 'Layout not found.', 'e2mconnect' ) );
			}
			$kind = e2m_engine_divi_layout_post_type_to_kind( $post->post_type );
			if ( ! $kind ) {
				return new WP_Error( 'wrong_type', __( 'The specified post is not a Divi Theme Builder layout.', 'e2mconnect' ) );
			}
			return [
				'action'  => 'get',
				'post_id' => $post_id,
				'name'    => $post->post_title,
				'kind'    => $kind,
				'content' => $post->post_content,
				'status'  => $post->post_status,
			];

		// ── Create ────────────────────────────────────────────────────────────
		case 'create':
			if ( empty( $input['name'] ) ) {
				return new WP_Error( 'missing_name', __( 'name is required for create.', 'e2mconnect' ) );
			}
			$kind_raw = sanitize_key( $input['kind'] ?? 'header' );
			if ( ! in_array( $kind_raw, [ 'header', 'footer', 'body' ], true ) ) {
				return new WP_Error( 'invalid_kind', __( 'kind must be "header", "footer", or "body".', 'e2mconnect' ) );
			}

			// Try Respira_API for full Theme Builder support (assignments etc).
			if ( class_exists( 'Respira_API' ) ) {
				$request = new WP_REST_Request( 'POST', '/respira/v1/divi/theme-builder/layout' );
				$request->set_param( 'kind', $kind_raw );
				$request->set_param( 'name', sanitize_text_field( $input['name'] ) );
				$request->set_param( '_respira_key_data', [ 'id' => 0, 'user_id' => get_current_user_id() ] );
				if ( isset( $input['assignments'] ) ) {
					$request->set_param( 'assignments', $input['assignments'] );
				}
				if ( isset( $input['structure'] ) ) {
					$request->set_param( 'structure', $input['structure'] );
				}
				$api      = new Respira_API();
				$response = $api->create_divi_theme_builder_layout( $request );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
				return array_merge( [ 'action' => 'create' ], is_array( $data ) ? $data : [] );
			}

			// Native fallback.
			$post_type = 'et_' . $kind_raw . '_layout';
			$post_id   = wp_insert_post( [
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $input['name'] ),
				'post_content' => wp_kses_post( $input['content'] ?? '' ),
			], true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			return [
				'action'  => 'create',
				'post_id' => $post_id,
				'kind'    => $kind_raw,
				'name'    => get_the_title( $post_id ),
				'created' => true,
			];

		// ── Update ────────────────────────────────────────────────────────────
		case 'update':
			if ( empty( $input['post_id'] ) ) {
				return new WP_Error( 'missing_post_id', __( 'post_id is required for update.', 'e2mconnect' ) );
			}
			$post_id = (int) $input['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post || ! e2m_engine_divi_layout_post_type_to_kind( $post->post_type ) ) {
				return new WP_Error( 'not_found', __( 'Theme Builder layout not found.', 'e2mconnect' ) );
			}
			$update = [ 'ID' => $post_id ];
			if ( ! empty( $input['name'] ) ) {
				$update['post_title'] = sanitize_text_field( $input['name'] );
			}
			if ( isset( $input['content'] ) ) {
				$update['post_content'] = wp_kses_post( $input['content'] );
			}
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
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
			if ( ! $post || ! e2m_engine_divi_layout_post_type_to_kind( $post->post_type ) ) {
				return new WP_Error( 'not_found', __( 'Theme Builder layout not found.', 'e2mconnect' ) );
			}
			if ( ! wp_trash_post( $post_id ) ) {
				return new WP_Error( 'delete_failed', __( 'Failed to trash the Theme Builder layout.', 'e2mconnect' ) );
			}
			return [
				'action'  => 'delete',
				'post_id' => $post_id,
				'deleted' => true,
			];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: list, get, create, update, delete.', 'e2mconnect' ) );
	}
}

/**
 * Convert a Divi Theme Builder post type to its kind string.
 *
 * @param string $post_type
 * @return string|null  "header", "footer", "body", or null if not a TB post type.
 */
function e2m_engine_divi_layout_post_type_to_kind( string $post_type ): ?string {
	return match ( $post_type ) {
		'et_header_layout' => 'header',
		'et_footer_layout' => 'footer',
		'et_body_layout'   => 'body',
		default            => null,
	};
}
