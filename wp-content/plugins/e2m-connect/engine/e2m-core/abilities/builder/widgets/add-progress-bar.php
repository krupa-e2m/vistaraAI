<?php
/**
 * E2M Connect MCP - Add Progress Bar
 *
 * Inserts a progress / skill-bar widget showing percent completion. Label
 * and colour preset are optional.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-progress-bar', [
	'label'       => __( '[Widget] Progress Bar', 'e2mconnect' ),
	'description' => 'Inserts a progress bar with a percentage value, optional label, and colour preset.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'label'   => [ 'type' => 'string' ],
				'percent' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
				'color'   => [ 'type' => 'string', 'enum' => [ 'default', 'primary', 'success', 'warning', 'danger' ], 'default' => 'primary' ],
			]
		),
		'required'             => [ 'post_id', 'percent' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_progress_bar_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Progress Bar' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_progress_bar_ability( array $input ) {
	$label   = sanitize_text_field( (string) ( $input['label'] ?? '' ) );
	$percent = isset( $input['percent'] ) ? max( 0, min( 100, (int) $input['percent'] ) ) : 0;
	$color   = isset( $input['color'] ) ? sanitize_key( (string) $input['color'] ) : 'primary';

	$settings = [
		'title'        => $label,
		'percent'      => [ 'unit' => '%', 'size' => $percent ],
		'value'        => $percent,
		'progress_type'=> $color,
		'className'    => 'e2m-progress e2m-progress-' . $color,
		'__inner_html' => sprintf(
			'<div class="e2m-progress e2m-progress-%1$s" role="progressbar" aria-valuenow="%2$d" aria-valuemin="0" aria-valuemax="100">%3$s<div class="e2m-progress-bar" style="width:%2$d%%"></div></div>',
			esc_attr( $color ),
			$percent,
			$label !== '' ? '<span class="e2m-progress-label">' . esc_html( $label ) . '</span>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'progress-bar', $settings );
}
