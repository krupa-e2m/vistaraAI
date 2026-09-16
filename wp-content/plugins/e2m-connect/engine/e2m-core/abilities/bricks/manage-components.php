<?php
/**
 * E2M Connect MCP - Bricks Manage Components
 *
 * Create and manage Bricks reusable components (templates) that can be
 * inserted across multiple pages. Bricks stores reusable components as
 * posts of type `bricks_template` with type "section" or "block".
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-components', [
	'label'       => __( '[Bricks] Manage Components', 'e2mconnect' ),
	'description' => 'Create, list, update, and delete Bricks reusable components (templates) that can be reused across pages.',
	'category'    => 'e2m-bricks',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'get', 'create', 'update', 'delete' ],
				'description' => 'Operation to perform.',
			],
			'component_id' => [
				'type'        => 'integer',
				'description' => 'Post ID of the Bricks component (for get, update, delete).',
			],
			'title' => [
				'type'        => 'string',
				'description' => 'Component title.',
			],
			'type' => [
				'type'        => 'string',
				'enum'        => [ 'section', 'block', 'header', 'footer', 'popup' ],
				'description' => 'Bricks template type. Defaults to "section".',
				'default'     => 'section',
			],
			'elements' => [
				'type'        => 'array',
				'description' => 'Bricks elements array to store as the component content.',
				'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'tags' => [
				'type'        => 'array',
				'description' => 'Optional tags for categorising the component.',
				'items'       => [ 'type' => 'string' ],
			],
		],
		'required'             => [ 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'     => [ 'type' => 'string' ],
			'components' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'component'  => [ 'type' => 'object', 'additionalProperties' => true ],
			'deleted'    => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_bricks_manage_components_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Components',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-components ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_components_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to manage Bricks components.', 'e2mconnect' ) );
	}

	$action = sanitize_key( $input['action'] ?? 'list' );

	switch ( $action ) {
		case 'list':
			$posts = get_posts( [
				'post_type'      => 'bricks_template',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
			] );
			$components = array_map( fn( $p ) => [
				'id'    => $p->ID,
				'title' => $p->post_title,
				'type'  => get_post_meta( $p->ID, '_bricks_template_type', true ) ?: 'section',
				'slug'  => $p->post_name,
			], $posts );
			return [ 'action' => 'list', 'components' => $components ];

		case 'get':
			$id = (int) ( $input['component_id'] ?? 0 );
			if ( $id <= 0 ) {
				return new WP_Error( 'missing_id', __( 'component_id is required.', 'e2mconnect' ) );
			}
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'bricks_template' ) {
				return new WP_Error( 'not_found', __( 'Bricks component not found.', 'e2mconnect' ) );
			}
			$elements = get_post_meta( $id, '_bricks_page_content_2', true );
			return [
				'action'    => 'get',
				'component' => [
					'id'       => $post->ID,
					'title'    => $post->post_title,
					'type'     => get_post_meta( $id, '_bricks_template_type', true ) ?: 'section',
					'elements' => is_array( $elements ) ? $elements : [],
				],
			];

		case 'create':
			if ( empty( $input['title'] ) ) {
				return new WP_Error( 'missing_title', __( 'A title is required to create a component.', 'e2mconnect' ) );
			}
			$type     = sanitize_key( $input['type'] ?? 'section' );
			$elements = is_array( $input['elements'] ?? null ) ? $input['elements'] : [];
			$tags     = array_map( 'sanitize_text_field', (array) ( $input['tags'] ?? [] ) );

			$post_id = wp_insert_post( [
				'post_title'  => sanitize_text_field( $input['title'] ),
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
				'tags_input'  => $tags,
			] );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			update_post_meta( $post_id, '_bricks_template_type', $type );
			update_post_meta( $post_id, '_bricks_page_content_2', $elements );
			update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

			return [
				'action'    => 'create',
				'component' => [
					'id'    => $post_id,
					'title' => get_the_title( $post_id ),
					'type'  => $type,
				],
			];

		case 'update':
			$id = (int) ( $input['component_id'] ?? 0 );
			if ( $id <= 0 ) {
				return new WP_Error( 'missing_id', __( 'component_id is required to update a component.', 'e2mconnect' ) );
			}
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'bricks_template' ) {
				return new WP_Error( 'not_found', __( 'Bricks component not found.', 'e2mconnect' ) );
			}
			$update_data = [ 'ID' => $id ];
			if ( isset( $input['title'] ) ) {
				$update_data['post_title'] = sanitize_text_field( $input['title'] );
			}
			wp_update_post( $update_data );
			if ( isset( $input['elements'] ) && is_array( $input['elements'] ) ) {
				update_post_meta( $id, '_bricks_page_content_2', $input['elements'] );
			}
			if ( isset( $input['type'] ) ) {
				update_post_meta( $id, '_bricks_template_type', sanitize_key( $input['type'] ) );
			}
			return [
				'action'    => 'update',
				'component' => [ 'id' => $id, 'title' => get_the_title( $id ) ],
			];

		case 'delete':
			$id = (int) ( $input['component_id'] ?? 0 );
			if ( $id <= 0 ) {
				return new WP_Error( 'missing_id', __( 'component_id is required to delete a component.', 'e2mconnect' ) );
			}
			$result = wp_delete_post( $id, true );
			return [ 'action' => 'delete', 'deleted' => (bool) $result ];

		default:
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use list, get, create, update, or delete.', 'e2mconnect' ) );
	}
}
