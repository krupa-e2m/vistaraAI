<?php
/**
 * E2M Connect MCP - Admin Bar Indicator.
 *
 * Adds a red "AI ACTIVE" node to the top admin bar whenever MCP abilities
 * are enabled. The goal is to make the agent's blast radius visible at
 * all times - admins shouldn't have to dig into Settings to notice that
 * an AI could be writing on this site right now.
 *
 * with other MCP-era plugins: operators recognise the pattern.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Admin_Bar {

	public static function register(): void {
		add_action( 'admin_bar_menu', [ self::class, 'add_node' ], 100 );
		add_action( 'wp_head', [ self::class, 'inline_style' ] );
		add_action( 'admin_head', [ self::class, 'inline_style' ] );
	}

	public static function add_node( WP_Admin_Bar $bar ): void {
		$settings = e2m_engine_get_settings();
		if ( empty( $settings['admin_bar_indicator'] ) ) {
			return;
		}
		if ( ! function_exists( 'e2m_engine_is_enabled' ) || ! e2m_engine_is_enabled() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$label = __( 'E2M Connect', 'e2mconnect' );

		$bar->add_node(
			[
				'id'    => 'e2m-mcp-live',
				'title' => '<span class="e2m-mcp-ai-dot"></span>' . esc_html( $label ),
				'href'  => admin_url( 'admin.php?page=e2mconnect' ),
				'meta'  => [
					'class' => 'e2m-mcp-admin-bar',
					'title' => __( 'E2M Connect AI abilities are active on this site. Click for settings.', 'e2mconnect' ),
				],
			]
		);
	}

	public static function inline_style(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		// Canonical values: --so-bar-online (green status dot) in
		// e2m-ui/admin-design-system.css. Minimal admin-bar indicator:
		// inherits admin-bar bg + text; only the small green dot signals
		// "AI active" state.
		echo '<style>
			#wpadminbar .e2m-mcp-admin-bar .e2m-mcp-ai-dot {
				display:inline-block;
				width:7px;
				height:7px;
				border-radius:50%;
				background:#00D084;
				margin-right:6px;
				vertical-align:1px;
			}
		</style>';
	}
}
