<?php
/**
 * E2M Connect MCP - Update Order.
 *
 * Partial update of a WooCommerce order - addresses, customer note, payment
 * method, and optional status change. Line-item edits are intentionally out
 * of scope to keep the schema stable; use WC admin for that.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-order', [
	'label'       => __( '[WooCommerce] Update Order', 'e2mconnect' ),
	'description' => 'Updates high-level order metadata: addresses, customer note, payment method, status.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'order_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
			'status'        => [ 'type' => 'string', 'description' => 'Status slug without the wc- prefix (e.g. processing, completed).' ],
			'customer_note' => [ 'type' => 'string' ],
			'billing'       => [ 'type' => 'object', 'additionalProperties' => true ],
			'shipping'      => [ 'type' => 'object', 'additionalProperties' => true ],
			'payment_method' => [ 'type' => 'string' ],
			'payment_method_title' => [ 'type' => 'string' ],
		],
		'required'             => [ 'order_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],

	'execute_callback'    => 'e2m_engine_update_order_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Order',
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
function e2m_engine_update_order_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
	$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'order_not_found', __( 'Order not found.', 'e2mconnect' ) );
	}

	if ( isset( $input['customer_note'] ) ) {
		$order->set_customer_note( wp_kses_post( (string) $input['customer_note'] ) );
	}
	if ( isset( $input['payment_method'] ) ) {
		$order->set_payment_method( sanitize_text_field( (string) $input['payment_method'] ) );
	}
	if ( isset( $input['payment_method_title'] ) ) {
		$order->set_payment_method_title( sanitize_text_field( (string) $input['payment_method_title'] ) );
	}

	if ( ! empty( $input['billing'] ) && is_array( $input['billing'] ) ) {
		$order->set_address( array_map( 'sanitize_text_field', $input['billing'] ), 'billing' );
	}
	if ( ! empty( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
		$order->set_address( array_map( 'sanitize_text_field', $input['shipping'] ), 'shipping' );
	}

	$saved_id = $order->save();
	if ( ! $saved_id ) {
		return new WP_Error( 'order_save_failed', __( 'Unable to save the order.', 'e2mconnect' ) );
	}

	// Status changes go through update_status so the "status changed" notes /
	// emails fire correctly.
	if ( isset( $input['status'] ) ) {
		$status = preg_replace( '/^wc-/', '', sanitize_key( (string) $input['status'] ) );
		$order->update_status( $status, 'Status updated via E2M Connect MCP.' );
	}

	return E2M_WooCommerce_Helper::order_to_row( wc_get_order( $saved_id ) );
}
