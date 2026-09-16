<?php
/**
 * E2M Connect MCP - Create Custom Post
 *
 * Creates an entry in any registered post type. Also accepts a meta map so
 * agents can populate ACF/Meta Box/SCF fields in the same call instead of
 * round-tripping through a separate update-meta operation.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-custom-post', [
	'label'       => __( '[CPT] Create Post', 'e2mconnect' ),
	'description' => 'Creates an entry in any registered post type. Meta fields can be supplied inline.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_type' => [ 'type' => 'string', 'minLength' => 1 ],
			'title'     => [ 'type' => 'string', 'minLength' => 1 ],
			'content'   => [ 'type' => 'string', 'default' => '' ],
			'excerpt'   => [ 'type' => 'string', 'default' => '' ],
			'status'    => [ 'type' => 'string', 'default' => 'draft' ],
			'slug'      => [ 'type' => 'string' ],
			'author_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'meta'      => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Map of meta_key => value.' ],
			'parent'    => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Parent post ID for hierarchical types.' ],
		],
		'required'             => [ 'post_type', 'title' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'type'      => [ 'type' => 'string' ],
			'permalink' => [ 'type' => 'string' ],
			'edit_url'  => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_custom_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Custom Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-custom-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_custom_post_ability( array $input ) {
	$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
	if ( $post_type === '' || ! post_type_exists( $post_type ) ) {
		return new WP_Error( 'invalid_post_type', __( 'Unknown post_type.', 'e2mconnect' ) );
	}

	$title = isset( $input['title'] ) ? trim( sanitize_text_field( (string) $input['title'] ) ) : '';
	if ( $title === '' ) {
		return new WP_Error( 'invalid_title', __( 'Title is required.', 'e2mconnect' ) );
	}

	$postarr = [
		'post_type'    => $post_type,
		'post_title'   => $title,
		'post_content' => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
		'post_excerpt' => isset( $input['excerpt'] ) ? wp_kses_post( (string) $input['excerpt'] ) : '',
		'post_status'  => isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft',
	];

	if ( ! empty( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
	}
	if ( ! empty( $input['author_id'] ) ) {
		$postarr['post_author'] = (int) $input['author_id'];
	}
	if ( isset( $input['parent'] ) ) {
		$postarr['post_parent'] = (int) $input['parent'];
	}

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
		foreach ( $input['meta'] as $key => $value ) {
			update_post_meta( (int) $post_id, sanitize_key( (string) $key ), $value );
		}
	}

	return [
		'post_id'   => (int) $post_id,
		'type'      => $post_type,
		'permalink' => (string) get_permalink( (int) $post_id ),
		'edit_url'  => (string) get_edit_post_link( (int) $post_id, 'raw' ),
	];
}
