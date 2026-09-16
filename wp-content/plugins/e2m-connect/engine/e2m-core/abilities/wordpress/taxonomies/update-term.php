<?php
/**
 * E2M Connect MCP - Update Term
 *
 * Partial update for a term: name, slug, description, and parent re-parenting.
 * All fields are optional; only supplied keys are touched.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-term', [
	'label'       => __( '[Taxonomy] Update Term', 'e2mconnect' ),
	'description' => 'Updates a term\'s name, slug, description, or parent.',
	'category'    => 'e2m-taxonomies',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'taxonomy'    => [ 'type' => 'string', 'minLength' => 1 ],
			'term_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'name'        => [ 'type' => 'string' ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
			'parent'      => [ 'type' => 'integer', 'minimum' => 0 ],
		],
		'required'             => [ 'taxonomy', 'term_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id'        => [ 'type' => 'integer' ],
			'updated_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_update_term_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Term',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the update-term ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_term_ability( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;

	if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) || $term_id <= 0 ) {
		return new WP_Error( 'invalid_input', __( 'Valid taxonomy and term_id are required.', 'e2mconnect' ) );
	}

	$patch   = [];
	$touched = [];

	if ( array_key_exists( 'name', $input ) ) {
		$patch['name'] = sanitize_text_field( (string) $input['name'] );
		$touched[]     = 'name';
	}
	if ( array_key_exists( 'slug', $input ) ) {
		$patch['slug'] = sanitize_title( (string) $input['slug'] );
		$touched[]     = 'slug';
	}
	if ( array_key_exists( 'description', $input ) ) {
		$patch['description'] = wp_kses_post( (string) $input['description'] );
		$touched[]            = 'description';
	}
	if ( array_key_exists( 'parent', $input ) ) {
		$patch['parent'] = (int) $input['parent'];
		$touched[]       = 'parent';
	}

	if ( $patch === [] ) {
		return new WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'e2mconnect' ) );
	}

	$result = wp_update_term( $term_id, $taxonomy, $patch );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'term_id'        => $term_id,
		'updated_fields' => $touched,
	];
}
