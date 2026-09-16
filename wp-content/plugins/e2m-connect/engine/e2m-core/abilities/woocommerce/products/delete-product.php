<?php
/**
 * E2M Connect MCP - Delete Product.
 *
 * Trashes (default) or permanently deletes a WooCommerce product. Deletion
 * routes through the WC data-store so variable products and their variations
 * are cleaned up correctly.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-product', [
	'label'       => __( '[WooCommerce] Delete Product', 'e2mconnect' ),
	'description' => 'Deletes a WooCommerce product. Defaults to trash; pass force=true for permanent deletion.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'force'      => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'product_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'product_id' => [ 'type' => 'integer' ],
			'deleted'    => [ 'type' => 'boolean' ],
			'force'      => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_product_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Product',
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
function e2m_engine_delete_product_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	$force      = ! empty( $input['force'] );

	$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'product_not_found', __( 'Product not found.', 'e2mconnect' ) );
	}

	$product->delete( $force );
	$still_exists = wc_get_product( $product_id );
	$deleted = $force ? ! $still_exists : ( $still_exists && 'trash' === $still_exists->get_status() );

	return [
		'product_id' => $product_id,
		'deleted'    => (bool) $deleted,
		'force'      => (bool) $force,
	];
}
