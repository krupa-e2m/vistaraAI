<?php
/**
 * E2M Connect MCP - Get Order.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-order', [
	'label'       => __( '[WooCommerce] Get Order', 'e2mconnect' ),
	'description' => 'Returns a WooCommerce order with line items, totals, and customer details.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'order_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],

	'execute_callback'    => 'e2m_engine_get_order_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Order',
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
function e2m_engine_get_order_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
	$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'order_not_found', __( 'Order not found.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::order_to_row( $order );
}
