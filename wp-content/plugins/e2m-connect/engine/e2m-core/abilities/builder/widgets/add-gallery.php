<?php
/**
 * E2M Connect MCP - Add Gallery
 *
 * Inserts a multi-image gallery widget. Accepts an array of attachment IDs;
 * each adapter shapes the payload to match its gallery widget expectations.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-gallery', [
	'label'       => __( '[Widget] Gallery', 'e2mconnect' ),
	'description' => 'Inserts a multi-image gallery widget from a list of attachment IDs.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'attachment_ids' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 200,
					'items'    => [ 'type' => 'integer', 'minimum' => 1 ],
				],
				'columns' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 3 ],
			]
		),
		'required'             => [ 'post_id', 'attachment_ids' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_gallery_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Gallery' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_gallery_ability( array $input ) {
	$ids     = isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ? array_map( 'intval', $input['attachment_ids'] ) : [];
	$columns = isset( $input['columns'] ) ? max( 1, min( 8, (int) $input['columns'] ) ) : 3;

	$images     = [];
	$html_items = [];
	foreach ( $ids as $id ) {
		$url = (string) wp_get_attachment_url( $id );
		if ( $url === '' ) {
			continue;
		}
		$alt         = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$images[]    = [ 'id' => $id, 'url' => $url, 'alt' => $alt ];
		$html_items[] = sprintf( '<figure class="e2m-gallery-item"><img src="%s" alt="%s"/></figure>', esc_url( $url ), esc_attr( $alt ) );
	}

	$settings = [
		'wp_gallery'   => $images,
		'images'       => $images,
		'ids'          => $ids,
		'columns'      => $columns,
		'__inner_html' => sprintf(
			'<figure class="wp-block-gallery e2m-gallery columns-%d">%s</figure>',
			$columns,
			implode( '', $html_items )
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'gallery', $settings );
}
