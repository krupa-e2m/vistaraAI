<?php
/**
 * E2M Connect MCP - Get Product.
 *
 * Returns the canonical product row for a single WooCommerce product by ID.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-product', [
	'label'       => __( '[WooCommerce] Get Product', 'e2mconnect' ),
	'description' => 'Returns a WooCommerce product by ID with pricing, stock, taxonomies, and gallery metadata.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'product_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'execute_callback'    => 'e2m_engine_get_product_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Product',
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
function e2m_engine_get_product_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'product_not_found', __( 'Product not found.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::product_to_row( $product );
}
