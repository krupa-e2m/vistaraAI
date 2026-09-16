<?php
/**
 * E2M Connect MCP - Get Post
 *
 * Returns the full record for a single post: title, content, metadata, taxonomy
 * terms, featured media, and author. Used when an MCP agent needs the complete
 * payload of a specific entry after discovering it through list-posts.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-post', [
	'label'       => __( '[Post] Get Post', 'e2mconnect' ),
	'description' => 'Retrieves a single WordPress post by post_id, including title, content, excerpt, status, taxonomy terms, featured image, and author info.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Post ID to retrieve.',
			],
			'raw_content' => [
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'If true, return the raw post_content as stored. If false (default), return with filters applied (e.g., blocks rendered).',
			],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'        => [ 'type' => 'integer' ],
			'title'          => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
			'status'         => [ 'type' => 'string' ],
			'type'           => [ 'type' => 'string' ],
			'author_id'      => [ 'type' => 'integer' ],
			'author_name'    => [ 'type' => 'string' ],
			'date'           => [ 'type' => 'string' ],
			'modified'       => [ 'type' => 'string' ],
			'content'        => [ 'type' => 'string' ],
			'excerpt'        => [ 'type' => 'string' ],
			'featured_image' => [
				'type'       => 'object',
				'properties' => [
					'id'  => [ 'type' => 'integer' ],
					'url' => [ 'type' => 'string' ],
				],
			],
			'categories'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'tags'           => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'permalink'      => [ 'type' => 'string' ],
			'edit_url'       => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true, 'tier' => 'essential' ],
		'annotations'  => [
			'title'       => 'Get Post',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$raw     = ! empty( $input['raw_content'] );

	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking core WP filter.
	$content = $raw ? (string) $post->post_content : apply_filters( 'the_content', (string) $post->post_content );

	$featured_id  = (int) get_post_thumbnail_id( $post_id );
	$featured_url = $featured_id > 0 ? (string) wp_get_attachment_url( $featured_id ) : '';

	$author      = get_userdata( (int) $post->post_author );
	$author_name = $author ? (string) $author->display_name : '';

	return [
		'post_id'        => (int) $post->ID,
		'title'          => get_the_title( $post ),
		'slug'           => (string) $post->post_name,
		'status'         => (string) $post->post_status,
		'type'           => (string) $post->post_type,
		'author_id'      => (int) $post->post_author,
		'author_name'    => $author_name,
		'date'           => mysql2date( 'c', $post->post_date_gmt, false ),
		'modified'       => mysql2date( 'c', $post->post_modified_gmt, false ),
		'content'        => $content,
		'excerpt'        => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
		'featured_image' => [
			'id'  => $featured_id,
			'url' => $featured_url,
		],
		'categories' => array_map( 'intval', wp_get_post_categories( $post_id ) ),
		'tags'       => array_map( 'intval', wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] ) ),
		'permalink'  => (string) get_permalink( $post_id ),
		'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
	];
}
