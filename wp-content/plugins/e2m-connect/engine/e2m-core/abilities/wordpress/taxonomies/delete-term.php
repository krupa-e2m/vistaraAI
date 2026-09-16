<?php
/**
 * E2M Connect MCP - Delete Term
 *
 * Removes a term from its taxonomy. Posts currently assigned to the term are
 * unassigned rather than deleted (WP_Term_Query default). For hierarchical
 * taxonomies children are orphaned to the default parent (0).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-term', [
	'label'       => __( '[Taxonomy] Delete Term', 'e2mconnect' ),
	'description' => 'Deletes a term from its taxonomy. Posts assigned to the term are unassigned; child terms are orphaned.',
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
			'term_id' => [ 'type' => 'integer' ],
			'state'   => [ 'type' => 'string' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_term_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Term',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the delete-term ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_term_ability( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;

	if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) || $term_id <= 0 ) {
		return new WP_Error( 'invalid_input', __( 'Valid taxonomy and term_id are required.', 'e2mconnect' ) );
	}

	$tax_object = get_taxonomy( $taxonomy );
	$delete_cap = $tax_object && ! empty( $tax_object->cap->delete_terms ) ? (string) $tax_object->cap->delete_terms : 'manage_categories';
	if ( ! current_user_can( $delete_cap ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete terms in this taxonomy.', 'e2mconnect' ) );
	}

	$result = wp_delete_term( $term_id, $taxonomy );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false || $result === 0 ) {
		return new WP_Error( 'delete_failed', __( 'Unable to delete term.', 'e2mconnect' ) );
	}

	return [
		'term_id' => $term_id,
		'state'   => 'deleted',
	];
}
