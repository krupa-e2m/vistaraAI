<?php
/**
 * E2M Connect MCP - Add Icon
 *
 * Inserts a single icon widget. Accepts either an icon library reference
 * (library + name, e.g. fa-solid + "star") or inline SVG markup. Adapters
 * pick the field their native widget expects.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-icon', [
	'label'       => __( '[Widget] Icon', 'e2mconnect' ),
	'description' => 'Inserts an icon widget from a library (e.g. Font Awesome) or raw SVG markup.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'library' => [ 'type' => 'string', 'description' => 'Icon library slug (e.g. "fa-solid", "fa-brands", "dashicons").' ],
				'name'    => [ 'type' => 'string', 'description' => 'Icon name inside the library (e.g. "star").' ],
				'svg'     => [ 'type' => 'string', 'description' => 'Raw SVG markup (sanitised before storage).' ],
				'size_px' => [ 'type' => 'integer', 'minimum' => 8, 'maximum' => 512, 'default' => 32 ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_icon_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Icon' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_icon_ability( array $input ) {
	$library = isset( $input['library'] ) ? sanitize_key( (string) $input['library'] ) : '';
	$name    = isset( $input['name'] ) ? sanitize_key( (string) $input['name'] ) : '';
	$svg     = isset( $input['svg'] ) ? wp_kses( (string) $input['svg'], [
		'svg'    => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true ],
		'path'   => [ 'd' => true, 'fill' => true ],
		'circle' => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true ],
		'rect'   => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true ],
		'g'      => [ 'fill' => true ],
	] ) : '';
	$size    = isset( $input['size_px'] ) ? max( 8, min( 512, (int) $input['size_px'] ) ) : 32;

	$settings = [
		'selected_icon' => [ 'value' => $library . ' fa-' . $name, 'library' => $library ],
		'icon_library'  => $library,
		'icon_name'     => $name,
		'svg'           => $svg,
		'size'          => [ 'unit' => 'px', 'size' => $size ],
		'__inner_html'  => $svg !== ''
			? $svg
			: sprintf( '<i class="%s fa-%s" style="font-size:%dpx"></i>', esc_attr( $library ), esc_attr( $name ), $size ),
	];

	return e2m_engine_widget_shortcut_run( $input, 'icon', $settings );
}
