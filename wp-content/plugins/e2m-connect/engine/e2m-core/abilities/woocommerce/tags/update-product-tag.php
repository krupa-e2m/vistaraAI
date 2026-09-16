<?php
/**
 * E2M Connect MCP - Update Product Tag.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/update-product-tag', [
	'label'       => __( '[WooCommerce] Update Product Tag', 'e2mconnect' ),
	'description' => 'Updates a WooCommerce product tag.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'term_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'name'        => [ 'type' => 'string' ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
		],
		'required'             => [ 'term_id' ],
		'additionalProperties' => false,
	],

	'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],

	'execute_callback'    => 'e2m_engine_update_product_tag_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Update Product Tag',
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
function e2m_engine_update_product_tag_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
	$term    = get_term( $term_id, 'product_tag' );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'tag_not_found', __( 'Product tag not found.', 'e2mconnect' ) );
	}

	$args = [];
	if ( isset( $input['name'] ) ) {
		$args['name'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( isset( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}

	if ( ! empty( $args ) ) {
		$result = wp_update_term( $term_id, 'product_tag', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	$refreshed = get_term( $term_id, 'product_tag' );
	return E2M_WooCommerce_Helper::term_to_row( $refreshed instanceof WP_Term ? $refreshed : $term );
}
