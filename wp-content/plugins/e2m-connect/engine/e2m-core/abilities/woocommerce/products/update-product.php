<?php
/**
 * E2M Connect MCP - Update Product.
 *
 * Partial update of a WooCommerce product. Only fields the caller supplies
 * are touched; everything else is preserved. Uses the CRUD factory so every
 * write hits the WC data-store hooks.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-product', [
	'label'       => __( '[WooCommerce] Update Product', 'e2mconnect' ),
	'description' => 'Partially updates a WooCommerce product. Unspecified fields are preserved.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'product_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
			'name'              => [ 'type' => 'string' ],
			'status'            => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private' ] ],
			'description'       => [ 'type' => 'string' ],
			'short_description' => [ 'type' => 'string' ],
			'sku'               => [ 'type' => 'string' ],
			'regular_price'     => [ 'type' => 'string' ],
			'sale_price'        => [ 'type' => 'string' ],
			'manage_stock'      => [ 'type' => 'boolean' ],
			'stock_quantity'    => [ 'type' => 'integer' ],
			'stock_status'      => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
			'featured'          => [ 'type' => 'boolean' ],
			'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'image_id'          => [ 'type' => 'integer' ],
			'gallery_image_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
		],
		'required'             => [ 'product_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'execute_callback'    => 'e2m_engine_update_product_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Product',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_update_product_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'product_not_found', __( 'Product not found.', 'e2mconnect' ) );
	}

	if ( isset( $input['name'] ) ) {
		$product->set_name( sanitize_text_field( (string) $input['name'] ) );
	}
	if ( isset( $input['status'] ) ) {
		$product->set_status( sanitize_key( (string) $input['status'] ) );
	}
	if ( isset( $input['description'] ) ) {
		$product->set_description( wp_kses_post( (string) $input['description'] ) );
	}
	if ( isset( $input['short_description'] ) ) {
		$product->set_short_description( wp_kses_post( (string) $input['short_description'] ) );
	}
	if ( isset( $input['sku'] ) ) {
		$product->set_sku( sanitize_text_field( (string) $input['sku'] ) );
	}
	if ( isset( $input['regular_price'] ) ) {
		$product->set_regular_price( (string) $input['regular_price'] );
	}
	if ( isset( $input['sale_price'] ) ) {
		$product->set_sale_price( (string) $input['sale_price'] );
	}
	if ( isset( $input['manage_stock'] ) ) {
		$product->set_manage_stock( (bool) $input['manage_stock'] );
	}
	if ( isset( $input['stock_quantity'] ) ) {
		$product->set_stock_quantity( (int) $input['stock_quantity'] );
	}
	if ( isset( $input['stock_status'] ) ) {
		$product->set_stock_status( sanitize_key( (string) $input['stock_status'] ) );
	}
	if ( isset( $input['featured'] ) ) {
		$product->set_featured( (bool) $input['featured'] );
	}
	if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
		$product->set_category_ids( array_map( 'intval', $input['categories'] ) );
	}
	if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
		$product->set_tag_ids( array_map( 'intval', $input['tags'] ) );
	}
	if ( isset( $input['image_id'] ) ) {
		$product->set_image_id( (int) $input['image_id'] );
	}
	if ( isset( $input['gallery_image_ids'] ) && is_array( $input['gallery_image_ids'] ) ) {
		$product->set_gallery_image_ids( array_map( 'intval', $input['gallery_image_ids'] ) );
	}

	$saved_id = $product->save();
	if ( ! $saved_id ) {
		return new WP_Error( 'product_save_failed', __( 'Unable to save the product.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::product_to_row( wc_get_product( $saved_id ) );
}
