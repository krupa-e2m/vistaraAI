<?php
/**
 * Vistara — ACF Options Page registration ("Theme Settings").
 *
 * Belt-and-braces theme-side registration. `html-deployer` also registers this page via the
 * `e2m/acf-manage-options-pages` bridge ability at deploy time; this survives if that
 * plugin-side registry is ever lost/reset. `menu_slug` MUST match the `location` value baked
 * into acf-json/group_cc61be0b553cc.json ("Theme Settings", options_page == theme-settings)
 * exactly — a mismatch makes the field group show up nowhere in wp-admin.
 *
 * Requires ACF PRO (acf_add_options_page is a Pro-only function); guarded with
 * function_exists so a free-ACF or no-ACF site degrades to the admin notice in functions.php
 * instead of a fatal.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Theme Settings options page.
 */
function vst_register_options_pages() {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}

	acf_add_options_page(
		array(
			'page_title' => __( 'Theme Settings', 'vistara' ),
			'menu_title' => __( 'Theme Settings', 'vistara' ),
			'menu_slug'  => 'theme-settings',
			'capability' => 'manage_options',
			'redirect'   => false,
			'icon_url'   => 'dashicons-admin-generic',
			'position'   => 61,
		)
	);
}
add_action( 'acf/init', 'vst_register_options_pages' );
