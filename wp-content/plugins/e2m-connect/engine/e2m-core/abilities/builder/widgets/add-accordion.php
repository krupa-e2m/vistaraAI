<?php
/**
 * E2M Connect MCP - Add Accordion
 *
 * Inserts an accordion widget with a list of collapsible sections. Each
 * section has a heading and body content.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-accordion', [
	'label'       => __( '[Widget] Accordion', 'e2mconnect' ),
	'description' => 'Inserts an accordion widget with collapsible title/content sections.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'items' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'title'   => [ 'type' => 'string', 'minLength' => 1 ],
							'content' => [ 'type' => 'string' ],
						],
						'required'             => [ 'title' ],
						'additionalProperties' => false,
					],
				],
			]
		),
		'required'             => [ 'post_id', 'items' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_accordion_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Accordion' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_accordion_ability( array $input ) {
	$raw = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];

	$panels = [];
	$html   = [];
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title   = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$content = wp_kses_post( (string) ( $row['content'] ?? '' ) );
		if ( $title === '' ) {
			continue;
		}
		$panels[] = [ 'tab_title' => $title, 'tab_content' => $content ];
		$html[]   = sprintf(
			'<details class="e2m-accordion-item"><summary>%s</summary><div>%s</div></details>',
			esc_html( $title ),
			$content
		);
	}

	$settings = [
		'tabs'         => $panels,
		'items'        => $panels,
		'__inner_html' => '<div class="e2m-accordion">' . implode( '', $html ) . '</div>',
	];

	return e2m_engine_widget_shortcut_run( $input, 'accordion', $settings );
}
