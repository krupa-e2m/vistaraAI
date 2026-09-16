<?php
/**
 * E2M Connect MCP - Update Product Category.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-product-category', [
	'label'       => __( '[WooCommerce] Update Category', 'e2mconnect' ),
	'description' => 'Updates a WooCommerce product category. Only supplied fields are modified.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'name'        => [ 'type' => 'string' ],
			'slug'        => [ 'type' => 'string' ],
			'parent'      => [ 'type' => 'integer', 'minimum' => 0 ],
			'description' => [ 'type' => 'string' ],
		],
		'required'             => [ 'term_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'                 => 'object',
		'additionalProperties' => true,
	],

	'execute_callback'    => 'e2m_engine_update_product_category_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Product Category',
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
function e2m_engine_update_product_category_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
	$term    = get_term( $term_id, 'product_cat' );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'category_not_found', __( 'Product category not found.', 'e2mconnect' ) );
	}

	$args = [];
	if ( isset( $input['name'] ) ) {
		$args['name'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( isset( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['parent'] = (int) $input['parent'];
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}

	if ( ! empty( $args ) ) {
		$result = wp_update_term( $term_id, 'product_cat', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	$refreshed = get_term( $term_id, 'product_cat' );
	return E2M_WooCommerce_Helper::term_to_row( $refreshed instanceof WP_Term ? $refreshed : $term );
}
