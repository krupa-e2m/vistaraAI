<?php
/**
 * E2M Connect MCP - Add Tabs
 *
 * Inserts a tabs widget. Panels are provided as [ { title, content } ];
 * each adapter serialises the list into the shape its widget expects.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-tabs', [
	'label'       => __( '[Widget] Tabs', 'e2mconnect' ),
	'description' => 'Inserts a tabs widget with an ordered list of title/content panels.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'tabs' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 20,
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
		'required'             => [ 'post_id', 'tabs' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_tabs_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Tabs' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_tabs_ability( array $input ) {
	$raw = isset( $input['tabs'] ) && is_array( $input['tabs'] ) ? $input['tabs'] : [];

	$panels    = [];
	$html_tabs = [];
	$html_body = [];
	foreach ( $raw as $idx => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title   = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$content = wp_kses_post( (string) ( $row['content'] ?? '' ) );
		if ( $title === '' ) {
			continue;
		}
		$panels[] = [ 'tab_title' => $title, 'tab_content' => $content ];
		$html_tabs[] = sprintf( '<li role="tab" data-e2m-tab="%d">%s</li>', $idx, esc_html( $title ) );
		$html_body[] = sprintf( '<div role="tabpanel" data-e2m-panel="%d">%s</div>', $idx, $content );
	}

	$settings = [
		'tabs'         => $panels,
		'__inner_html' => '<div class="e2m-tabs"><ul role="tablist">' . implode( '', $html_tabs ) . '</ul>' . implode( '', $html_body ) . '</div>',
	];

	return e2m_engine_widget_shortcut_run( $input, 'tabs', $settings );
}
