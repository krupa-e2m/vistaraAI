<?php
/**
 * E2M Connect MCP - List Product Tags.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/list-product-tags', [
	'label'       => __( '[WooCommerce] List Product Tags', 'e2mconnect' ),
	'description' => 'Returns WooCommerce product tags (product_tag taxonomy).',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'search'   => [ 'type' => 'string' ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ],
		],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'tags' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_list_product_tags_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'List Product Tags',
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
function e2m_engine_list_product_tags_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$args = [
		'taxonomy'   => 'product_tag',
		'hide_empty' => false,
		'number'     => isset( $input['per_page'] ) ? max( 1, min( 500, (int) $input['per_page'] ) ) : 100,
	];
	if ( ! empty( $input['search'] ) ) {
		$args['search'] = sanitize_text_field( (string) $input['search'] );
	}

	$terms = get_terms( $args );
	$rows  = [];
	if ( ! is_wp_error( $terms ) ) {
		foreach ( (array) $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$rows[] = E2M_WooCommerce_Helper::term_to_row( $term );
			}
		}
	}

	return [ 'tags' => $rows ];
}
