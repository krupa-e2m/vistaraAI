<?php
/**
 * E2M Connect MCP - Get Term
 *
 * Returns a single term's full record by ID. The taxonomy slug is required to
 * disambiguate when the same term_id exists across multiple taxonomies.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-term', [
	'label'       => __( '[Taxonomy] Get Term', 'e2mconnect' ),
	'description' => 'Retrieves a single taxonomy term by term_id and taxonomy slug.',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'taxonomy' => [ 'type' => 'string', 'minLength' => 1 ],
			'term_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'taxonomy', 'term_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id'     => [ 'type' => 'integer' ],
			'taxonomy'    => [ 'type' => 'string' ],
			'name'        => [ 'type' => 'string' ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
			'parent'      => [ 'type' => 'integer' ],
			'count'       => [ 'type' => 'integer' ],
			'link'        => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_term_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Term',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the get-term ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_term_ability( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;

	if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) || $term_id <= 0 ) {
		return new WP_Error( 'invalid_input', __( 'Valid taxonomy and term_id are required.', 'e2mconnect' ) );
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return is_wp_error( $term ) ? $term : new WP_Error( 'term_not_found', __( 'Term not found.', 'e2mconnect' ) );
	}

	$link = get_term_link( $term );

	return [
		'term_id'     => (int) $term->term_id,
		'taxonomy'    => (string) $term->taxonomy,
		'name'        => (string) $term->name,
		'slug'        => (string) $term->slug,
		'description' => (string) $term->description,
		'parent'      => (int) $term->parent,
		'count'       => (int) $term->count,
		'link'        => is_string( $link ) ? $link : '',
	];
}
