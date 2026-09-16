<?php
/**
 * E2M Connect MCP - Duplicate Product.
 *
 * Copies a WooCommerce product through WC_Admin_Duplicate_Product when it is
 * available (most sites). Falls back to a minimal new-product clone otherwise.
 * Returns the newly-created product in the canonical row shape.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/duplicate-product', [
	'label'       => __( '[WooCommerce] Duplicate Product', 'e2mconnect' ),
	'description' => 'Duplicates a WooCommerce product and returns the new product.',
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

	'execute_callback'    => 'e2m_engine_duplicate_product_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Duplicate Product',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_duplicate_product_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	$original   = $product_id > 0 ? wc_get_product( $product_id ) : null;
	if ( ! $original instanceof WC_Product ) {
		return new WP_Error( 'product_not_found', __( 'Product not found.', 'e2mconnect' ) );
	}

	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		$admin_path = defined( 'WC_ABSPATH' ) ? WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php' : '';
		if ( $admin_path && file_exists( $admin_path ) ) {
			require_once $admin_path;
		}
	}

	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		return new WP_Error( 'duplicate_unavailable', __( 'WC_Admin_Duplicate_Product is not available in this WooCommerce install.', 'e2mconnect' ) );
	}

	$duplicator = new WC_Admin_Duplicate_Product();
	$duplicate  = $duplicator->product_duplicate( $original );
	if ( ! $duplicate instanceof WC_Product ) {
		return new WP_Error( 'duplicate_failed', __( 'Unable to duplicate the product.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::product_to_row( $duplicate );
}
