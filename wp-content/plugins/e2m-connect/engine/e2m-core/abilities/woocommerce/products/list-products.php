<?php
/**
 * E2M Connect MCP - List Products.
 *
 * Returns a paginated, filterable list of WooCommerce products. Filters are a
 * deliberate subset of wc_get_products() args to keep the MCP schema small
 * and forward-compatible across WC versions.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-products', [
	'label'       => __( '[WooCommerce] List Products', 'e2mconnect' ),
	'description' => 'Returns a paginated list of WooCommerce products with optional filters for status, type, category, tag, and search.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'status'   => [ 'type' => 'string', 'enum' => [ 'any', 'publish', 'draft', 'pending', 'private' ], 'default' => 'any' ],
			'type'     => [ 'type' => 'string', 'description' => 'Product type slug: simple, grouped, external, variable.' ],
			'category' => [ 'type' => 'string', 'description' => 'Category slug to filter by.' ],
			'tag'      => [ 'type' => 'string', 'description' => 'Tag slug to filter by.' ],
			'search'   => [ 'type' => 'string', 'description' => 'Keyword match against name + SKU.' ],
			'orderby'  => [ 'type' => 'string', 'enum' => [ 'date', 'id', 'name', 'slug', 'price', 'popularity' ], 'default' => 'date' ],
			'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
		],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'total'    => [ 'type' => 'integer' ],
			'page'     => [ 'type' => 'integer' ],
			'per_page' => [ 'type' => 'integer' ],
			'products' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_list_products_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Products',
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
function e2m_engine_list_products_ability( array $input ) {
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
	if ( $status !== 'any' ) {
		$args['status'] = $status;
	}
	if ( ! empty( $input['type'] ) ) {
		$args['type'] = sanitize_key( (string) $input['type'] );
	}
	if ( ! empty( $input['category'] ) ) {
		$args['category'] = [ sanitize_title( (string) $input['category'] ) ];
	}
	if ( ! empty( $input['tag'] ) ) {
		$args['tag'] = [ sanitize_title( (string) $input['tag'] ) ];
	}
	if ( ! empty( $input['search'] ) ) {
		$args['s'] = sanitize_text_field( (string) $input['search'] );
	}

	$result = wc_get_products( $args );
	$products = [];
	foreach ( (array) $result->products as $product ) {
		if ( $product instanceof WC_Product ) {
			$products[] = E2M_WooCommerce_Helper::product_to_row( $product );
		}
	}

	return [
		'total'    => (int) ( $result->total ?? 0 ),
		'page'     => $page,
		'per_page' => $per_page,
		'products' => $products,
	];
}
