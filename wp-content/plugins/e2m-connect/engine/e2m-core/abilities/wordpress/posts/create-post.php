<?php
/**
 * E2M Connect MCP - Create Post
 *
 * Creates a WordPress blog post with title, content, status, categories, tags,
 * excerpt and featured image. Terms are accepted as ID arrays to keep the MCP
 * schema predictable across sites with different term vocabularies.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-post', [
	'label'       => __( '[Post] Create Post', 'e2mconnect' ),
	'description' => 'Creates a WordPress blog post with title, content, status, categories, tags, excerpt, and optional featured image.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'title'             => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Post title.' ],
			'content'           => [ 'type' => 'string', 'default' => '', 'description' => 'Post content (HTML or block markup).' ],
			'excerpt'           => [ 'type' => 'string', 'default' => '', 'description' => 'Optional manual excerpt.' ],
			'status'            => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ], 'default' => 'draft' ],
			'author_id'         => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Author user ID. Defaults to the current user.' ],
			'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Category term IDs.' ],
			'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Tag term IDs.' ],
			'featured_image_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Attachment ID to set as featured image.' ],
			'slug'              => [ 'type' => 'string', 'description' => 'Override the auto-generated slug.' ],
			'date'              => [ 'type' => 'string', 'description' => 'Publish date (ISO 8601). Required when status is "future".' ],
		],
		'required'             => [ 'title' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'permalink' => [ 'type' => 'string' ],
			'edit_url'  => [ 'type' => 'string' ],
			'status'    => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_post_ability( array $input ) {
	$title = isset( $input['title'] ) ? trim( sanitize_text_field( (string) $input['title'] ) ) : '';
	if ( $title === '' ) {
		return new WP_Error( 'invalid_title', __( 'A non-empty title is required.', 'e2mconnect' ) );
	}

	$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
	if ( ! in_array( $status, [ 'draft', 'publish', 'pending', 'private', 'future' ], true ) ) {
		$status = 'draft';
	}

	$postarr = [
		'post_type'    => 'post',
		'post_title'   => $title,
		'post_content' => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
		'post_excerpt' => isset( $input['excerpt'] ) ? wp_kses_post( (string) $input['excerpt'] ) : '',
		'post_status'  => $status,
	];

	if ( ! empty( $input['author_id'] ) ) {
		$postarr['post_author'] = (int) $input['author_id'];
	}

	if ( ! empty( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
	}

	if ( ! empty( $input['date'] ) ) {
		$timestamp = strtotime( (string) $input['date'] );
		if ( $timestamp !== false ) {
			$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}
	}

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
		wp_set_post_categories( (int) $post_id, array_map( 'intval', $input['categories'] ) );
	}

	if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
		wp_set_post_tags( (int) $post_id, array_map( 'intval', $input['tags'] ) );
	}

	if ( ! empty( $input['featured_image_id'] ) ) {
		set_post_thumbnail( (int) $post_id, (int) $input['featured_image_id'] );
	}

	return [
		'post_id'   => (int) $post_id,
		'permalink' => (string) get_permalink( (int) $post_id ),
		'edit_url'  => (string) get_edit_post_link( (int) $post_id, 'raw' ),
		'status'    => $status,
	];
}
