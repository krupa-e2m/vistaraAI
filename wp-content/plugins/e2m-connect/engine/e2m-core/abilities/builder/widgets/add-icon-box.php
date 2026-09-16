<?php
/**
 * E2M Connect MCP - Add Icon Box
 *
 * Inserts a combined icon + heading + description widget - a common
 * "feature" layout primitive. For builders without a dedicated icon-box
 * widget, the adapter falls back to a composite container.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-icon-box', [
	'label'       => __( '[Widget] Icon Box', 'e2mconnect' ),
	'description' => 'Inserts an icon + heading + description widget (feature tile).',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'icon'        => [ 'type' => 'string', 'description' => 'Icon library slug (e.g. "fa-solid fa-rocket").' ],
				'title'       => [ 'type' => 'string', 'minLength' => 1 ],
				'description' => [ 'type' => 'string' ],
				'link_url'    => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id', 'title' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_icon_box_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Icon Box' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_icon_box_ability( array $input ) {
	$icon        = sanitize_text_field( (string) ( $input['icon'] ?? '' ) );
	$title       = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$description = wp_kses_post( (string) ( $input['description'] ?? '' ) );
	$link        = isset( $input['link_url'] ) ? esc_url_raw( (string) $input['link_url'] ) : '';

	$settings = [
		'selected_icon'    => [ 'value' => $icon, 'library' => 'fa-solid' ],
		'icon'             => $icon,
		'title_text'       => $title,
		'description_text' => $description,
		'link'             => [ 'url' => $link ],
		'__inner_html'     => sprintf(
			'<div class="e2m-icon-box">%s<h3>%s</h3><p>%s</p>%s</div>',
			$icon !== '' ? '<span class="e2m-icon-box-icon"><i class="' . esc_attr( $icon ) . '"></i></span>' : '',
			esc_html( $title ),
			$description,
			$link !== '' ? '<a href="' . esc_url( $link ) . '" class="e2m-icon-box-link">Learn more</a>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'icon-box', $settings );
}
