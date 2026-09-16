<?php
/**
 * E2M Connect MCP - Get Sales Report.
 *
 * Aggregates completed-order revenue between two dates. Mirrors the shape
 * returned by WC's REST /wc-analytics/reports/revenue/stats so downstream
 * agents can reason about top-level KPIs without needing the analytics
 * subsystem to be available.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/get-sales-report', [
	'label'       => __( '[WooCommerce] Sales Report', 'e2mconnect' ),
	'description' => 'Aggregated WooCommerce sales for a date range: gross total, net revenue, order count, average order value.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'after'    => [ 'type' => 'string', 'description' => 'ISO 8601 start date (inclusive).' ],
			'before'   => [ 'type' => 'string', 'description' => 'ISO 8601 end date (inclusive). Defaults to now.' ],
			'statuses' => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
				'description' => 'Order statuses to include (default: completed, processing).',
			],
		],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'after'               => [ 'type' => 'string' ],
			'before'              => [ 'type' => 'string' ],
			'order_count'         => [ 'type' => 'integer' ],
			'gross_total'         => [ 'type' => 'string' ],
			'net_total'           => [ 'type' => 'string' ],
			'shipping_total'      => [ 'type' => 'string' ],
			'tax_total'           => [ 'type' => 'string' ],
			'discount_total'      => [ 'type' => 'string' ],
			'average_order_value' => [ 'type' => 'string' ],
			'currency'            => [ 'type' => 'string' ],
			'statuses'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_get_sales_report_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Get Sales Report',
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
function e2m_engine_get_sales_report_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$statuses = [];
	if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
		foreach ( $input['statuses'] as $s ) {
			$statuses[] = 'wc-' . preg_replace( '/^wc-/', '', sanitize_key( (string) $s ) );
		}
	}
	if ( empty( $statuses ) ) {
		$statuses = [ 'wc-completed', 'wc-processing' ];
	}

	$after_ts  = ! empty( $input['after'] ) ? strtotime( (string) $input['after'] ) : strtotime( '-30 days' );
	$before_ts = ! empty( $input['before'] ) ? strtotime( (string) $input['before'] ) : time();
	if ( $after_ts === false || $before_ts === false || $after_ts > $before_ts ) {
		return new WP_Error( 'invalid_date_range', __( '`after` must be earlier than `before` and both must be valid ISO 8601 timestamps.', 'e2mconnect' ) );
	}

	$orders = wc_get_orders( [
		'limit'        => -1,
		'status'       => $statuses,
		'date_created' => sprintf( '%d...%d', $after_ts, $before_ts ),
		'return'       => 'objects',
	] );

	$order_count   = 0;
	$gross         = 0.0;
	$shipping      = 0.0;
	$tax           = 0.0;
	$discount      = 0.0;
	foreach ( (array) $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$order_count++;
		$gross    += (float) $order->get_total();
		$shipping += (float) $order->get_shipping_total();
		$tax      += (float) $order->get_total_tax();
		$discount += (float) $order->get_discount_total();
	}
	$net       = $gross - $shipping - $tax;
	$avg_order = $order_count > 0 ? $gross / $order_count : 0.0;

	return [
		'after'               => gmdate( 'c', $after_ts ),
		'before'              => gmdate( 'c', $before_ts ),
		'order_count'         => $order_count,
		'gross_total'         => wc_format_decimal( $gross, wc_get_price_decimals() ),
		'net_total'           => wc_format_decimal( $net, wc_get_price_decimals() ),
		'shipping_total'      => wc_format_decimal( $shipping, wc_get_price_decimals() ),
		'tax_total'           => wc_format_decimal( $tax, wc_get_price_decimals() ),
		'discount_total'      => wc_format_decimal( $discount, wc_get_price_decimals() ),
		'average_order_value' => wc_format_decimal( $avg_order, wc_get_price_decimals() ),
		'currency'            => get_woocommerce_currency(),
		'statuses'            => array_map( fn( $s ) => preg_replace( '/^wc-/', '', $s ), $statuses ),
	];
}
