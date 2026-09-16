<?php
/**
 * Vistara — admin UX tweaks.
 *
 * One-time cleanup of ACF's remembered tab positions (localStorage `acf` → `tabs-{post_id}`
 * entries, restored BY INDEX). The 2026-08 feedback round moved the Section Content tab to
 * the front of every flexible content layout (and added Header/Footer tabs to Theme
 * Settings), so an index remembered under the old order now points at the wrong tab —
 * browsers that had edited the page before the reorder kept opening Section Settings.
 * Clearing the stored indexes once per browser lets ACF fall back to its default: the
 * first tab, now Section Content. Bump the flag suffix if tab order ever changes again.
 *
 * Cleared via acf.setPreference() when ACF's JS is present, because acf.js snapshots
 * localStorage into memory on load and writes that snapshot back on every preference save —
 * editing localStorage behind its back would be resurrected by the next tab click.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Print the one-time ACF tab-preference reset script on admin screens.
 */
function vst_reset_stale_acf_tab_prefs() {
	?>
	<script>
	( function () {
		try {
			var flag = 'vst_acf_tabs_reset_202608';
			if ( ! window.localStorage || localStorage.getItem( flag ) ) {
				return;
			}
			var prefs = JSON.parse( localStorage.getItem( 'acf' ) || '{}' );
			var keys  = Object.keys( prefs ).filter( function ( key ) {
				return 0 === key.indexOf( 'tabs-' );
			} );
			if ( window.acf && acf.setPreference ) {
				keys.forEach( function ( key ) {
					acf.setPreference( key, null );
				} );
			} else if ( keys.length ) {
				keys.forEach( function ( key ) {
					delete prefs[ key ];
				} );
				localStorage.setItem( 'acf', JSON.stringify( prefs ) );
			}
			localStorage.setItem( flag, '1' );
		} catch ( e ) { /* localStorage unavailable — ACF falls back to its defaults anyway. */ }
	} )();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'vst_reset_stale_acf_tab_prefs', 1 );
