<?php
/**
 * E2M Connect MCP - Add Icon List
 *
 * Inserts a bulleted list where each item can carry an icon + text + link.
 * Items are passed as an array; each becomes a row in the native widget.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-icon-list', [
	'label'       => __( '[Widget] Icon List', 'e2mconnect' ),
	'description' => 'Inserts an icon list widget with text + optional icon/link per row.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'items' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'text' => [ 'type' => 'string', 'minLength' => 1 ],
							'icon' => [ 'type' => 'string' ],
							'url'  => [ 'type' => 'string' ],
						],
						'required'             => [ 'text' ],
						'additionalProperties' => false,
					],
				],
			]
		),
		'required'             => [ 'post_id', 'items' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_icon_list_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Icon List' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_icon_list_ability( array $input ) {
	$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];

	$normalised = [];
	$html_rows  = [];
	foreach ( $items as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$text = sanitize_text_field( (string) ( $row['text'] ?? '' ) );
		if ( $text === '' ) {
			continue;
		}
		$icon = isset( $row['icon'] ) ? sanitize_text_field( (string) $row['icon'] ) : '';
		$url  = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';

		$normalised[] = [
			'text'          => $text,
			'selected_icon' => [ 'value' => $icon, 'library' => 'fa-solid' ],
			'icon'          => $icon,
			'link'          => [ 'url' => $url ],
		];

		$html_rows[] = '<li>'
			. ( $icon !== '' ? '<span class="e2m-icon-list-icon">' . esc_html( $icon ) . '</span>' : '' )
			. ( $url !== '' ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text ) )
			. '</li>';
	}

	$settings = [
		'icon_list'    => $normalised,
		'items'        => $normalised,
		'__inner_html' => '<ul class="wp-block-list e2m-icon-list">' . implode( '', $html_rows ) . '</ul>',
	];

	return e2m_engine_widget_shortcut_run( $input, 'icon-list', $settings );
}
