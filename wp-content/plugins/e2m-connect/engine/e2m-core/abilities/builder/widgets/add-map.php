<?php
/**
 * E2M Connect MCP - Add Map
 *
 * Inserts a map widget. Takes either a free-form address (for builders
 * that geocode server-side) or lat/lng coordinates plus zoom.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-map', [
	'label'       => __( '[Widget] Map', 'e2mconnect' ),
	'description' => 'Inserts a map widget. Supports address strings or explicit lat/lng coordinates.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'address' => [ 'type' => 'string' ],
				'lat'     => [ 'type' => 'number' ],
				'lng'     => [ 'type' => 'number' ],
				'zoom'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 21, 'default' => 13 ],
				'height_px' => [ 'type' => 'integer', 'minimum' => 100, 'maximum' => 2000, 'default' => 400 ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_map_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Map' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_map_ability( array $input ) {
	$address = sanitize_text_field( (string) ( $input['address'] ?? '' ) );
	$lat     = isset( $input['lat'] ) ? (float) $input['lat'] : null;
	$lng     = isset( $input['lng'] ) ? (float) $input['lng'] : null;
	$zoom    = isset( $input['zoom'] ) ? max( 1, min( 21, (int) $input['zoom'] ) ) : 13;
	$height  = isset( $input['height_px'] ) ? max( 100, min( 2000, (int) $input['height_px'] ) ) : 400;

	if ( $address === '' && ( $lat === null || $lng === null ) ) {
		return new WP_Error( 'missing_location', __( 'Provide address or lat+lng.', 'e2mconnect' ) );
	}

	$iframe_src = $address !== ''
		? 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed&z=' . $zoom
		: sprintf( 'https://www.google.com/maps?q=%F,%F&z=%d&output=embed', (float) $lat, (float) $lng, $zoom );

	$settings = [
		'address'      => $address,
		'location'     => [ 'lat' => $lat, 'lng' => $lng ],
		'lat'          => $lat,
		'lng'          => $lng,
		'zoom'         => [ 'size' => $zoom ],
		'height'       => [ 'unit' => 'px', 'size' => $height ],
		'__inner_html' => sprintf(
			'<iframe class="e2m-map" src="%s" style="width:100%%;height:%dpx;border:0" loading="lazy" allowfullscreen></iframe>',
			esc_url( $iframe_src ),
			$height
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'map', $settings );
}
