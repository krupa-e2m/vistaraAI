<?php
/**
 * E2M Connect MCP - Elementor List Dynamic Tags (Pro)
 *
 * Enumerates every dynamic tag Elementor Pro has registered: post title,
 * featured image, ACF field, archive description, etc. Agents use this
 * before binding a tag via elementor-set-dynamic-tag.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/elementor-list-dynamic-tags', [
	'label'       => __( '[Elementor] List Dynamic Tags', 'e2mconnect' ),
	'description' => 'Pro-only. Lists every dynamic tag registered with Elementor Pro.',
	'category'    => 'e2m-elementor',

	'input_schema' => [
		'type'       => 'object',
		'properties' => [
			'group' => [ 'type' => 'string', 'description' => 'Filter by group slug (post, media, site, actions, archive).' ],
		],
		'additionalProperties' => false,
	],

	'output_schema' => [
		'type'       => 'object',
		'properties' => [
			'tags' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name'     => [ 'type' => 'string' ],
						'title'    => [ 'type' => 'string' ],
						'group'    => [ 'type' => 'string' ],
						'categories'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
		],
	],

	'execute_callback'    => 'e2m_engine_elementor_list_dynamic_tags_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'title'       => 'Elementor: List Dynamic Tags (Pro)',
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		],
	],
] );

/**
 * Execute the elementor-list-dynamic-tags ability.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_elementor_list_dynamic_tags_ability( array $input ) {
	$guard = E2M_Elementor_Helper::require_pro();
	if ( $guard !== null ) {
		return $guard;
	}

	$filter = isset( $input['group'] ) ? sanitize_key( (string) $input['group'] ) : '';

	$manager = \Elementor\Plugin::$instance->dynamic_tags ?? null;
	if ( ! $manager ) {
		return new WP_Error( 'dynamic_tags_missing', __( 'Elementor dynamic tags manager is unavailable.', 'e2mconnect' ) );
	}

	// Elementor Pro exposes the tag catalogue via get_tags_config() in
	// recent versions (4.x); older releases had get_tags_info(). We probe
	// for whichever exists on this install so the ability works across
	// Elementor majors.
	$catalogue = [];
	if ( method_exists( $manager, 'get_tags_config' ) ) {
		$catalogue = (array) $manager->get_tags_config();
	} elseif ( method_exists( $manager, 'get_tags_info' ) ) {
		$catalogue = (array) $manager->get_tags_info();
	}

	$rows = [];
	foreach ( $catalogue as $key => $info ) {
		if ( ! is_array( $info ) ) {
			continue;
		}
		$group = (string) ( $info['group'] ?? '' );
		if ( $filter !== '' && $group !== $filter ) {
			continue;
		}
		$rows[] = [
			'name'       => (string) ( $info['name'] ?? $key ),
			'title'      => (string) ( $info['title'] ?? '' ),
			'group'      => $group,
			'categories' => (array) ( $info['categories'] ?? [] ),
		];
	}

	return [ 'tags' => $rows ];
}
