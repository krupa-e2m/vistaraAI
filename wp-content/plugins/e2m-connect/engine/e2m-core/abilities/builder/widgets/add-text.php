<?php
/**
 * E2M Connect MCP - Add Text
 *
 * Inserts a paragraph / text-editor widget. Accepts raw HTML-safe content;
 * the adapter wraps it appropriately for each builder's storage format.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-text', [
	'label'       => __( '[Widget] Text', 'e2mconnect' ),
	'description' => 'Inserts a paragraph or rich-text widget. Supports basic HTML via wp_kses_post.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'content' => [ 'type' => 'string', 'minLength' => 1 ],
				'align'   => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right' ], 'default' => 'left' ],
			]
		),
		'required'             => [ 'post_id', 'content' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_text_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Text' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_text_ability( array $input ) {
	$content = wp_kses_post( (string) ( $input['content'] ?? '' ) );
	$align   = isset( $input['align'] ) ? sanitize_key( (string) $input['align'] ) : 'left';

	$settings = [
		'editor'       => $content,
		'text'         => wp_strip_all_tags( $content ),
		'align'        => $align,
		'__inner_html' => sprintf( '<p class="has-text-align-%s">%s</p>', esc_attr( $align ), $content ),
	];

	return e2m_engine_widget_shortcut_run( $input, 'text', $settings );
}
