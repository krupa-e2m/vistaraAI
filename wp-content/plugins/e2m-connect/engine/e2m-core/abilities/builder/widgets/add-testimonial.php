<?php
/**
 * E2M Connect MCP - Add Testimonial
 *
 * Inserts a single customer/user testimonial with quote, attribution, and
 * optional avatar. For a scrolling set use add-carousel over a list of
 * testimonial blocks.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-testimonial', [
	'label'       => __( '[Widget] Testimonial', 'e2mconnect' ),
	'description' => 'Inserts a single testimonial with quote, author name, optional role and avatar image.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'quote'      => [ 'type' => 'string', 'minLength' => 1 ],
				'author'     => [ 'type' => 'string', 'minLength' => 1 ],
				'role'       => [ 'type' => 'string' ],
				'avatar_url' => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id', 'quote', 'author' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_testimonial_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Testimonial' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_testimonial_ability( array $input ) {
	$quote  = wp_kses_post( (string) ( $input['quote'] ?? '' ) );
	$author = sanitize_text_field( (string) ( $input['author'] ?? '' ) );
	$role   = sanitize_text_field( (string) ( $input['role'] ?? '' ) );
	$avatar = isset( $input['avatar_url'] ) ? esc_url_raw( (string) $input['avatar_url'] ) : '';

	$settings = [
		'testimonial_content' => $quote,
		'testimonial_name'    => $author,
		'testimonial_job'     => $role,
		'testimonial_image'   => [ 'url' => $avatar ],
		'quote'               => $quote,
		'author'              => $author,
		'role'                => $role,
		'avatar'              => $avatar,
		'__inner_html'        => sprintf(
			'<blockquote class="e2m-testimonial">%s<footer>%s%s%s</footer></blockquote>',
			'<p>' . $quote . '</p>',
			$avatar !== '' ? '<img src="' . esc_url( $avatar ) . '" alt="" class="e2m-testimonial-avatar"/>' : '',
			'<cite>' . esc_html( $author ) . '</cite>',
			$role !== '' ? '<span class="e2m-testimonial-role">' . esc_html( $role ) . '</span>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'testimonial', $settings );
}
