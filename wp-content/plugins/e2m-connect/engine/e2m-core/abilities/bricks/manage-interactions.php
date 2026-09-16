<?php
/**
 * E2M Connect MCP - Bricks Manage Interactions
 *
 * Configure Bricks animations, transitions, and interactions on elements.
 * Bricks stores animation/interaction settings directly in each element's
 * settings array within `_bricks_page_content_2`.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/bricks-manage-interactions', [
	'label'       => __( '[Bricks] Manage Interactions', 'e2mconnect' ),
	'description' => 'Configure Bricks animations, transitions, and interactions on page elements — entrance animations, hover effects, and scroll triggers.',
	'category'    => 'e2m-bricks',

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
				'description' => 'Bricks element ID to target (required for add, update, remove).',
			],
			'animation' => [
				'type'        => 'string',
				'description' => 'Animation name as used in Bricks (e.g. "fadeIn", "slideInUp", "zoomIn", "bounceIn").',
			],
			'duration' => [
				'type'        => 'integer',
				'description' => 'Animation duration in milliseconds. Default: 600.',
			],
			'delay' => [
				'type'        => 'integer',
				'description' => 'Animation delay in milliseconds. Default: 0.',
			],
			'trigger' => [
				'type'        => 'string',
				'enum'        => [ 'scroll', 'hover', 'click', 'page-load' ],
				'description' => 'What triggers the animation. Default: "scroll".',
				'default'     => 'scroll',
			],
			'iteration_count' => [
				'type'        => 'string',
				'description' => 'How many times the animation runs: a number or "infinite". Default: "1".',
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

	'execute_callback'    => 'e2m_engine_bricks_manage_interactions_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Bricks: Manage Interactions',
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		],
	],
] );

/**
 * Execute the bricks-manage-interactions ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_bricks_manage_interactions_ability( array $input ) {
	if ( ! e2m_engine_has_bricks() ) {
		return new WP_Error( 'bricks_missing', __( 'Bricks Builder is not installed or activated on this site.', 'e2mconnect' ) );
	}

	$post_id = (int) ( $input['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post ID.', 'e2mconnect' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'e2mconnect' ) );
	}

	$action   = sanitize_key( $input['action'] ?? 'list' );
	$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$elements = is_array( $elements ) ? $elements : [];

	if ( $action === 'list' ) {
		$interactions = array_values( array_filter( array_map( function ( $el ) {
			$settings = $el['settings'] ?? [];
			if ( empty( $settings['_animation'] ) && empty( $settings['animation'] ) ) {
				return null;
			}
			return [
				'element_id' => $el['id'] ?? '',
				'name'       => $el['name'] ?? '',
				'animation'  => $settings['_animation'] ?? $settings['animation'] ?? '',
				'duration'   => $settings['_animationDuration'] ?? 600,
				'delay'      => $settings['_animationDelay'] ?? 0,
				'trigger'    => $settings['_e2m_trigger'] ?? 'scroll',
			];
		}, $elements ) ) );

		return [ 'action' => 'list', 'post_id' => $post_id, 'interactions' => $interactions ];
	}

	$element_id = sanitize_key( $input['element_id'] ?? '' );
	if ( $element_id === '' ) {
		return new WP_Error( 'missing_element_id', __( 'element_id is required for add, update, and remove actions.', 'e2mconnect' ) );
	}

	if ( $action === 'remove' ) {
		$remove_keys = [ '_animation', 'animation', '_animationDuration', '_animationDelay', '_animationIterationCount', '_e2m_trigger' ];
		foreach ( $elements as &$el ) {
			if ( ( $el['id'] ?? '' ) === $element_id ) {
				foreach ( $remove_keys as $k ) {
					unset( $el['settings'][ $k ] );
				}
				break;
			}
		}
		unset( $el );
		update_post_meta( $post_id, '_bricks_page_content_2', $elements );
		return [ 'action' => 'remove', 'post_id' => $post_id, 'element_id' => $element_id, 'applied' => true ];
	}

	// add or update.
	$animation       = sanitize_text_field( $input['animation'] ?? 'fadeIn' );
	$duration        = max( 0, (int) ( $input['duration'] ?? 600 ) );
	$delay           = max( 0, (int) ( $input['delay'] ?? 0 ) );
	$trigger         = sanitize_key( $input['trigger'] ?? 'scroll' );
	$iteration_count = sanitize_text_field( $input['iteration_count'] ?? '1' );

	$found = false;
	foreach ( $elements as &$el ) {
		if ( ( $el['id'] ?? '' ) === $element_id ) {
			$el['settings']['_animation']              = $animation;
			$el['settings']['_animationDuration']      = $duration;
			$el['settings']['_animationDelay']         = $delay;
			$el['settings']['_animationIterationCount'] = $iteration_count;
			$el['settings']['_e2m_trigger']         = $trigger;
			$found = true;
			break;
		}
	}
	unset( $el );

	if ( ! $found ) {
		return new WP_Error( 'element_not_found', __( 'Element not found on this page.', 'e2mconnect' ) );
	}

	update_post_meta( $post_id, '_bricks_page_content_2', $elements );

	return [
		'action'     => $action,
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'applied'    => true,
	];
}
