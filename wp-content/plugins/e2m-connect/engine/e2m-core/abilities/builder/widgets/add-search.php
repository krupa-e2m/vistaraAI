<?php
/**
 * E2M Connect MCP - Add Search
 *
 * Inserts a site search widget. Optional placeholder text and button label
 * let callers match the form to the page context ("Search the docs", etc.).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-search', [
	'label'       => __( '[Widget] Search', 'e2mconnect' ),
	'description' => 'Inserts a site search form widget with configurable placeholder and button label.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'placeholder'  => [ 'type' => 'string', 'default' => 'Search...' ],
				'button_label' => [ 'type' => 'string', 'default' => 'Search' ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_search_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Search' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_search_ability( array $input ) {
	$placeholder = sanitize_text_field( (string) ( $input['placeholder'] ?? 'Search...' ) );
	$button      = sanitize_text_field( (string) ( $input['button_label'] ?? 'Search' ) );

	$settings = [
		'placeholder'   => $placeholder,
		'button_text'   => $button,
		'label'         => $button,
		'showLabel'     => false,
		'buttonText'    => $button,
		'__inner_html'  => sprintf(
			'<form role="search" method="get" class="e2m-search" action="%s"><input type="search" name="s" placeholder="%s"/><button type="submit">%s</button></form>',
			esc_url( home_url( '/' ) ),
			esc_attr( $placeholder ),
			esc_html( $button )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'search', $settings );
}
