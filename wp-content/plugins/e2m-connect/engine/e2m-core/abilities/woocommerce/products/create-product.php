<?php
/**
 * E2M Connect MCP - Create Product.
 *
 * Creates a new WooCommerce product via the CRUD factory so the write hits
 * every WC data-store hook (object caches, Elasticsearch index, etc.).
 * Supports simple products with optional category / tag associations.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-product', [
	'label'       => __( '[WooCommerce] Create Product', 'e2mconnect' ),
	'description' => 'Creates a new WooCommerce product (simple by default) with pricing, stock, and taxonomy associations.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'              => [ 'type' => 'string', 'minLength' => 1 ],
			'type'              => [ 'type' => 'string', 'enum' => [ 'simple', 'grouped', 'external', 'variable' ], 'default' => 'simple' ],
			'status'            => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private' ], 'default' => 'draft' ],
			'description'       => [ 'type' => 'string', 'default' => '' ],
			'short_description' => [ 'type' => 'string', 'default' => '' ],
			'sku'               => [ 'type' => 'string', 'default' => '' ],
			'regular_price'     => [ 'type' => 'string', 'default' => '' ],
			'sale_price'        => [ 'type' => 'string', 'default' => '' ],
			'manage_stock'      => [ 'type' => 'boolean', 'default' => false ],
			'stock_quantity'    => [ 'type' => 'integer' ],
			'stock_status'      => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ], 'default' => 'instock' ],
			'featured'          => [ 'type' => 'boolean', 'default' => false ],
			'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'image_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
			'gallery_image_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
		],
		'required'             => [ 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'execute_callback'    => 'e2m_engine_create_product_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Product',
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
function e2m_engine_create_product_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
	if ( $name === '' ) {
		return new WP_Error( 'invalid_name', __( 'A non-empty product name is required.', 'e2mconnect' ) );
	}

	$type  = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'simple';
	$class = 'WC_Product_' . ucfirst( $type );
	if ( ! class_exists( $class ) ) {
		$class = 'WC_Product_Simple';
	}

	/** @var WC_Product $product */
	$product = new $class();
	$product->set_name( $name );
	$product->set_status( isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft' );
	$product->set_description( isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '' );
	$product->set_short_description( isset( $input['short_description'] ) ? wp_kses_post( (string) $input['short_description'] ) : '' );

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
	if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
		$product->set_category_ids( array_map( 'intval', $input['categories'] ) );
	}
	if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
		$product->set_tag_ids( array_map( 'intval', $input['tags'] ) );
	}
	if ( ! empty( $input['image_id'] ) ) {
		$product->set_image_id( (int) $input['image_id'] );
	}
	if ( ! empty( $input['gallery_image_ids'] ) && is_array( $input['gallery_image_ids'] ) ) {
		$product->set_gallery_image_ids( array_map( 'intval', $input['gallery_image_ids'] ) );
	}

	$product_id = $product->save();
	if ( ! $product_id ) {
		return new WP_Error( 'product_save_failed', __( 'Unable to save the product.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::product_to_row( wc_get_product( $product_id ) );
}
