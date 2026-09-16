<?php
/**
 * E2M Connect MCP - Add Form
 *
 * Inserts a contact-style form widget. Fields are passed as an array of
 * { type, label, required }. For advanced behaviour (conditional logic,
 * payment, etc.) use the builder's own form UI - this ability is for the
 * canonical 80% case (contact, newsletter, quick feedback).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-form', [
	'label'       => __( '[Widget] Form', 'e2mconnect' ),
	'description' => 'Inserts a contact-style form widget with a list of fields and submit label.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'fields' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'type'        => [ 'type' => 'string', 'enum' => [ 'text', 'email', 'tel', 'textarea', 'select', 'checkbox' ] ],
							'label'       => [ 'type' => 'string' ],
							'required'    => [ 'type' => 'boolean', 'default' => false ],
							'placeholder' => [ 'type' => 'string' ],
							'options'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
						'required'             => [ 'type', 'label' ],
						'additionalProperties' => false,
					],
				],
				'submit_label' => [ 'type' => 'string', 'default' => 'Send' ],
				'success_message' => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id', 'fields' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_form_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Form' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_form_ability( array $input ) {
	$raw          = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [];
	$submit_label = sanitize_text_field( (string) ( $input['submit_label'] ?? 'Send' ) );
	$success      = wp_kses_post( (string) ( $input['success_message'] ?? '' ) );

	$fields     = [];
	$html_rows  = [];
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$type     = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
		$label    = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
		$required = ! empty( $row['required'] );
		$holder   = sanitize_text_field( (string) ( $row['placeholder'] ?? '' ) );
		$options  = isset( $row['options'] ) && is_array( $row['options'] ) ? array_map( 'sanitize_text_field', $row['options'] ) : [];

		$fields[] = [
			'field_type'  => $type,
			'field_label' => $label,
			'required'    => $required ? 'true' : '',
			'placeholder' => $holder,
			'field_options' => implode( "\n", $options ),
		];

		$html_rows[] = e2m_engine_render_form_field_html( $type, $label, $required, $holder, $options );
	}

	$settings = [
		'form_fields'   => $fields,
		'fields'        => $fields,
		'submit_label'  => $submit_label,
		'button_text'   => $submit_label,
		'success_message' => $success,
		'__inner_html'  => sprintf(
			'<form class="e2m-form">%s<button type="submit">%s</button></form>',
			implode( '', $html_rows ),
			esc_html( $submit_label )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'form', $settings );
}

/**
 * Render a single form field to HTML for preview / Gutenberg innerHTML.
 */
function e2m_engine_render_form_field_html( string $type, string $label, bool $required, string $placeholder, array $options ): string {
	$req = $required ? ' required' : '';

	switch ( $type ) {
		case 'textarea':
			return sprintf(
				'<label>%s<textarea placeholder="%s"%s></textarea></label>',
				esc_html( $label ),
				esc_attr( $placeholder ),
				$req
			);
		case 'select':
			$opts = '';
			foreach ( $options as $opt ) {
				$opts .= '<option>' . esc_html( $opt ) . '</option>';
			}
			return sprintf( '<label>%s<select%s>%s</select></label>', esc_html( $label ), $req, $opts );
		case 'checkbox':
			return sprintf( '<label><input type="checkbox"%s/> %s</label>', $req, esc_html( $label ) );
		default:
			return sprintf(
				'<label>%s<input type="%s" placeholder="%s"%s/></label>',
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $placeholder ),
				$req
			);
	}
}
