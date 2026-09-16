<?php
/**
 * E2M Connect MCP - Add CTA
 *
 * Inserts a call-to-action widget: a heading + supporting copy + button.
 * Builders that lack a dedicated CTA widget receive a composite of heading
 * + text + button inside a container via their make_widget fallback.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-cta', [
	'label'       => __( '[Widget] Call-to-Action', 'e2mconnect' ),
	'description' => 'Inserts a CTA widget with heading, description, and action button.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'heading'      => [ 'type' => 'string', 'minLength' => 1 ],
				'description'  => [ 'type' => 'string' ],
				'button_label' => [ 'type' => 'string', 'minLength' => 1 ],
				'button_url'   => [ 'type' => 'string' ],
				'style'        => [ 'type' => 'string', 'enum' => [ 'default', 'inverse' ], 'default' => 'default' ],
			]
		),
		'required'             => [ 'post_id', 'heading', 'button_label' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_cta_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Call-to-Action' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_cta_ability( array $input ) {
	$heading      = sanitize_text_field( (string) ( $input['heading'] ?? '' ) );
	$description  = wp_kses_post( (string) ( $input['description'] ?? '' ) );
	$button_label = sanitize_text_field( (string) ( $input['button_label'] ?? '' ) );
	$button_url   = isset( $input['button_url'] ) ? esc_url_raw( (string) $input['button_url'] ) : '';
	$style        = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : 'default';

	$settings = [
		'title'        => $heading,
		'description'  => $description,
		'button'       => $button_label,
		'link'         => [ 'url' => $button_url ],
		'button_text'  => $button_label,
		'button_link'  => $button_url,
		'style'        => $style,
		'className'    => 'e2m-cta e2m-cta-' . $style,
		'__inner_html' => sprintf(
			'<div class="e2m-cta e2m-cta-%1$s"><h2>%2$s</h2>%3$s<a class="e2m-cta-button" href="%4$s">%5$s</a></div>',
			esc_attr( $style ),
			esc_html( $heading ),
			$description !== '' ? '<div class="e2m-cta-desc">' . $description . '</div>' : '',
			esc_url( $button_url ),
			esc_html( $button_label )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'cta', $settings );
}
