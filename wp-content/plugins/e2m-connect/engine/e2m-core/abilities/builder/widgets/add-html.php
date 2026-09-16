<?php
/**
 * E2M Connect MCP - Add HTML
 *
 * Escape hatch for arbitrary HTML. Content is sanitised with wp_kses_post
 * before storage so we keep MCP-originated payloads free of executable
 * scripts by default. Use add-custom-js (roadmap) for script injection.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-html', [
	'label'       => __( '[Widget] HTML Block', 'e2mconnect' ),
	'description' => 'Inserts a raw HTML block. Content is passed through wp_kses_post so script tags and unsafe attributes are stripped.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'html' => [ 'type' => 'string', 'minLength' => 1 ],
			]
		),
		'required'             => [ 'post_id', 'html' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_html_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add HTML Block' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_html_ability( array $input ) {
	$html = wp_kses_post( (string) ( $input['html'] ?? '' ) );

	$settings = [
		'html'         => $html,
		'code'         => $html,
		'__inner_html' => $html,
	];

	return e2m_engine_widget_shortcut_run( $input, 'html', $settings );
}
