<?php
/**
 * Section: lockup_band  (FC layout `lockup_band`)
 *
 * Source fragment : spec/fragments/home/02-what.html  (home / section id `what`) — BOTTOM HALF.
 * Approved plan decision #3 splits that one <section> into media_intro (the 2-col grid) +
 * lockup_band (this centred logo-wordmark band). This partial keeps the section/.wrap shell
 * and the .lockup block verbatim; the .grid-2 block was removed and lives in media_intro.php.
 * No id is emitted unless an editor sets common_settings.sec_id (the source's id="what"
 * belongs to the media_intro half, which is the footer-nav anchor target).
 *
 * JS hooks carried by this markup:
 *   [data-waitlist] — WAITLIST_URL overwrites the CTA href on load (keep the attribute).
 *   .rv             — scroll-reveal class on the .lockup wrapper.
 *
 * LINT (justified WARNs — lint_partial.py):
 *   Against the whole 02-what.html fragment the split shows up as missing <h2>/<i>/<b>
 *   (they belong to media_intro.php). Verified instead against the byte-sliced bottom half
 *   of the fragment: clean apart from
 *   WARN <span> 1 < 2 — the <span class="limitless grad-word"> accent now comes from the
 *   `lockup_line` field value (restricted wp_kses keeps it).
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_logo_id    = get_sub_field( 'lockup_logo' );
$vst_logo_src   = vst_image_url( $vst_logo_id );
$vst_logo_alt   = vst_image_alt( $vst_logo_id );
$vst_lockup     = get_sub_field( 'lockup_line' );
$vst_body_1     = get_sub_field( 'lockup_body_1' );
$vst_body_2     = get_sub_field( 'lockup_body_2' );
$vst_cta        = get_sub_field( 'cta' );
$vst_cta_url    = vst_link_url( $vst_cta );
$vst_cta_label  = vst_link_label( $vst_cta );
$vst_cta_target = vst_link_target( $vst_cta );
$vst_sec        = vst_section_setup(
	array(
		/*
		 * QA fix (desktop 1920, iter 1, root cause #2) — see media_intro.php's matching
		 * comment. `sec-what-bottom` (assets/scss/main.scss, additive) zeroes this partial's
		 * TOP padding only; its bottom padding (120px, the real boundary before `the_plan`
		 * starts) is untouched. `.lockup`'s own padding-top:110px (unchanged, transplanted
		 * verbatim) becomes the sole gap above the lockup content again, matching source.
		 */
		'class'  => 'sec sec-what-bottom',
		'layout' => 'lockup_band',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="lockup rv">
      <div class="lockup-line">
        <?php if ( $vst_logo_src ) : ?>
        <img class="lockup-img" src="<?php echo esc_url( $vst_logo_src ); ?>" alt="<?php echo esc_attr( $vst_logo_alt ); ?>">
        <?php endif; ?>
        <span><?php echo vst_accent_html( $vst_lockup ); ?></span>
      </div>
      <p><?php echo vst_multiline_html( $vst_body_1 ); ?></p>
      <p class="strong"><?php echo vst_multiline_html( $vst_body_2 ); ?></p>
      <?php if ( $vst_cta_url ) : ?>
      <a class="btn" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>