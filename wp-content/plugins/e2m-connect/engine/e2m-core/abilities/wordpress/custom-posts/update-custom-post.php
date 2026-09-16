<?php
/**
 * E2M Connect MCP - Update Custom Post
 *
 * Partial update of any post-type entry with support for merging meta fields.
 * Meta writes operate in two modes: merge (default - update provided keys,
 * preserve others) and replace (clear existing meta keys listed in meta_keys_to_remove).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-custom-post', [
	'label'       => __( '[CPT] Update Post', 'e2mconnect' ),
	'description' => 'Partial update of any post-type entry. Meta fields merge by default.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'              => [ 'type' => 'integer', 'minimum' => 1 ],
			'title'                => [ 'type' => 'string' ],
			'content'              => [ 'type' => 'string' ],
			'excerpt'              => [ 'type' => 'string' ],
			'status'               => [ 'type' => 'string' ],
			'slug'                 => [ 'type' => 'string' ],
			'author_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
			'meta'                 => [ 'type' => 'object', 'additionalProperties' => true ],
			'meta_keys_to_remove'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'parent'               => [ 'type' => 'integer', 'minimum' => 0 ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_custom_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Custom Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-custom-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_custom_post_ability( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( $post_id <= 0 ) {
		return new WP_Error( 'invalid_post_id', __( 'A valid post_id is required.', 'e2mconnect' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'e2mconnect' ) );
	}

	$patch   = [ 'ID' => $post_id ];
	$touched = [];

	$field_map = [
		'title'     => 'post_title',
		'content'   => 'post_content',
		'excerpt'   => 'post_excerpt',
		'status'    => 'post_status',
		'slug'      => 'post_name',
		'author_id' => 'post_author',
		'parent'    => 'post_parent',
	];

	foreach ( $field_map as $in_key => $db_key ) {
		if ( ! array_key_exists( $in_key, $input ) ) {
			continue;
		}
		$raw = $input[ $in_key ];

		$patch[ $db_key ] = match ( $in_key ) {
			'title', 'slug'            => $in_key === 'slug' ? sanitize_title( (string) $raw ) : sanitize_text_field( (string) $raw ),
			'content', 'excerpt'       => wp_kses_post( (string) $raw ),
			'status'                   => sanitize_key( (string) $raw ),
			'author_id', 'parent'      => (int) $raw,
			default                    => $raw,
		};
		$touched[] = $in_key;
	}

	if ( count( $patch ) > 1 ) {
		$res = wp_update_post( $patch, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}

	if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
		foreach ( $input['meta'] as $key => $value ) {
			update_post_meta( $post_id, sanitize_key( (string) $key ), $value );
		}
		$touched[] = 'meta';
	}

	if ( isset( $input['meta_keys_to_remove'] ) && is_array( $input['meta_keys_to_remove'] ) ) {
		foreach ( $input['meta_keys_to_remove'] as $key ) {
			delete_post_meta( $post_id, sanitize_key( (string) $key ) );
		}
		$touched[] = 'meta_keys_to_remove';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	return [
		'post_id'        => $post_id,
		'updated_fields' => $touched,
	];
}
