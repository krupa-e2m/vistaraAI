<?php
/**
 * Vistara — footer.
 *
 * Source : spec/fragments/shared/footer.html, plus the shared modal shell
 *          (spec/fragments/shared/modal.html) and the decorative edge bar
 *          (spec/fragments/shared/edge.html) that sit right after </footer> in source
 *          (source/html/_extracted/index.html: <footer>…</footer><div class="edge">…
 *          <div class="modal" id="modal">…).
 *
 * Global chrome owned here (see header.php's own comment for the matching ambient divs):
 *   - the single #modal / #modalSlot lightbox shell — must exist exactly ONCE per page,
 *     shared by both the video/image zoom (data-video) and the gallery lightbox hooks.
 *   - the .edge decorative gradient bar.
 * Menus: `footer-nav` (5 on-page anchors + external links, flat <a> list — no dropdown in
 * source) and `footer-legal` (bottom link(s), e.g. "Refund Policy") both render through
 * Vst_Menu_Walker_Flat (inc/menu-walkers.php) so wp_nav_menu() never introduces a <ul>/<li>
 * the source's flex-row CSS was not written for.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

$vst_site_logo_id   = function_exists( 'get_field' ) ? get_field( 'site_logo', 'option' ) : 0;
$vst_logo_src        = vst_image_url( $vst_site_logo_id );
$vst_logo_alt        = vst_image_alt( $vst_site_logo_id, __( 'Vistara', 'vistara' ) );
$vst_tagline         = function_exists( 'get_field' ) ? get_field( 'brand_tagline', 'option' ) : '';
$vst_copyright       = function_exists( 'get_field' ) ? get_field( 'footer_copyright', 'option' ) : '';
$vst_copyright_text  = $vst_copyright ? sprintf( str_replace( '{year}', '%s', $vst_copyright ), gmdate( 'Y' ) ) : '';
// The seeded sample has no {year} token (it hardcodes "2026"); when present it is treated as
// the year placeholder per house standard (date('Y')) so the copyright never needs a manual
// yearly edit. If the field has no {year} token at all, the str_replace is a harmless no-op
// and the authored value is used as-is.
?>
  <footer class="foot">
    <div class="wrap">
      <div class="foot-top">
        <a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Vistara — The AI Conference by E2M', 'vistara' ); ?>">
          <?php if ( $vst_logo_src ) : ?>
          <img class="logo-img" src="<?php echo esc_url( $vst_logo_src ); ?>" alt="<?php echo esc_attr( $vst_logo_alt ); ?>">
          <?php endif; ?>
          <span class="logo-tag"><?php echo esc_html( $vst_tagline ); ?></span>
        </a>
        <nav class="foot-nav" aria-label="<?php esc_attr_e( 'Footer', 'vistara' ); ?>">
        <?php
        /*
         * QA fix (desktop 1920, iter 1, root cause #1): wp_nav_menu()'s `before`/`after` args
         * wrap the text INSIDE each individual menu item (Walker_Nav_Menu::start_el appends
         * them around every single <a>, once per item) — they are not a whole-menu wrapper,
         * and Vst_Menu_Walker_Flat's start_el() never even reads $args->before/after (it
         * builds its own <a> markup directly). With `container => false` too, the previous
         * version therefore emitted zero `.foot-nav` elements, so `.foot-nav a` in
         * styles.css (font-family Space Grotesk / 500 / .94rem, color var(--tx-dim)) never
         * matched anything and the links fell through to the body's default Poppins/400/1rem.
         * Fix: print the real `.foot-nav` element in the template itself, around the
         * wp_nav_menu() call, matching spec/fragments/shared/footer.html's
         * `<nav class="foot-nav" aria-label="Footer">` exactly.
         */
        wp_nav_menu(
			array(
				'theme_location' => 'footer-nav',
				'container'      => false,
				'items_wrap'     => '%3$s',
				'walker'         => new Vst_Menu_Walker_Flat(),
				'fallback_cb'    => false,
			)
		);
		?>
        </nav>
      </div>
      <div class="foot-bot">
        <span><?php echo esc_html( $vst_copyright_text ); ?></span>
        <?php
        wp_nav_menu(
			array(
				'theme_location' => 'footer-legal',
				'container'      => false,
				'items_wrap'     => '%3$s',
				'walker'         => new Vst_Menu_Walker_Flat(),
				'fallback_cb'    => false,
			)
		);
		?>
      </div>
    </div>
  </footer>

  <div class="edge" aria-hidden="true"></div>

  <div class="modal" id="modal" aria-hidden="true">
    <div class="modal-back" data-close></div>
    <div class="modal-box" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Vistara recap — AI for Agencies, May 2026, Austin, Texas', 'vistara' ); ?>">
      <button class="modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'vistara' ); ?>">&times;</button>
      <button class="modal-nav prev" data-gnav="-1" aria-label="<?php esc_attr_e( 'Previous photo', 'vistara' ); ?>"><svg viewBox="0 0 24 24"><path d="M14.5 6 9 12l5.5 6"/></svg></button>
      <button class="modal-nav next" data-gnav="1" aria-label="<?php esc_attr_e( 'Next photo', 'vistara' ); ?>"><svg viewBox="0 0 24 24"><path d="M9.5 6 15 12l-5.5 6"/></svg></button>
      <div id="modalSlot"></div>
    </div>
  </div>

<?php wp_footer(); ?>
</body>
</html>
