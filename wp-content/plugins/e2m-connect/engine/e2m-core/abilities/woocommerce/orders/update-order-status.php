<?php
/**
 * E2M Connect MCP - Update Order Status.
 *
 * Focused shortcut for transitioning an order. Goes through update_status()
 * so status-change notes + customer emails fire correctly.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-order-status', [
	'label'       => __( '[WooCommerce] Update Order Status', 'e2mconnect' ),
	'description' => 'Transitions an order to a new status and records an optional note.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'status'   => [ 'type' => 'string', 'description' => 'Status slug (pending, processing, on-hold, completed, cancelled, refunded, failed).' ],
			'note'     => [ 'type' => 'string', 'description' => 'Optional transition note stored on the order.' ],
		],
		'required'             => [ 'order_id', 'status' ],
		'additionalProperties' => false,
	],

	'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],

	'execute_callback'    => 'e2m_engine_update_order_status_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Order Status',
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
function e2m_engine_update_order_status_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
	$status   = isset( $input['status'] ) ? preg_replace( '/^wc-/', '', sanitize_key( (string) $input['status'] ) ) : '';
	$note     = isset( $input['note'] ) ? wp_kses_post( (string) $input['note'] ) : '';

	if ( $status === '' ) {
		return new WP_Error( 'invalid_status', __( 'A status slug is required.', 'e2mconnect' ) );
	}

	$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'order_not_found', __( 'Order not found.', 'e2mconnect' ) );
	}

	$order->update_status( $status, $note );

	return E2M_WooCommerce_Helper::order_to_row( wc_get_order( $order_id ) );
}
