<?php
/**
 * E2M Connect MCP - Add Social Icons
 *
 * Inserts a horizontal row of social links. Each entry specifies a network
 * slug (twitter, facebook, instagram, linkedin, youtube, github, etc.) and
 * a target URL. Icons are resolved by the builder.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-social-icons', [
	'label'       => __( '[Widget] Social Icons', 'e2mconnect' ),
	'description' => 'Inserts a row of social network icons linking to external profiles.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'links' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'network' => [ 'type' => 'string', 'minLength' => 1 ],
							'url'     => [ 'type' => 'string', 'minLength' => 1 ],
						],
						'required'             => [ 'network', 'url' ],
						'additionalProperties' => false,
					],
				],
				'size_px' => [ 'type' => 'integer', 'minimum' => 8, 'maximum' => 128, 'default' => 24 ],
			]
		),
		'required'             => [ 'post_id', 'links' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_social_icons_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Social Icons' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_social_icons_ability( array $input ) {
	$raw  = isset( $input['links'] ) && is_array( $input['links'] ) ? $input['links'] : [];
	$size = isset( $input['size_px'] ) ? max( 8, min( 128, (int) $input['size_px'] ) ) : 24;

	$links = [];
	$html  = [];
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$network = sanitize_key( (string) ( $row['network'] ?? '' ) );
		$url     = esc_url_raw( (string) ( $row['url'] ?? '' ) );
		if ( $network === '' || $url === '' ) {
			continue;
		}
		$links[] = [ 'social' => $network, 'link' => [ 'url' => $url ] ];
		$html[]  = sprintf(
			'<a class="e2m-social e2m-social-%1$s" href="%2$s" aria-label="%1$s"><span class="e2m-social-label">%1$s</span></a>',
			esc_attr( $network ),
			esc_url( $url )
		);
	}

	$settings = [
		'social_icon_list' => $links,
		'icon'             => [ 'size' => $size ],
		'size_px'          => $size,
		'__inner_html'     => '<div class="e2m-social-icons" style="--e2m-social-size:' . $size . 'px">' . implode( '', $html ) . '</div>',
	];

	return e2m_engine_widget_shortcut_run( $input, 'social-icons', $settings );
}
