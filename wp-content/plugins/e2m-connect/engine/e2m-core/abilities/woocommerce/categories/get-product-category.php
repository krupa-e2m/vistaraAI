<?php
/**
 * E2M Connect MCP - Get Product Category.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-product-category', [
	'label'       => __( '[WooCommerce] Get Category', 'e2mconnect' ),
	'description' => 'Returns a single WooCommerce product category by term ID.',
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
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'execute_callback'    => 'e2m_engine_get_product_category_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Product Category',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_get_product_category_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
	$term    = get_term( $term_id, 'product_cat' );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'category_not_found', __( 'Product category not found.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::term_to_row( $term );
}
