<?php
/**
 * E2M Connect MCP - WooCommerce shared helpers.
 *
 * Single source of truth for the payload shapes returned by every WooCommerce
 * ability (products, terms, orders) plus the guard used at the top of each
 * execute callback. Centralising these keeps the 22 shop tools consistent
 * and lets us evolve the output format in one place.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_WooCommerce_Helper {

	/**
	 * Uniform guard at the top of every WC ability. Returns a WP_Error when
	 * WooCommerce is not loaded, so the ability can bail early.
	 *
	 * @return WP_Error|null
	 */
	public static function require_active() {
		if ( function_exists( 'e2m_engine_has_woocommerce' ) && e2m_engine_has_woocommerce() ) {
			return null;
		}
		return new WP_Error(
			'woocommerce_required',
			__( 'WooCommerce is not active on this site.', 'e2mconnect' ),
			[ 'status' => 412 ]
		);
	}

	/**
	 * Flatten a WC_Product into a compact API payload. We intentionally
	 * return a subset of product_data() rather than the full REST CRUD body
	 * so the schema stays stable across WC versions.
	 *
	 * @return array<string, mixed>
	 */
	public static function product_to_row( \WC_Product $product ): array {
		return [
			'product_id'       => (int) $product->get_id(),
			'name'             => (string) $product->get_name(),
			'slug'             => (string) $product->get_slug(),
			'type'             => (string) $product->get_type(),
			'status'           => (string) $product->get_status(),
			'featured'         => (bool) $product->get_featured(),
			'catalog_visibility' => (string) $product->get_catalog_visibility(),
			'description'      => (string) $product->get_description(),
			'short_description'=> (string) $product->get_short_description(),
			'sku'              => (string) $product->get_sku(),
			'price'            => (string) $product->get_price(),
			'regular_price'    => (string) $product->get_regular_price(),
			'sale_price'       => (string) $product->get_sale_price(),
			'on_sale'          => (bool) $product->is_on_sale(),
			'stock_status'     => (string) $product->get_stock_status(),
			'stock_quantity'   => $product->get_stock_quantity(),
			'manage_stock'     => (bool) $product->get_manage_stock(),
			'categories'       => self::term_ids_to_rows( $product->get_category_ids(), 'product_cat' ),
			'tags'             => self::term_ids_to_rows( $product->get_tag_ids(), 'product_tag' ),
			'image_id'         => (int) $product->get_image_id(),
			'gallery_image_ids'=> array_map( 'intval', (array) $product->get_gallery_image_ids() ),
			'date_created'     => $product->get_date_created() ? (string) $product->get_date_created()->date( 'c' ) : '',
			'date_modified'    => $product->get_date_modified() ? (string) $product->get_date_modified()->date( 'c' ) : '',
			'permalink'        => (string) get_permalink( $product->get_id() ),
			'edit_url'         => (string) get_edit_post_link( $product->get_id(), 'raw' ),
		];
	}

	/**
	 * Flatten a WP_Term into a consistent {id,name,slug,parent,count,description} row.
	 *
	 * @return array<string, mixed>
	 */
	public static function term_to_row( \WP_Term $term ): array {
		return [
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		];
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function term_ids_to_rows( array $ids, string $taxonomy ): array {
		$rows = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$rows[] = self::term_to_row( $term );
			}
		}
		return $rows;
	}

	/**
	 * Flatten a WC_Order into the canonical order row returned by list-orders,
	 * get-order, and update-order-status.
	 *
	 * @return array<string, mixed>
	 */
	public static function order_to_row( \WC_Order $order ): array {
		$line_items = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$line_items[] = [
				'item_id'    => (int) $item->get_id(),
				'product_id' => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name'       => (string) $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'subtotal'   => (string) $item->get_subtotal(),
				'total'      => (string) $item->get_total(),
			];
		}
		return [
			'order_id'       => (int) $order->get_id(),
			'number'         => (string) $order->get_order_number(),
			'status'         => (string) $order->get_status(),
			'currency'       => (string) $order->get_currency(),
			'total'          => (string) $order->get_total(),
			'subtotal'       => (string) $order->get_subtotal(),
			'total_tax'      => (string) $order->get_total_tax(),
			'shipping_total' => (string) $order->get_shipping_total(),
			'discount_total' => (string) $order->get_discount_total(),
			'customer_id'    => (int) $order->get_customer_id(),
			'customer_email' => (string) $order->get_billing_email(),
			'payment_method' => (string) $order->get_payment_method(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'date_created'   => $order->get_date_created() ? (string) $order->get_date_created()->date( 'c' ) : '',
			'date_modified'  => $order->get_date_modified() ? (string) $order->get_date_modified()->date( 'c' ) : '',
			'date_paid'      => $order->get_date_paid() ? (string) $order->get_date_paid()->date( 'c' ) : '',
			'billing'        => (array) $order->get_address( 'billing' ),
			'shipping'       => (array) $order->get_address( 'shipping' ),
			'line_items'     => $line_items,
		];
	}
}
