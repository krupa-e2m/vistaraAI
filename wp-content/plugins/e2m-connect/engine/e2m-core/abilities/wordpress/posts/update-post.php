<?php
/**
 * E2M Connect MCP - Update Post
 *
 * Partial-update a WordPress post by ID. Only the fields present in the input
 * are touched; the rest are preserved. Reports which fields changed so MCP
 * clients can render an accurate diff summary.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-post', [
	'label'       => __( '[Post] Update Post', 'e2mconnect' ),
	'description' => 'Updates an existing WordPress post. Only provided fields are changed; omitted fields are preserved.',
	'category'    => 'e2m-content',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'           => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post ID to update.' ],
			'title'             => [ 'type' => 'string' ],
			'content'           => [ 'type' => 'string' ],
			'excerpt'           => [ 'type' => 'string' ],
			'status'            => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future', 'trash' ] ],
			'author_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
			'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'featured_image_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Set to 0 to remove the featured image.' ],
			'slug'              => [ 'type' => 'string' ],
			'date'              => [ 'type' => 'string', 'description' => 'ISO 8601 publish date.' ],
		],
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'permalink'      => [ 'type' => 'string' ],
			'edit_url'       => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_post_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Post',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-post ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_post_ability( array $input ) {
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

	if ( array_key_exists( 'title', $input ) ) {
		$patch['post_title'] = sanitize_text_field( (string) $input['title'] );
		$touched[]           = 'title';
	}
	if ( array_key_exists( 'content', $input ) ) {
		$patch['post_content'] = wp_kses_post( (string) $input['content'] );
		$touched[]             = 'content';
	}
	if ( array_key_exists( 'excerpt', $input ) ) {
		$patch['post_excerpt'] = wp_kses_post( (string) $input['excerpt'] );
		$touched[]             = 'excerpt';
	}
	if ( array_key_exists( 'status', $input ) ) {
		$patch['post_status'] = sanitize_key( (string) $input['status'] );
		$touched[]            = 'status';
	}
	if ( array_key_exists( 'author_id', $input ) ) {
		$patch['post_author'] = (int) $input['author_id'];
		$touched[]            = 'author_id';
	}
	if ( array_key_exists( 'slug', $input ) ) {
		$patch['post_name'] = sanitize_title( (string) $input['slug'] );
		$touched[]          = 'slug';
	}
	if ( array_key_exists( 'date', $input ) ) {
		$ts = strtotime( (string) $input['date'] );
		if ( $ts !== false ) {
			$patch['post_date']     = gmdate( 'Y-m-d H:i:s', $ts );
			$patch['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
			$touched[]              = 'date';
		}
	}

	if ( count( $patch ) > 1 ) {
		$res = wp_update_post( $patch, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}

	if ( array_key_exists( 'categories', $input ) && is_array( $input['categories'] ) ) {
		wp_set_post_categories( $post_id, array_map( 'intval', $input['categories'] ) );
		$touched[] = 'categories';
	}
	if ( array_key_exists( 'tags', $input ) && is_array( $input['tags'] ) ) {
		wp_set_post_tags( $post_id, array_map( 'intval', $input['tags'] ) );
		$touched[] = 'tags';
	}
	if ( array_key_exists( 'featured_image_id', $input ) ) {
		$attachment = (int) $input['featured_image_id'];
		if ( $attachment > 0 ) {
			set_post_thumbnail( $post_id, $attachment );
		} else {
			delete_post_thumbnail( $post_id );
		}
		$touched[] = 'featured_image_id';
	}

	if ( $touched === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	return [
		'post_id'        => $post_id,
		'updated_fields' => $touched,
		'permalink'      => (string) get_permalink( $post_id ),
		'edit_url'       => (string) get_edit_post_link( $post_id, 'raw' ),
	];
}
