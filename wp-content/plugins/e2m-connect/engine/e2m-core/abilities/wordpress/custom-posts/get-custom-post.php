<?php
/**
 * E2M Connect MCP - Get Custom Post
 *
 * Retrieves a single entry from any post type, including all public post meta
 * values. Meta is returned as a flat key => value map, with underscore-prefixed
 * (private) keys excluded unless include_private_meta is true.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-custom-post', [
	'label'       => __( '[CPT] Get Post', 'e2mconnect' ),
	'description' => 'Retrieves a single entry from any post type with its public meta fields.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'              => [ 'type' => 'integer', 'minimum' => 1 ],
			'include_private_meta' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Include meta keys that begin with underscore.' ],
			'raw_content'          => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'   => [ 'type' => 'integer' ],
			'type'      => [ 'type' => 'string' ],
			'title'     => [ 'type' => 'string' ],
			'slug'      => [ 'type' => 'string' ],
			'status'    => [ 'type' => 'string' ],
			'author_id' => [ 'type' => 'integer' ],
			'content'   => [ 'type' => 'string' ],
			'excerpt'   => [ 'type' => 'string' ],
			'meta'      => [ 'type' => 'object', 'additionalProperties' => true ],
			'modified'  => [ 'type' => 'string' ],
			'permalink' => [ 'type' => 'string' ],
			'edit_url'  => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_custom_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Custom Post',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-custom-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_custom_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}

	$raw                   = ! empty( $input['raw_content'] );
	$include_private_meta  = ! empty( $input['include_private_meta'] );

	$meta_all = get_post_meta( $post_id );
	$meta     = [];
	if ( is_array( $meta_all ) ) {
		foreach ( $meta_all as $key => $values ) {
			if ( ! $include_private_meta && str_starts_with( (string) $key, '_' ) ) {
				continue;
			}
			$meta[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
	}

	return [
		'post_id'   => (int) $post->ID,
		'type'      => (string) $post->post_type,
		'title'     => get_the_title( $post ),
		'slug'      => (string) $post->post_name,
		'status'    => (string) $post->post_status,
		'author_id' => (int) $post->post_author,
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking core WP filter.
		'content'   => $raw ? (string) $post->post_content : apply_filters( 'the_content', (string) $post->post_content ),
		'excerpt'   => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
		'meta'      => $meta,
		'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
		'permalink' => (string) get_permalink( $post ),
		'edit_url'  => (string) get_edit_post_link( $post->ID, 'raw' ),
	];
}
