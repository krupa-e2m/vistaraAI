<?php
/**
 * E2M Connect MCP - Add Video
 *
 * Inserts a video widget from a URL (YouTube, Vimeo, or self-hosted MP4).
 * Providers are detected via WordPress' oembed mechanism where available.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability( 'e2m/add-video', [
	'label'       => __( '[Widget] Video', 'e2mconnect' ),
	'description' => 'Inserts a video widget from a YouTube, Vimeo, or self-hosted MP4 URL.',
	'category'    => 'e2m-builder',

	'input_schema' => [
		'type'       => 'object',
		'properties' => array_merge(
			e2m_engine_widget_shortcut_common_properties(),
			[
				'url'      => [ 'type' => 'string', 'minLength' => 1 ],
				'autoplay' => [ 'type' => 'boolean', 'default' => false ],
				'loop'     => [ 'type' => 'boolean', 'default' => false ],
				'muted'    => [ 'type' => 'boolean', 'default' => false ],
			]
		),
		'required'             => [ 'post_id', 'url' ],
		'additionalProperties' => false,
	],

	'output_schema'       => e2m_engine_widget_shortcut_output_schema(),
	'execute_callback'    => 'e2m_engine_add_video_ability',
	'permission_callback' => 'e2m_engine_permission_callback',

	'meta' => [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => e2m_engine_widget_shortcut_annotations( 'Add Video' ),
	],
] );

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function e2m_engine_add_video_ability( array $input ) {
	$url      = esc_url_raw( (string) ( $input['url'] ?? '' ) );
	$autoplay = ! empty( $input['autoplay'] );
	$loop     = ! empty( $input['loop'] );
	$muted    = ! empty( $input['muted'] );

	$settings = [
		'video_type'  => e2m_engine_infer_video_provider( $url ),
		'youtube_url' => $url,
		'vimeo_url'   => $url,
		'hosted_url'  => [ 'url' => $url ],
		'url'         => $url,
		'autoplay'    => $autoplay ? 'yes' : '',
		'loop'        => $loop ? 'yes' : '',
		'mute'        => $muted ? 'yes' : '',
		'src'         => $url,
		'__inner_html' => sprintf( '<figure class="wp-block-video"><video src="%s"%s%s%s controls></video></figure>',
			esc_url( $url ),
			$autoplay ? ' autoplay' : '',
			$loop ? ' loop' : '',
			$muted ? ' muted' : ''
		),
	];

	return e2m_engine_widget_shortcut_run( $input, 'video', $settings );
}

/**
 * Infer which video provider a URL belongs to so builders that key on a
 * "video_type" field route the URL into the right slot.
 */
function e2m_engine_infer_video_provider( string $url ): string {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( str_contains( $host, 'youtube.com' ) || str_contains( $host, 'youtu.be' ) ) {
		return 'youtube';
	}
	if ( str_contains( $host, 'vimeo.com' ) ) {
		return 'vimeo';
	}
	return 'hosted';
}
