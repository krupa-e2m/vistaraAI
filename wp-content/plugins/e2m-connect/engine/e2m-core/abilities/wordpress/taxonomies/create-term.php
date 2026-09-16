<?php
/**
 * E2M Connect MCP - Create Term
 *
 * Inserts a new term into a taxonomy. Supports slug overrides, description,
 * and parent assignment for hierarchical taxonomies (categories, custom
 * hierarchical taxonomies).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-term', [
	'label'       => __( '[Taxonomy] Create Term', 'e2mconnect' ),
	'description' => 'Creates a term in a taxonomy with optional slug, description, and parent (hierarchical taxonomies only).',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'taxonomy'    => [ 'type' => 'string', 'minLength' => 1 ],
			'name'        => [ 'type' => 'string', 'minLength' => 1 ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
			'parent'      => [ 'type' => 'integer', 'minimum' => 0 ],
		],
		'required'             => [ 'taxonomy', 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id'          => [ 'type' => 'integer' ],
			'term_taxonomy_id' => [ 'type' => 'integer' ],
		],
	],

	'execute_callback'    => 'e2m_engine_create_term_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Term',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the create-term ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_term_ability( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$name     = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';

	if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', __( 'Unknown taxonomy.', 'e2mconnect' ) );
	}
	if ( $name === '' ) {
		return new WP_Error( 'invalid_name', __( 'Term name is required.', 'e2mconnect' ) );
	}

	$args = [];
	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['parent'] = (int) $input['parent'];
	}

	$result = wp_insert_term( $name, $taxonomy, $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'term_id'          => (int) $result['term_id'],
		'term_taxonomy_id' => (int) $result['term_taxonomy_id'],
	];
}
