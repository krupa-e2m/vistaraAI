<?php
/**
 * E2M Connect MCP - Add Image Box
 *
 * Inserts a combined image + heading + description widget. Similar in shape
 * to add-icon-box but uses a Media Library image (or URL) instead of an
 * icon glyph.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-image-box', [
	'label'       => __( '[Widget] Image Box', 'e2mconnect' ),
	'description' => 'Inserts an image + heading + description widget (media + text feature tile).',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'image_url'     => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string', 'minLength' => 1 ],
				'description'   => [ 'type' => 'string' ],
				'link_url'      => [ 'type' => 'string' ],
			]
		),
		'required'             => [ 'post_id', 'title' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_image_box_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Image Box' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_image_box_ability( array $input ) {
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	$image_url     = isset( $input['image_url'] ) ? esc_url_raw( (string) $input['image_url'] ) : '';
	$title         = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$description   = wp_kses_post( (string) ( $input['description'] ?? '' ) );
	$link          = isset( $input['link_url'] ) ? esc_url_raw( (string) $input['link_url'] ) : '';

	if ( $attachment_id > 0 ) {
		$resolved = (string) wp_get_attachment_url( $attachment_id );
		if ( $resolved !== '' ) {
			$image_url = $resolved;
		}
	}

	$settings = [
		'image'            => [ 'id' => $attachment_id, 'url' => $image_url ],
		'image_url'        => $image_url,
		'title_text'       => $title,
		'description_text' => $description,
		'link'             => [ 'url' => $link ],
		'__inner_html'     => sprintf(
			'<div class="e2m-image-box">%s<div class="e2m-image-box-body"><h3>%s</h3><p>%s</p>%s</div></div>',
			$image_url !== '' ? '<div class="e2m-image-box-image"><img src="' . esc_url( $image_url ) . '" alt=""/></div>' : '',
			esc_html( $title ),
			$description,
			$link !== '' ? '<a href="' . esc_url( $link ) . '">Learn more</a>' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'image-box', $settings );
}
