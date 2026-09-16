<?php
/**
 * E2M Connect MCP - Get Taxonomy
 *
 * Returns the full registration payload for a single taxonomy: labels, REST
 * base, capabilities, and the set of post types it applies to.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-taxonomy', [
	'label'       => __( '[Taxonomy] Get Taxonomy', 'e2mconnect' ),
	'description' => 'Retrieves full registration metadata for a taxonomy by slug.',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'slug' => [ 'type' => 'string', 'minLength' => 1 ],
		],
		'required'             => [ 'slug' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'slug'           => [ 'type' => 'string' ],
			'label'          => [ 'type' => 'string' ],
			'label_singular' => [ 'type' => 'string' ],
			'description'    => [ 'type' => 'string' ],
			'hierarchical'   => [ 'type' => 'boolean' ],
			'public'         => [ 'type' => 'boolean' ],
			'show_in_rest'   => [ 'type' => 'boolean' ],
			'rest_base'      => [ 'type' => 'string' ],
			'post_types'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_taxonomy_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Taxonomy',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-taxonomy ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_taxonomy_ability( array $input ) {
	$slug = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
	if ( $slug === '' ) {
		return new WP_Error( 'invalid_slug', __( 'Taxonomy slug is required.', 'e2mconnect' ) );
	}

	$tax = get_taxonomy( $slug );
	if ( ! $tax ) {
		return new WP_Error( 'taxonomy_not_found', __( 'Unknown taxonomy slug.', 'e2mconnect' ) );
	}

	return [
		'slug'           => $slug,
		'label'          => (string) $tax->label,
		'label_singular' => isset( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : (string) $tax->label,
		'description'    => (string) $tax->description,
		'hierarchical'   => (bool) $tax->hierarchical,
		'public'         => (bool) $tax->public,
		'show_in_rest'   => (bool) $tax->show_in_rest,
		'rest_base'      => (string) ( $tax->rest_base !== false ? $tax->rest_base : $slug ),
		'post_types'     => (array) $tax->object_type,
	];
}
