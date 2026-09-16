<?php
/**
 * E2M Connect MCP - Add Pricing Table
 *
 * Inserts a pricing table widget with title, price, features array, and CTA.
 * Typically used inside a multi-column container to compare plans.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-pricing-table', [
	'label'       => __( '[Widget] Pricing Table', 'e2mconnect' ),
	'description' => 'Inserts a single pricing column with plan name, price, period, features list, and CTA button.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'plan_name'   => [ 'type' => 'string', 'minLength' => 1 ],
				'price'       => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Formatted price (e.g. "$29", "29.00").' ],
				'currency'    => [ 'type' => 'string', 'default' => '$' ],
				'period'      => [ 'type' => 'string', 'description' => 'e.g. "/month", "/year".' ],
				'features'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'cta_label'   => [ 'type' => 'string', 'default' => 'Choose plan' ],
				'cta_url'     => [ 'type' => 'string' ],
				'highlighted' => [ 'type' => 'boolean', 'default' => false ],
			]
		),
		'required'             => [ 'post_id', 'plan_name', 'price' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_pricing_table_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Pricing Table' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_pricing_table_ability( array $input ) {
	$plan     = sanitize_text_field( (string) ( $input['plan_name'] ?? '' ) );
	$price    = sanitize_text_field( (string) ( $input['price'] ?? '' ) );
	$currency = sanitize_text_field( (string) ( $input['currency'] ?? '$' ) );
	$period   = sanitize_text_field( (string) ( $input['period'] ?? '' ) );
	$features = isset( $input['features'] ) && is_array( $input['features'] )
		? array_map( 'sanitize_text_field', $input['features'] )
		: [];
	$cta_l    = sanitize_text_field( (string) ( $input['cta_label'] ?? 'Choose plan' ) );
	$cta_u    = isset( $input['cta_url'] ) ? esc_url_raw( (string) $input['cta_url'] ) : '';
	$highlighted = ! empty( $input['highlighted'] );

	$feature_html = '';
	$feature_rows = [];
	foreach ( $features as $f ) {
		$feature_html  .= '<li>' . esc_html( $f ) . '</li>';
		$feature_rows[] = [ 'item_text' => $f ];
	}

	$settings = [
		'heading'      => $plan,
		'price'        => $price,
		'currency_symbol' => $currency,
		'period'       => $period,
		'features_list' => $feature_rows,
		'button_text'  => $cta_l,
		'button_link'  => [ 'url' => $cta_u ],
		'highlighted'  => $highlighted ? 'yes' : '',
		'className'    => $highlighted ? 'e2m-pricing-table is-highlighted' : 'e2m-pricing-table',
		'__inner_html' => sprintf(
			'<div class="e2m-pricing-table%1$s"><h3>%2$s</h3><div class="e2m-pricing-price">%3$s%4$s%5$s</div><ul class="e2m-pricing-features">%6$s</ul>%7$s</div>',
			$highlighted ? ' is-highlighted' : '',
			esc_html( $plan ),
			esc_html( $currency ),
			esc_html( $price ),
			$period !== '' ? '<span class="e2m-pricing-period">' . esc_html( $period ) . '</span>' : '',
			$feature_html,
			$cta_u !== ''
				? '<a class="e2m-pricing-cta" href="' . esc_url( $cta_u ) . '">' . esc_html( $cta_l ) . '</a>'
				: ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'pricing-table', $settings );
}
