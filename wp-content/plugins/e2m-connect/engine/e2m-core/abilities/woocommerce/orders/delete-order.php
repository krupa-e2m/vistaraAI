<?php
/**
 * E2M Connect MCP - Delete Order.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/delete-order', [
	'label'       => __( '[WooCommerce] Delete Order', 'e2mconnect' ),
	'description' => 'Deletes a WooCommerce order. Defaults to trash; pass force=true for permanent deletion.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'force'    => [ 'type' => 'boolean', 'default' => false ],
		],
		'required'             => [ 'order_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'order_id' => [ 'type' => 'integer' ],
			'deleted'  => [ 'type' => 'boolean' ],
			'force'    => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_delete_order_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Delete Order',
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
function e2m_engine_delete_order_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
	$force    = ! empty( $input['force'] );

	$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'order_not_found', __( 'Order not found.', 'e2mconnect' ) );
	}

	$order->delete( $force );
	$still = wc_get_order( $order_id );
	$deleted = $force ? ! $still : ( $still && 'trash' === $still->get_status() );

	return [
		'order_id' => $order_id,
		'deleted'  => (bool) $deleted,
		'force'    => (bool) $force,
	];
}
