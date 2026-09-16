<?php
/**
 * E2M Connect MCP - Elementor Manage Interactions
 *
 * Add, update, list, and remove scroll animations, hover effects, and
 * motion design interactions on Elementor elements. Reads and writes
 * the _elementor_data motion/animation settings on a given post.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-manage-interactions', [
	'label'       => __( '[Elementor] Manage Interactions', 'e2mconnect' ),
	'description' => 'Add scroll animations, hover effects, and motion design interactions to Elementor elements on a page.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'post_id' => [
				'type'        => 'integer',
				'description' => 'The post/page ID to operate on.',
			],
			'action' => [
				'type'        => 'string',
				'enum'        => [ 'list', 'add', 'update', 'remove' ],
				'description' => 'Operation to perform.',
			],
			'element_id' => [
				'type'        => 'string',
				'description' => 'Elementor element ID to target (required for add, update, remove).',
			],
			'animation_type' => [
				'type'        => 'string',
				'enum'        => [ 'entrance', 'scroll', 'hover', 'mouse-track' ],
				'description' => 'Type of interaction/animation.',
			],
			'animation' => [
				'type'        => 'string',
				'description' => 'Animation name (e.g. "fadeIn", "slideInUp", "zoomIn").',
			],
			'duration' => [
				'type'        => 'string',
				'description' => 'Animation duration: "slow", "normal", or "fast". Defaults to "normal".',
				'enum'        => [ 'slow', 'normal', 'fast' ],
			],
			'delay' => [
				'type'        => 'integer',
				'description' => 'Delay in milliseconds before the animation starts.',
			],
		],
		'required'             => [ 'post_id', 'action' ],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'action'       => [ 'type' => 'string' ],
			'post_id'      => [ 'type' => 'integer' ],
			'interactions' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
			'element_id'   => [ 'type' => 'string' ],
			'applied'      => [ 'type' => 'boolean' ],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_manage_interactions_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: Manage Interactions',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the elementor-manage-interactions ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_manage_interactions_ability( array $input ) {
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

	$action = sanitize_key( $input['action'] ?? 'list' );

	// Load current Elementor data.
	$raw_data = get_post_meta( $post_id, '_elementor_data', true );
	$data     = is_string( $raw_data ) ? json_decode( $raw_data, true ) : [];
	$data     = is_array( $data ) ? $data : [];

	if ( $action === 'list' ) {
		$interactions = e2m_engine_elementor_collect_interactions( $data );
		return [ 'action' => 'list', 'post_id' => $post_id, 'interactions' => $interactions ];
	}

	$element_id = sanitize_key( $input['element_id'] ?? '' );
	if ( $element_id === '' ) {
		return new WP_Error( 'missing_element_id', __( 'element_id is required for add, update, and remove actions.', 'e2mconnect' ) );
	}

	if ( $action === 'remove' ) {
		$data    = e2m_engine_elementor_remove_interaction( $data, $element_id );
		$encoded = wp_json_encode( $data );
		update_post_meta( $post_id, '_elementor_data', $encoded );
		delete_post_meta( $post_id, '_elementor_css' );
		return [ 'action' => 'remove', 'post_id' => $post_id, 'element_id' => $element_id, 'applied' => true ];
	}

	// add or update.
	$animation_type = sanitize_key( $input['animation_type'] ?? 'entrance' );
	$animation      = sanitize_text_field( $input['animation'] ?? 'fadeIn' );
	$duration       = sanitize_key( $input['duration'] ?? 'normal' );
	$delay          = (int) ( $input['delay'] ?? 0 );

	$interaction_settings = [
		'_animation'          => $animation,
		'_animation_duration' => $duration,
		'_animation_delay'    => $delay,
		'_e2m_anim_type'   => $animation_type,
	];

	$data    = e2m_engine_elementor_apply_interaction( $data, $element_id, $interaction_settings );
	$encoded = wp_json_encode( $data );
	update_post_meta( $post_id, '_elementor_data', $encoded );
	delete_post_meta( $post_id, '_elementor_css' );

	return [
		'action'     => $action,
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'applied'    => true,
	];
}

/**
 * Recursively collect all elements that have animation settings.
 *
 * @param array<mixed> $elements
 * @return array<mixed>
 */
function e2m_engine_elementor_collect_interactions( array $elements ): array {
	$found = [];
	foreach ( $elements as $element ) {
		$settings = $element['settings'] ?? [];
		if ( ! empty( $settings['_animation'] ) ) {
			$found[] = [
				'element_id'     => $element['id'] ?? '',
				'elType'         => $element['elType'] ?? '',
				'animation'      => $settings['_animation'],
				'duration'       => $settings['_animation_duration'] ?? 'normal',
				'delay'          => $settings['_animation_delay'] ?? 0,
			];
		}
		if ( ! empty( $element['elements'] ) ) {
			$found = array_merge( $found, e2m_engine_elementor_collect_interactions( $element['elements'] ) );
		}
	}
	return $found;
}

/**
 * Recursively apply interaction settings to a specific element ID.
 *
 * @param array<mixed>         $elements
 * @param string               $target_id
 * @param array<string, mixed> $settings
 * @return array<mixed>
 */
function e2m_engine_elementor_apply_interaction( array $elements, string $target_id, array $settings ): array {
	foreach ( $elements as &$element ) {
		if ( ( $element['id'] ?? '' ) === $target_id ) {
			foreach ( $settings as $k => $v ) {
				$element['settings'][ $k ] = $v;
			}
		}
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = e2m_engine_elementor_apply_interaction( $element['elements'], $target_id, $settings );
		}
	}
	return $elements;
}

/**
 * Recursively remove interaction settings from a specific element ID.
 *
 * @param array<mixed> $elements
 * @param string       $target_id
 * @return array<mixed>
 */
function e2m_engine_elementor_remove_interaction( array $elements, string $target_id ): array {
	$interaction_keys = [ '_animation', '_animation_duration', '_animation_delay', '_e2m_anim_type' ];
	foreach ( $elements as &$element ) {
		if ( ( $element['id'] ?? '' ) === $target_id ) {
			foreach ( $interaction_keys as $key ) {
				unset( $element['settings'][ $key ] );
			}
		}
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = e2m_engine_elementor_remove_interaction( $element['elements'], $target_id );
		}
	}
	return $elements;
}
