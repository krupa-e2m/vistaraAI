<?php
/**
 * E2M Connect MCP - Elementor Get Style Schema
 *
 * Returns the full Elementor style schema so AI agents can apply
 * pixel-perfect settings. Includes all registered widget controls,
 * their style sections, control types, and allowed values — giving the
 * AI everything it needs to build valid payloads without guessing.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-get-style-schema', [
	'label'       => __( '[Elementor] Get Style Schema', 'e2mconnect' ),
	'description' => 'Returns the full Elementor style schema so the AI can apply pixel-perfect settings on any widget.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget_type' => [
				'type'        => 'string',
				'description' => 'Optional: limit the schema to a single widget type (e.g. "heading", "button", "image").',
			],
			'section' => [
				'type'        => 'string',
				'enum'        => [ 'all', 'style', 'content', 'advanced' ],
				'description' => 'Which control section to return. Defaults to "style".',
				'default'     => 'style',
			],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'widget_type' => [ 'type' => 'string' ],
			'section'     => [ 'type' => 'string' ],
			'schema'      => [ 'type' => 'object', 'additionalProperties' => true ],
			'controls'    => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_get_style_schema_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Get Style Schema',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-get-style-schema ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_get_style_schema_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$widget_type = sanitize_key( $input['widget_type'] ?? '' );
	$section     = sanitize_key( $input['section'] ?? 'style' );

	$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
	if ( ! $widgets_manager ) {
		return new WP_Error( 'elementor_not_ready', __( 'Elementor widgets manager is not available.', 'e2mconnect' ) );
	}

	// Single widget schema.
	if ( $widget_type !== '' ) {
		$widget = $widgets_manager->get_widget_types( $widget_type );
		if ( ! $widget ) {
			return new WP_Error( 'widget_not_found', sprintf(
				/* translators: %s: widget type slug */
				__( 'Widget type "%s" was not found.', 'e2mconnect' ),
				$widget_type
			) );
		}

		$controls = $widget->get_controls();
		$schema   = e2m_engine_elementor_filter_schema_by_section( $controls, $section );

		return [
			'widget_type' => $widget_type,
			'section'     => $section,
			'schema'      => $schema,
			'controls'    => array_values( $schema ),
		];
	}

	// All widgets — return a summary map (name → style control count).
	$all_widgets = $widgets_manager->get_widget_types();
	$schema      = [];
	foreach ( $all_widgets as $name => $widget ) {
		$controls         = $widget->get_controls();
		$filtered         = e2m_engine_elementor_filter_schema_by_section( $controls, $section );
		$schema[ $name ]  = [
			'name'          => $name,
			'title'         => $widget->get_title(),
			'control_count' => count( $filtered ),
		];
	}

	return [
		'widget_type' => 'all',
		'section'     => $section,
		'schema'      => $schema,
		'controls'    => [],
	];
}

/**
 * Filter a controls array to only those belonging to the requested section.
 *
 * @param array<string, mixed> $controls
 * @param string               $section
 * @return array<string, mixed>
 */
function e2m_engine_elementor_filter_schema_by_section( array $controls, string $section ): array {
	if ( $section === 'all' ) {
		return $controls;
	}

	$section_map = [
		'style'    => 'section_style',
		'content'  => 'section_',
		'advanced' => 'section_advanced',
	];

	$filtered     = [];
	$in_section   = false;

	foreach ( $controls as $key => $control ) {
		$ctrl_type = $control['type'] ?? '';

		if ( $ctrl_type === 'section' ) {
			$in_section = ( $section === 'all' )
				|| ( $section === 'style' && str_contains( $key, 'style' ) )
				|| ( $section === 'content' && ! str_contains( $key, 'style' ) && ! str_contains( $key, 'advanced' ) )
				|| ( $section === 'advanced' && str_contains( $key, 'advanced' ) );
		}

		if ( $in_section || $section === 'all' ) {
			$filtered[ $key ] = $control;
		}
	}

	return $filtered;
}
