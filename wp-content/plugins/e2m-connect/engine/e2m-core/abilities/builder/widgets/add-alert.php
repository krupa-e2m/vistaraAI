<?php
/**
 * E2M Connect MCP - Add Alert
 *
 * Inserts a notice / callout widget with a semantic severity (info, success,
 * warning, danger). Each adapter maps the severity to its native preset.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-alert', [
	'label'       => __( '[Widget] Alert', 'e2mconnect' ),
	'description' => 'Inserts an alert / callout widget with a severity (info, success, warning, danger).',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'title'    => [ 'type' => 'string' ],
				'message'  => [ 'type' => 'string', 'minLength' => 1 ],
				'severity' => [ 'type' => 'string', 'enum' => [ 'info', 'success', 'warning', 'danger' ], 'default' => 'info' ],
			]
		),
		'required'             => [ 'post_id', 'message' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_alert_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Alert' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_alert_ability( array $input ) {
	$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$message  = wp_kses_post( (string) ( $input['message'] ?? '' ) );
	$severity = isset( $input['severity'] ) ? sanitize_key( (string) $input['severity'] ) : 'info';

	$settings = [
		'alert_type'         => $severity,
		'alert_title'        => $title,
		'alert_description'  => $message,
		'title'              => $title,
		'message'            => $message,
		'className'          => 'e2m-alert e2m-alert-' . $severity,
		'__inner_html'       => sprintf(
			'<div class="e2m-alert e2m-alert-%s" role="alert">%s<div>%s</div></div>',
			esc_attr( $severity ),
			$title !== '' ? '<strong>' . esc_html( $title ) . '</strong>' : '',
			$message
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'alert', $settings );
}
