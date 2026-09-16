<?php
/**
 * E2M Connect MCP - Elementor Get Widget Schema
 *
 * Returns every control on a widget (type, label, default, options) so MCP
 * agents can construct legal settings payloads without guessing. The
 * output is intentionally lossy - we surface the fields that are useful
 * for code generation, not the entire Elementor internal schema.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-get-widget-schema', [
	'label'       => __( '[Elementor] Get Widget Schema', 'e2mconnect' ),
	'description' => 'Returns the control schema for a single Elementor widget (one entry per setting: type, label, default, options).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget_name' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Native Elementor widget name (e.g. "heading", "button").' ],
		],
		'required'             => [ 'widget_name' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget'   => [ 'type' => 'string' ],
			'controls' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name'    => [ 'type' => 'string' ],
						'type'    => [ 'type' => 'string' ],
						'label'   => [ 'type' => 'string' ],
						'default' => [ 'description' => 'Default value (mixed type).' ],
						'options' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'section' => [ 'type' => 'string' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_get_widget_schema_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Get Widget Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-get-widget-schema ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_get_widget_schema_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$manager = E2M_Elementor_Helper::widgets_manager();
	if ( ! $manager ) {
		return new WP_Error( 'widgets_manager_missing', __( 'Elementor widgets manager is unavailable.', 'e2mconnect' ) );
	}

	$widget_name = isset( $input['widget_name'] ) ? sanitize_key( (string) $input['widget_name'] ) : '';
	if ( $widget_name === '' ) {
		return new WP_Error( 'invalid_widget_name', __( 'widget_name is required.', 'e2mconnect' ) );
	}

	$widget = $manager->get_widget_types( $widget_name );
	if ( ! $widget ) {
		return new WP_Error( 'widget_not_found', __( 'No Elementor widget matches that name.', 'e2mconnect' ) );
	}

	$controls = [];

	// Some widgets require a runtime init pass before get_controls() returns
	// anything useful. Guard the call so buggy third-party widgets do not
	// bring down the whole response.
	try {
		$raw_controls = (array) $widget->get_controls();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'widget_controls_error', sprintf( 'Widget threw while listing controls: %s', $e->getMessage() ) );
	}

	foreach ( $raw_controls as $key => $control ) {
		if ( ! is_array( $control ) ) {
			continue;
		}
		$controls[] = [
			'name'    => (string) ( $control['name'] ?? $key ),
			'type'    => (string) ( $control['type'] ?? '' ),
			'label'   => (string) ( $control['label'] ?? '' ),
			'default' => $control['default'] ?? null,
			'options' => isset( $control['options'] ) && is_array( $control['options'] )
				? array_map( 'strval', array_keys( $control['options'] ) )
				: [],
			'section' => (string) ( $control['section'] ?? '' ),
		];
	}

	return [
		'widget'   => $widget_name,
		'controls' => $controls,
	];
}
