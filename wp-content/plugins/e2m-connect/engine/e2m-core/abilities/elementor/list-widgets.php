<?php
/**
 * E2M Connect MCP - Elementor List Widgets
 *
 * Enumerates every widget registered with Elementor's widgets manager on
 * this site. Useful as the first call when an MCP agent is composing a
 * page and wants to know which widgets - free and Pro - are available.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-list-widgets', [
	'label'       => __( '[Elementor] List Widgets', 'e2mconnect' ),
	'description' => 'Lists every widget registered with Elementor, including name, label, icon, and category membership.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'category' => [ 'type' => 'string', 'description' => 'Filter to a single Elementor category slug (e.g. "basic", "pro-elements").' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'widgets' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name'       => [ 'type' => 'string' ],
						'title'      => [ 'type' => 'string' ],
						'icon'       => [ 'type' => 'string' ],
						'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'keywords'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'is_pro'     => [ 'type' => 'boolean' ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_list_widgets_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: List Widgets',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-list-widgets ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_list_widgets_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$manager = E2M_Elementor_Helper::widgets_manager();
	if ( ! $manager ) {
		return new WP_Error( 'widgets_manager_missing', __( 'Elementor widgets manager is unavailable.', 'e2mconnect' ) );
	}

	$filter  = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
	$widgets = [];

	foreach ( $manager->get_widget_types() as $widget ) {
		$categories = (array) ( method_exists( $widget, 'get_categories' ) ? $widget->get_categories() : [] );
		if ( $filter !== '' && ! in_array( $filter, $categories, true ) ) {
			continue;
		}

		$name = (string) $widget->get_name();
		$widgets[] = [
			'name'       => $name,
			'title'      => (string) $widget->get_title(),
			'icon'       => method_exists( $widget, 'get_icon' ) ? (string) $widget->get_icon() : '',
			'categories' => $categories,
			'keywords'   => method_exists( $widget, 'get_keywords' ) ? (array) $widget->get_keywords() : [],
			'is_pro'     => str_starts_with( $name, 'wp-' ) === false && str_starts_with( strtolower( get_class( $widget ) ), 'elementorpro' ),
		];
	}

	return [ 'widgets' => $widgets ];
}
