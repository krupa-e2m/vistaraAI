<?php
/**
 * E2M Connect MCP - Add Heading
 *
 * Inserts a heading widget at the top level (or inside parent_id). Accepts
 * plain text + optional level (h1-h6); the adapter maps these to builder-
 * native settings.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-heading', [
	'label'       => __( '[Widget] Heading', 'e2mconnect' ),
	'description' => 'Inserts a heading widget with configurable text, level (h1-h6), and alignment.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'text'  => [ 'type' => 'string', 'minLength' => 1 ],
				'level' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 2 ],
				'align' => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right' ], 'default' => 'left' ],
			]
		),
		'required'             => [ 'post_id', 'text' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_heading_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Heading' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_heading_ability( array $input ) {
	$level = isset( $input['level'] ) ? max( 1, min( 6, (int) $input['level'] ) ) : 2;
	$tag   = 'h' . $level;
	$text  = sanitize_text_field( (string) ( $input['text'] ?? '' ) );
	$align = isset( $input['align'] ) ? sanitize_key( (string) $input['align'] ) : 'left';

	$settings = [
		'title'        => $text,
		'text'         => $text,
		'header_size'  => $tag,
		'level'        => $level,
		'align'        => $align,
		'__inner_html' => sprintf( '<%1$s class="has-text-align-%2$s">%3$s</%1$s>', $tag, esc_attr( $align ), esc_html( $text ) ),
	];

	return e2m_engine_widget_shortcut_run( $input, 'heading', $settings );
}
