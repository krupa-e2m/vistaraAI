<?php
/**
 * E2M Connect MCP - Create Product Tag.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/create-product-tag', [
	'label'       => __( '[WooCommerce] Create Product Tag', 'e2mconnect' ),
	'description' => 'Creates a WooCommerce product tag.',
	'category'      => 'woocommerce-e2m',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'name'        => [ 'type' => 'string', 'minLength' => 1 ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
		],
		'required'             => [ 'name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],

	'execute_callback'    => 'e2m_engine_create_product_tag_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Create Product Tag',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_create_product_tag_ability( array $input ) {
	$err = E2M_WooCommerce_Helper::require_active();
	if ( $err instanceof WP_Error ) {
		return $err;
	}

	$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
	if ( $name === '' ) {
		return new WP_Error( 'invalid_name', __( 'A non-empty tag name is required.', 'e2mconnect' ) );
	}

	$args = [];
	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}

	$result = wp_insert_term( $name, 'product_tag', $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$term = get_term( (int) $result['term_id'], 'product_tag' );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'tag_lookup_failed', __( 'Tag was created but could not be re-read.', 'e2mconnect' ) );
	}

	return E2M_WooCommerce_Helper::term_to_row( $term );
}
