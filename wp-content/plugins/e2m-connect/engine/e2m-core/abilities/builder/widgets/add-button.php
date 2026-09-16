<?php
/**
 * E2M Connect MCP - Add Button
 *
 * Inserts a button widget. Supports label, link URL, link target, and a
 * simple style variant (primary / secondary / outline) that each adapter
 * translates into its native preset.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-button', [
	'label'       => __( '[Widget] Button', 'e2mconnect' ),
	'description' => 'Inserts a button with text, URL, target, and style variant.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'text'   => [ 'type' => 'string', 'minLength' => 1 ],
				'url'    => [ 'type' => 'string' ],
				'target' => [ 'type' => 'string', 'enum' => [ '_self', '_blank' ], 'default' => '_self' ],
				'style'  => [ 'type' => 'string', 'enum' => [ 'primary', 'secondary', 'outline' ], 'default' => 'primary' ],
			]
		),
		'required'             => [ 'post_id', 'text' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_button_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Button' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_button_ability( array $input ) {
	$text   = sanitize_text_field( (string) ( $input['text'] ?? '' ) );
	$url    = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
	$target = isset( $input['target'] ) && $input['target'] === '_blank' ? '_blank' : '_self';
	$style  = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : 'primary';

	$settings = [
		'text'         => $text,
		'link'         => [ 'url' => $url, 'is_external' => $target === '_blank' ],
		'url'          => $url,
		'button_style' => $style,
		'className'    => 'e2m-btn-' . $style,
		'__inner_html' => sprintf(
			'<div class="wp-block-button"><a class="wp-block-button__link" href="%1$s" target="%2$s">%3$s</a></div>',
			esc_url( $url ),
			esc_attr( $target ),
			esc_html( $text )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'button', $settings );
}
