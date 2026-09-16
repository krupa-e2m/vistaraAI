<?php
/**
 * E2M Connect MCP - Add Counter
 *
 * Inserts an animated numeric counter. The front-end animation is the
 * builder's own JavaScript - we only store the start/end/duration values.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-counter', [
	'label'       => __( '[Widget] Counter', 'e2mconnect' ),
	'description' => 'Inserts a numeric counter that animates from starting_number to ending_number.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'starting_number' => [ 'type' => 'number', 'default' => 0 ],
				'ending_number'   => [ 'type' => 'number' ],
				'duration_ms'     => [ 'type' => 'integer', 'minimum' => 100, 'maximum' => 60000, 'default' => 2000 ],
				'prefix'          => [ 'type' => 'string' ],
				'suffix'          => [ 'type' => 'string' ],
				'title'           => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id', 'ending_number' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_counter_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Counter' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_counter_ability( array $input ) {
	$start    = (float) ( $input['starting_number'] ?? 0 );
	$end      = (float) ( $input['ending_number'] ?? 0 );
	$duration = isset( $input['duration_ms'] ) ? max( 100, min( 60000, (int) $input['duration_ms'] ) ) : 2000;
	$prefix   = sanitize_text_field( (string) ( $input['prefix'] ?? '' ) );
	$suffix   = sanitize_text_field( (string) ( $input['suffix'] ?? '' ) );
	$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

	$settings = [
		'starting_number' => $start,
		'ending_number'   => $end,
		'duration'        => $duration,
		'title'           => $title,
		'prefix'          => $prefix,
		'suffix'          => $suffix,
		'__inner_html'    => sprintf(
			'<div class="e2m-counter" data-start="%s" data-end="%s" data-duration="%d">%s<span class="e2m-counter-value">%s</span>%s%s</div>',
			esc_attr( (string) $start ),
			esc_attr( (string) $end ),
			$duration,
			$prefix !== '' ? esc_html( $prefix ) : '',
			esc_html( (string) $end ),
			$suffix !== '' ? esc_html( $suffix ) : '',
			$title !== '' ? '<div class="e2m-counter-title">' . esc_html( $title ) . '</div>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'counter', $settings );
}
