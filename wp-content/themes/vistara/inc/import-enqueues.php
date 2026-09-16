<?php
/**
 * Vistara — asset enqueues.
 *
 * Source load order preserved: one <style> block in <head> (assets/css/styles.css, compiled
 * from assets/scss/main.scss via Dart Sass — see package.json), then wp-compat.css enqueued
 * AFTER it so its overrides win, then the Google Fonts css2 URL, then the single <script>
 * block at the end of <body> (assets/js/main.js) with no defer/async — the source loaded it
 * unblocked at the bottom of body, so `in_footer => true` reproduces that position/order
 * without changing execution semantics.
 *
 * Enqueue versions use filemtime() (never a static string) so a bridge push always busts the
 * browser cache — a static version let a client see stale CSS after a previous push, costing
 * two full QA cycles chasing what looked like a regression.
 *
 * This file is required by functions.php via a single `require_once`; kept isolated per the
 * existing-theme import path structure rule so `html-deployer` can drop this exact file into
 * an existing theme and append one require line, instead of overwriting functions.php.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue theme styles + scripts.
 */
function vst_enqueue_assets() {
	$dir = get_template_directory();
	$uri = get_template_directory_uri();

	// Google Fonts — Space Grotesk (display/.btn), Poppins (body), JetBrains Mono (instrument
	// layer), Montserrat 800 (.logo-word) — single shared css2 URL, per project-config.json.
	wp_enqueue_style( 'vst-fonts-preconnect-gstatic', 'https://fonts.gstatic.com', array(), null );
	wp_enqueue_style(
		'vst-fonts',
		'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Poppins:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&family=JetBrains+Mono:wght@400;500;600&family=Montserrat:wght@800&display=swap',
		array(),
		null
	);

	$styles_path = $dir . '/assets/css/styles.css';
	wp_enqueue_style(
		'vst-styles',
		$uri . '/assets/css/styles.css',
		array(),
		file_exists( $styles_path ) ? filemtime( $styles_path ) : false
	);

	// wp-compat.css MUST load after the source stylesheet so its overrides win.
	$compat_path = $dir . '/assets/css/wp-compat.css';
	wp_enqueue_style(
		'vst-wp-compat',
		$uri . '/assets/css/wp-compat.css',
		array( 'vst-styles' ),
		file_exists( $compat_path ) ? filemtime( $compat_path ) : false
	);

	$main_js_path = $dir . '/assets/js/main.js';
	wp_enqueue_script(
		'vst-main',
		$uri . '/assets/js/main.js',
		array(),
		file_exists( $main_js_path ) ? filemtime( $main_js_path ) : false,
		true // in_footer — matches the source's inline <script> position at the end of <body>.
	);

	/*
	 * Centralizes WAITLIST_URL (approved plan decision #10): main.js reads
	 * window.vstConfig.waitlistUrl and falls back to the source's hardcoded default when the
	 * options field is empty, so the CTA destination is editable without a script edit.
	 */
	$waitlist_url = function_exists( 'get_field' ) ? get_field( 'waitlist_url', 'option' ) : '';
	wp_localize_script(
		'vst-main',
		'vstConfig',
		array(
			'waitlistUrl' => $waitlist_url ? esc_url_raw( $waitlist_url ) : '',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'vst_enqueue_assets' );
