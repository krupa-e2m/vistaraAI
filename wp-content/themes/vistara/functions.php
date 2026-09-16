<?php
/**
 * Vistara theme bootstrap.
 *
 * Structure rule (html-theme-assembly): every capability lives in an `inc/*.php` module that
 * this file merely `require_once`s, so `html-deployer` can drop a module into an EXISTING
 * theme and append one require line instead of overwriting functions.php wholesale.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

define( 'VST_THEME_VERSION', '1.0.0' );
define( 'VST_THEME_DIR', get_template_directory() );
define( 'VST_THEME_URI', get_template_directory_uri() );

/* -------------------------------------------------------------------------
 * Module requires — see each file's own header for what it owns.
 * ---------------------------------------------------------------------- */
require_once VST_THEME_DIR . '/inc/template-helpers.php'; // All 16 section partials fatal without this.
require_once VST_THEME_DIR . '/inc/post-types.php';
require_once VST_THEME_DIR . '/inc/menu-walkers.php';
require_once VST_THEME_DIR . '/inc/import-enqueues.php';
require_once VST_THEME_DIR . '/inc/options.php';
require_once VST_THEME_DIR . '/inc/admin-ux.php';

/* -------------------------------------------------------------------------
 * Theme setup
 * ---------------------------------------------------------------------- */
function vst_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'automatic-feed-links' );

	// Editor-only image classes are neutralized in wp-compat.css, but keep responsive-embed
	// support on so an editor pasting a video URL into a wysiwyg field is still contained.
	add_theme_support( 'responsive-embeds' );

	load_theme_textdomain( 'vistara', VST_THEME_DIR . '/languages' );
}
add_action( 'after_setup_theme', 'vst_setup' );

/* -------------------------------------------------------------------------
 * ACF Local JSON — LOCAL/DEV path only.
 *
 * Per html-acf-standards' seeding contract, the BRIDGE deploy (html-deployer,
 * e2m/acf-manage-field-groups) registers field groups directly in the WordPress database —
 * the live theme it pushes must NOT ship an `acf-json/` directory (dual registration makes
 * ACF's Local JSON win, shows a permanent "sync available" notice, and lets admin edits
 * silently diverge from the theme's JSON). These hooks exist so `acf-json/` still works as
 * the LOCAL reference/versioned source of truth when this theme is run on a local ACF Pro
 * install during development — html-deployer strips this directory before/while pushing to
 * a live site.
 * ---------------------------------------------------------------------- */
add_filter(
	'acf/settings/save_json',
	function ( $path ) {
		return VST_THEME_DIR . '/acf-json';
	}
);
add_filter(
	'acf/settings/load_json',
	function ( $paths ) {
		unset( $paths[0] );
		$paths[] = VST_THEME_DIR . '/acf-json';
		return $paths;
	}
);

/* -------------------------------------------------------------------------
 * Dependency gates — loud, never silent.
 * ---------------------------------------------------------------------- */

/**
 * True only for ACF PRO. `function_exists('acf_add_local_field_group')` is TRUE on the free
 * plugin too, so it can never be used alone to gate Pro-only features (options pages,
 * flexible content, clone, repeater, gallery).
 *
 * @return bool
 */
function vst_has_acf_pro() {
	if ( function_exists( 'acf_get_setting' ) && acf_get_setting( 'pro' ) ) {
		return true;
	}
	return in_array(
		'advanced-custom-fields-pro/acf.php',
		(array) get_option( 'active_plugins', array() ),
		true
	) || is_plugin_active_for_network( 'advanced-custom-fields-pro/acf.php' );
}

/**
 * `is_plugin_active_for_network()` lives in an admin-only file; provide a safe fallback so
 * `vst_has_acf_pro()` never fatals when called on the front end before that file loads.
 *
 * @param string $plugin Plugin basename.
 * @return bool
 */
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ) {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugins = get_site_option( 'active_sitewide_plugins' );
		return isset( $plugins[ $plugin ] );
	}
}

function vst_dependency_admin_notices() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		echo '<div class="notice notice-error"><p><strong>Vistara theme:</strong> ' .
			esc_html__( 'Advanced Custom Fields is not active. Every section of this site is built on ACF flexible content — no page will render until it is installed and activated.', 'vistara' ) .
			'</p></div>';
		return;
	}

	if ( ! vst_has_acf_pro() ) {
		echo '<div class="notice notice-error"><p><strong>Vistara theme:</strong> ' .
			esc_html__( 'ACF PRO is required (flexible content, repeaters, clone fields, and the Theme Settings options page all depend on it). The free version of Advanced Custom Fields is active, which is not sufficient.', 'vistara' ) .
			'</p></div>';
	}
}
add_action( 'admin_notices', 'vst_dependency_admin_notices' );
