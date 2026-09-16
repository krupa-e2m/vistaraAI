<?php
/**
 * E2M Connect MCP - Elementor Get Container Schema
 *
 * Reports the control schema for Elementor's flex/grid container element.
 * Shape matches elementor-get-widget-schema for consistency - the native
 * container element type lives under a different namespace than widgets
 * so it needs its own lookup path.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-get-container-schema', [
	'label'       => __( '[Elementor] Get Container Schema', 'e2mconnect' ),
	'description' => 'Returns the control schema for the Elementor container element (flex/grid).',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'                 => 'object',
		'properties'           => [],
		'additionalProperties' => true,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'element'  => [ 'type' => 'string' ],
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

	'execute_callback'    => 'e2m_engine_elementor_get_container_schema_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Get Container Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-get-container-schema ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_get_container_schema_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$elements_manager = \Elementor\Plugin::$instance->elements_manager ?? null;
	if ( ! $elements_manager ) {
		return new WP_Error( 'elements_manager_missing', __( 'Elementor elements manager is unavailable.', 'e2mconnect' ) );
	}

	$element = $elements_manager->get_element_types( 'container' );
	if ( ! $element ) {
		return new WP_Error( 'container_unsupported', __( 'This Elementor version does not expose a container element.', 'e2mconnect' ) );
	}

	try {
		$raw_controls = (array) $element->get_controls();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'element_controls_error', sprintf( 'Container threw while listing controls: %s', $e->getMessage() ) );
	}

	$controls = [];
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
		'element'  => 'container',
		'controls' => $controls,
	];
}
