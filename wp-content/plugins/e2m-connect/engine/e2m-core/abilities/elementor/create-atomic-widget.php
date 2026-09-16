<?php
/**
 * E2M Connect MCP - Elementor Create Atomic Widget
 *
 * Programmatically create Elementor 3 atomic widgets — the new
 * container-based architecture where widgets are composed of atomic
 * style props. Inserts a fully-configured widget into a specified
 * container on a post.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-create-atomic-widget', [
	'label'       => __( '[Elementor] Create Atomic Widget', 'e2mconnect' ),
	'description' => 'Programmatically create an Elementor 3 atomic widget inside a container on a post or page.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [
				'type'        => 'integer',
				'description' => 'The post/page ID to insert the widget into.',
			],
			'container_id' => [
				'type'        => 'string',
				'description' => 'Elementor container element ID to insert the widget into. Leave empty to append to the page root.',
			],
			'widget_type' => [
				'type'        => 'string',
				'description' => 'Elementor widget type slug (e.g. "heading", "button", "text-editor", "image").',
			],
			'settings' => [
				'type'                 => 'object',
				'description'          => 'Widget settings payload (same structure Elementor stores internally).',
				'additionalProperties' => true,
			],
			'insert_position' => [
				'type'        => 'string',
				'enum'        => [ 'first', 'last' ],
				'description' => 'Whether to insert at the first or last position in the container. Defaults to "last".',
				'default'     => 'last',
			],
		],
		'required'             => [ 'post_id', 'widget_type' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id'      => [ 'type' => 'integer' ],
			'widget_id'    => [ 'type' => 'string' ],
			'widget_type'  => [ 'type' => 'string' ],
			'container_id' => [ 'type' => 'string', 'description' => 'Container ID the widget was inserted into. May be auto-generated if container_id was not specified.' ],
			'inserted'     => [ 'type' => 'boolean' ],
			'auto_wrapped'  => [ 'type' => 'boolean', 'description' => 'True when an auto-generated container was created to wrap the widget (no container_id was specified).' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_create_atomic_widget_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Create Atomic Widget',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-create-atomic-widget ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_create_atomic_widget_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_elementor();
	if ( $guard !== null ) {
		return $guard;
	}

	$post_id = (int) ( $input['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post ID.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$widget_type     = sanitize_text_field( $input['widget_type'] ?? '' );
	$container_id    = sanitize_key( $input['container_id'] ?? '' );
	$settings        = is_array( $input['settings'] ?? null ) ? $input['settings'] : [];
	$insert_position = in_array( $input['insert_position'] ?? 'last', [ 'first', 'last' ], true )
		? $input['insert_position']
		: 'last';

	if ( $widget_type === '' ) {
		return new WP_Error( 'missing_widget_type', __( 'widget_type is required.', 'e2mconnect' ) );
	}

	// Validate widget type exists.
	$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
	if ( $widgets_manager && ! $widgets_manager->get_widget_types( $widget_type ) ) {
		return new WP_Error( 'invalid_widget_type', sprintf(
			/* translators: %s: widget type slug */
			__( 'Widget type "%s" is not registered in Elementor.', 'e2mconnect' ),
			$widget_type
		) );
	}

	// Build the new widget element.
	$widget_id  = substr( md5( uniqid( '', true ) ), 0, 7 );
	$new_widget = [
		'id'       => $widget_id,
		'elType'   => 'widget',
		'widgetType' => $widget_type,
		'settings' => $settings,
		'elements' => [],
	];

	// Load current data and insert.
	$raw_data = get_post_meta( $post_id, '_elementor_data', true );
	$data     = is_string( $raw_data ) ? json_decode( $raw_data, true ) : [];
	$data     = is_array( $data ) ? $data : [];

	if ( $container_id === '' ) {
		// Widgets MUST live inside a container/section — inserting at root
		// causes Elementor's editor to throw errors. Auto-wrap in a container.
		$container_wrap_id = substr( md5( uniqid( 'wrap_', true ) ), 0, 7 );
		$container_wrap    = [
			'id'       => $container_wrap_id,
			'elType'   => 'container',
			'settings' => [ 'layout' => 'default' ],
			'elements' => [ $new_widget ],
		];

		if ( $insert_position === 'first' ) {
			array_unshift( $data, $container_wrap );
		} else {
			$data[] = $container_wrap;
		}

		// Report the auto-created container so callers can reference it later.
		$container_id = $container_wrap_id;
	} else {
		$data = e2m_engine_elementor_insert_into_container( $data, $container_id, $new_widget, $insert_position );
	}

	// wp_slash() compensates for WP's auto-unslash so JSON stays valid in the DB.
	$encoded = wp_json_encode( $data );
	update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
	delete_post_meta( $post_id, '_elementor_css' );

	// Update Elementor's "built with" meta so the editor recognises this post.
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

	return [
		'post_id'      => $post_id,
		'widget_id'    => $widget_id,
		'widget_type'  => $widget_type,
		'container_id' => $container_id,
		'inserted'     => true,
		'auto_wrapped'  => isset( $container_wrap ),
	];
}

/**
 * Recursively find a container by ID and insert a widget into it.
 *
 * @param array<mixed>         $elements
 * @param string               $container_id
 * @param array<string, mixed> $widget
 * @param string               $position
 * @return array<mixed>
 */
function e2m_engine_elementor_insert_into_container( array $elements, string $container_id, array $widget, string $position ): array {
	foreach ( $elements as &$element ) {
		if ( ( $element['id'] ?? '' ) === $container_id ) {
			if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
				$element['elements'] = [];
			}
			if ( $position === 'first' ) {
				array_unshift( $element['elements'], $widget );
			} else {
				$element['elements'][] = $widget;
			}
			return $elements;
		}
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = e2m_engine_elementor_insert_into_container( $element['elements'], $container_id, $widget, $position );
		}
	}
	return $elements;
}
