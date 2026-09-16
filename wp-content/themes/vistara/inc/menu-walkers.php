<?php
/**
 * Vistara — native WP menu registration + a flat-shape Walker.
 *
 * Both menu locations in this theme (`footer-nav`, `footer-legal`) render as flat <a> lists
 * directly inside their parent container in the source — NO <ul>/<li> wrapper:
 *   <nav class="foot-nav" aria-label="Footer"><a href="#what">…</a><a …>…</a></nav>
 *   <div class="foot-bot"><span>…</span><a href="…">Refund Policy</a></div>
 * `wp_nav_menu()`'s default Walker always wraps items in <ul><li>, which the source CSS
 * (flex row of bare <a> tags) was never written against. `Vst_Menu_Walker_Flat` outputs
 * bare <a> tags only, so wp_nav_menu()'s markup is byte-shape-identical to the source.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the two menu locations the plan calls for (spec/acf-plan.json `menus`).
 */
function vst_register_menus() {
	register_nav_menus(
		array(
			'footer-nav'   => __( 'Footer Navigation (What Is Vistara / Who It\'s For / Agenda / Speakers / external links)', 'vistara' ),
			'footer-legal' => __( 'Footer Legal / Bottom Links (e.g. Refund Policy)', 'vistara' ),
		)
	);
}
add_action( 'after_setup_theme', 'vst_register_menus' );

/**
 * Walker outputting a flat run of <a> tags — no <ul>/<li>, no default WP menu classes.
 *
 * `current-menu-item` / `current_page_item` are preserved as a CLASS ON THE <a> itself
 * (not a parent <li>) so wp-compat.css's `.foot-nav .current-menu-item > a` selector still
 * has a fallback path if a future markup change reintroduces the <li> wrapper; the primary,
 * intended path is `.foot-nav a.current-menu-item`.
 */
class Vst_Menu_Walker_Flat extends Walker_Nav_Menu {

	/**
	 * @param string   $output Passed by reference. Used to append additional content.
	 * @param stdClass $item   Menu item data object.
	 * @param int      $depth  Depth of menu item. Used for padding.
	 * @param stdClass $args   An object of wp_nav_menu() arguments.
	 * @param int      $id     Current item ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes   = empty( $item->classes ) ? array() : (array) $item->classes;
		$classes[] = 'menu-item-' . $item->ID;

		if ( in_array( 'current-menu-item', $classes, true ) || in_array( 'current_page_item', $classes, true ) ) {
			$classes[] = 'current-menu-item';
		}

		$class_names = esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) );
		$target      = $item->target ? ' target="' . esc_attr( $item->target ) . '"' : '';
		$rel         = $item->xfn ? ' rel="' . esc_attr( $item->xfn ) . '"' : '';
		$url         = ! empty( $item->url ) ? esc_url( $item->url ) : '';

		$output .= '<a href="' . $url . '" class="' . $class_names . '"' . $target . $rel . '>';
	}

	/**
	 * @param string   $output Passed by reference.
	 * @param stdClass $item   Menu item data object.
	 * @param int      $depth  Depth of menu item.
	 * @param stdClass $args   An object of wp_nav_menu() arguments.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= esc_html( $item->title ) . '</a>';
	}

	/**
	 * Never emit a <ul>/<li> wrapper — the source has none.
	 *
	 * @param string $output Passed by reference.
	 * @param int    $depth  Depth of page. Used for padding.
	 * @param stdClass $args An object of wp_nav_menu() arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {}

	/**
	 * @param string $output Passed by reference.
	 * @param int    $depth  Depth of page.
	 * @param stdClass $args An object of wp_nav_menu() arguments.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {}
}
