<?php
/**
 * E2M Connect MCP - Add Image
 *
 * Inserts an image widget by attachment_id (preferred) or a direct URL.
 * When attachment_id is supplied, alt text and dimensions are pulled from
 * the Media Library so the widget survives re-renders.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-image', [
	'label'       => __( '[Widget] Image', 'e2mconnect' ),
	'description' => 'Inserts an image widget from a Media Library attachment or an external URL.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'url'           => [ 'type' => 'string' ],
				'alt'           => [ 'type' => 'string' ],
				'caption'       => [ 'type' => 'string' ],
				'link_url'      => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_image_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Image' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_image_ability( array $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	$url           = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
	$alt           = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
	$caption       = isset( $input['caption'] ) ? wp_kses_post( (string) $input['caption'] ) : '';
	$link_url      = isset( $input['link_url'] ) ? esc_url_raw( (string) $input['link_url'] ) : '';

	if ( $attachment_id > 0 ) {
		$resolved = (string) wp_get_attachment_url( $attachment_id );
		if ( $resolved !== '' ) {
			$url = $resolved;
		}
		if ( $alt === '' ) {
			$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		}
	}

	if ( $url === '' ) {
		return new WP_Error( 'missing_image', __( 'Provide attachment_id or url.', 'e2mconnect' ) );
	}

	$settings = [
		'image'        => [ 'id' => $attachment_id, 'url' => $url, 'alt' => $alt ],
		'url'          => $url,
		'id'           => $attachment_id,
		'alt'          => $alt,
		'caption'      => $caption,
		'link'         => [ 'url' => $link_url ],
		'__inner_html' => sprintf(
			'<figure class="wp-block-image"><img src="%1$s" alt="%2$s"/>%3$s</figure>',
			esc_url( $url ),
			esc_attr( $alt ),
			$caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'image', $settings );
}
