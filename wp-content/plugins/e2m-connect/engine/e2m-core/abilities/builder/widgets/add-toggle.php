<?php
/**
 * E2M Connect MCP - Add Toggle
 *
 * Inserts a single-item disclosure widget (collapsed summary that expands to
 * show detail content). Conceptually a one-item accordion.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-toggle', [
	'label'       => __( '[Widget] Toggle', 'e2mconnect' ),
	'description' => 'Inserts a single collapsible toggle with a title and body.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'title'   => [ 'type' => 'string', 'minLength' => 1 ],
				'content' => [ 'type' => 'string' ],
				'open'    => [ 'type' => 'boolean', 'default' => false ],
			]
		),
		'required'             => [ 'post_id', 'title' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_toggle_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Toggle' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_toggle_ability( array $input ) {
	$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$content = wp_kses_post( (string) ( $input['content'] ?? '' ) );
	$open    = ! empty( $input['open'] );

	$settings = [
		'tabs'         => [ [ 'tab_title' => $title, 'tab_content' => $content ] ],
		'title'        => $title,
		'content'      => $content,
		'default_open' => $open ? 'yes' : 'no',
		'__inner_html' => sprintf(
			'<details class="e2m-toggle"%s><summary>%s</summary><div>%s</div></details>',
			$open ? ' open' : '',
			esc_html( $title ),
			$content
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'toggle', $settings );
}
