<?php
/**
 * E2M Connect MCP - Delete Product Tag.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-product-tag', [
	'label'       => __( '[WooCommerce] Delete Product Tag', 'e2mconnect' ),
	'description' => 'Deletes a WooCommerce product tag.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'term_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id' => [ 'type' => 'integer' ],
			'deleted' => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_product_tag_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Product Tag',
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_delete_product_tag_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
	$term    = get_term( $term_id, 'product_tag' );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'tag_not_found', __( 'Product tag not found.', 'e2mconnect' ) );
	}

	$result = wp_delete_term( $term_id, 'product_tag' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [ 'term_id' => $term_id, 'deleted' => (bool) $result ];
}
