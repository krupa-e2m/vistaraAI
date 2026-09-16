<?php
/**
 * E2M Connect MCP - Add Slider
 *
 * Inserts a slides-style widget (one slide visible at a time). Each slide
 * carries a background image and optional heading + description overlay.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-slider', [
	'label'       => __( '[Widget] Slider', 'e2mconnect' ),
	'description' => 'Inserts a full-width slider with image backgrounds and optional heading/description overlay per slide.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'slides' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'image_url'   => [ 'type' => 'string' ],
							'heading'     => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'link'        => [ 'type' => 'string' ],
						],
						'additionalProperties' => false,
					],
				],
				'autoplay'      => [ 'type' => 'boolean', 'default' => true ],
				'interval_ms'   => [ 'type' => 'integer', 'minimum' => 1000, 'maximum' => 20000, 'default' => 5000 ],
			]
		),
		'required'             => [ 'post_id', 'slides' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_slider_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Slider' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_slider_ability( array $input ) {
	$raw      = isset( $input['slides'] ) && is_array( $input['slides'] ) ? $input['slides'] : [];
	$autoplay = ! empty( $input['autoplay'] );
	$interval = isset( $input['interval_ms'] ) ? max( 1000, min( 20000, (int) $input['interval_ms'] ) ) : 5000;

	$slides = [];
	$html   = [];
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$slide = [
			'background_image' => [ 'url' => esc_url_raw( (string) ( $row['image_url'] ?? '' ) ) ],
			'heading'          => sanitize_text_field( (string) ( $row['heading'] ?? '' ) ),
			'description'      => wp_kses_post( (string) ( $row['description'] ?? '' ) ),
			'link'             => [ 'url' => esc_url_raw( (string) ( $row['link'] ?? '' ) ) ],
		];
		$slides[] = $slide;
		$html[]   = sprintf(
			'<li class="e2m-slider-slide" style="background-image:url(%s)"><div class="e2m-slider-inner">%s%s</div></li>',
			esc_url( (string) $slide['background_image']['url'] ),
			$slide['heading'] !== '' ? '<h3>' . esc_html( $slide['heading'] ) . '</h3>' : '',
			$slide['description'] !== '' ? '<div class="e2m-slider-desc">' . $slide['description'] . '</div>' : ''
		);
	}

	$settings = [
		'slides'       => $slides,
		'autoplay'     => $autoplay ? 'yes' : '',
		'pause_speed'  => $interval,
		'__inner_html' => sprintf(
			'<ul class="e2m-slider" data-autoplay="%s" data-interval="%d">%s</ul>',
			$autoplay ? '1' : '0',
			$interval,
			implode( '', $html )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'slider', $settings );
}
