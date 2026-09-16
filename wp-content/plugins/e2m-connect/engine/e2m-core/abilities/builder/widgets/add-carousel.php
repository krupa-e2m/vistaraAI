<?php
/**
 * E2M Connect MCP - Add Carousel
 *
 * Inserts a horizontally scrolling carousel of images. Different from
 * add-slider (one full-width slide) because carousel rows show multiple
 * items per view and auto-advance one item at a time.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-carousel', [
	'label'       => __( '[Widget] Carousel', 'e2mconnect' ),
	'description' => 'Inserts a horizontal carousel showing multiple images per view.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'attachment_ids' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 100,
					'items'    => [ 'type' => 'integer', 'minimum' => 1 ],
				],
				'slides_to_show' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 3 ],
				'autoplay'       => [ 'type' => 'boolean', 'default' => false ],
			]
		),
		'required'             => [ 'post_id', 'attachment_ids' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_carousel_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Carousel' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_carousel_ability( array $input ) {
	$ids      = isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ? array_map( 'intval', $input['attachment_ids'] ) : [];
	$per_view = isset( $input['slides_to_show'] ) ? max( 1, min( 8, (int) $input['slides_to_show'] ) ) : 3;
	$autoplay = ! empty( $input['autoplay'] );

	$images = [];
	$html   = [];
	foreach ( $ids as $id ) {
		$url = (string) wp_get_attachment_url( $id );
		if ( $url === '' ) {
			continue;
		}
		$alt      = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$images[] = [ 'id' => $id, 'url' => $url, 'alt' => $alt ];
		$html[]   = sprintf( '<li class="e2m-carousel-item"><img src="%s" alt="%s"/></li>', esc_url( $url ), esc_attr( $alt ) );
	}

	$settings = [
		'carousel'       => $images,
		'wp_gallery'     => $images,
		'slides_to_show' => $per_view,
		'autoplay'       => $autoplay ? 'yes' : '',
		'__inner_html'   => sprintf(
			'<ul class="e2m-carousel" data-per-view="%d" data-autoplay="%s">%s</ul>',
			$per_view,
			$autoplay ? '1' : '0',
			implode( '', $html )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'carousel', $settings );
}
