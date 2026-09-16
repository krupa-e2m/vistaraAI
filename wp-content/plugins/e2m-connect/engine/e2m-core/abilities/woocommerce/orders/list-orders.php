<?php
/**
 * E2M Connect MCP - List Orders.
 *
 * Returns a paginated list of WooCommerce orders. Uses wc_get_orders() which
 * respects High-Performance Order Storage (HPOS) when enabled.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-orders', [
	'label'       => __( '[WooCommerce] List Orders', 'e2mconnect' ),
	'description' => 'Returns a paginated list of WooCommerce orders with filters for status, customer, and date range.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'status'      => [ 'type' => 'string', 'description' => 'Single status slug (pending, processing, completed, ...) or "any".' ],
			'customer_id' => [ 'type' => 'integer', 'minimum' => 0 ],
			'after'       => [ 'type' => 'string', 'description' => 'ISO 8601 date; orders created after this timestamp.' ],
			'before'      => [ 'type' => 'string', 'description' => 'ISO 8601 date; orders created before this timestamp.' ],
			'orderby'     => [ 'type' => 'string', 'enum' => [ 'date', 'id', 'modified' ], 'default' => 'date' ],
			'order'       => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'orders'   => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_list_orders_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Orders',
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
function e2m_engine_list_orders_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$args = [
		'limit'    => $per_page,
		'page'     => $page,
		'orderby'  => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'date',
		'order'    => isset( $input['order'] ) && strtoupper( (string) $input['order'] ) === 'ASC' ? 'ASC' : 'DESC',
		'paginate' => true,
	];

	$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
	if ( $status !== 'any' && $status !== '' ) {
		$args['status'] = 'wc-' . preg_replace( '/^wc-/', '', $status );
	}
	if ( isset( $input['customer_id'] ) ) {
		$args['customer_id'] = (int) $input['customer_id'];
	}
	if ( ! empty( $input['after'] ) ) {
		$after = strtotime( (string) $input['after'] );
		if ( $after !== false ) {
			$args['date_created'] = '>=' . $after;
		}
	}
	if ( ! empty( $input['before'] ) ) {
		$before = strtotime( (string) $input['before'] );
		if ( $before !== false ) {
			$args['date_created'] = isset( $args['date_created'] )
				? $args['date_created'] . '...' . $before
				: '<=' . $before;
		}
	}

	$result = wc_get_orders( $args );
	$orders = [];
	foreach ( (array) ( $result->orders ?? [] ) as $order ) {
		if ( $order instanceof WC_Order ) {
			$orders[] = E2M_WooCommerce_Helper::order_to_row( $order );
		}
	}

	return [
		'total'    => (int) ( $result->total ?? 0 ),
		'page'     => $page,
		'per_page' => $per_page,
		'orders'   => $orders,
	];
}
