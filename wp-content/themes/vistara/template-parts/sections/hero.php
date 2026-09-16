<?php
/**
 * Section: hero  (FC layout `hero`)
 *
 * Source fragment : spec/fragments/home/00-hero.html  (home / section id `pos-01`)
 * Transplanted verbatim; only the nodes listed in spec.pages[0].sections[0].fields are
 * replaced. Every decorative node is kept: <canvas id="net">, .hero-frame + its four
 * <i>, .scan/.veil/.play spans, .scroll-cue, the `>_` prompt and the blinking .caret.
 *
 * JS hooks carried by this markup (spec.js_hooks):
 *   [data-waitlist]  — WAITLIST_URL overwrites the CTA href on load (keep the attribute).
 *   [data-scramble]  — the two .grad-word spans inside the headline decode on load; their
 *                      textContent IS the final value, so the field carries plain text
 *                      inside those spans.
 *   #net             — decorative particle canvas sized to .hero-media.
 *   [data-video]      — .portal (data-video="austin") opens the shared #modal; VIDEO_URLS
 *                      is empty in source so it falls back to zooming the child <img>.
 *   .rv / .d1-.d4    — scroll-reveal + stagger classes.
 *
 * STATIC BY DESIGN (approved plan):
 *   meta_pills.icon        — static_content entry #1: per-row decorative SVG (calendar for
 *                            row 1, pin for row 2), rendered positionally.
 *   .portal aria-label     — plan note on `media_tag`: "also mirrors the portal's aria-label
 *                            — keep in sync manually". Its wording differs from media_tag
 *                            (commas vs ·), so deriving it would change the rendered text.
 *   .portal id / data-video — JS config keys, not content.
 *
 * LINT (justified WARNs / ERROR — lint_partial.py --fragment):
 *   ERROR tag <br> MISSING  — the source's single <br> lives INSIDE the `headline` value
 *     (spec: "Verbatim markup: ... wrapped in <span class="nw"> no-wrap spans + a <br>").
 *     Restricted wp_kses allows <br>, so it renders from the DB. Hardcoding it in the
 *     template instead would make part of the headline non-editable — the exact defect the
 *     coverage gate forbids. Accepted checker limitation, not a dropped node.
 *   WARN <span> 11 < 16    — same cause: 4 headline spans + the accent spans now come from
 *     the `headline` field value.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow       = get_sub_field( 'eyebrow' );
$vst_headline      = get_sub_field( 'headline' );
$vst_terminal_line = get_sub_field( 'terminal_line' );
$vst_media_id      = get_sub_field( 'media' );
$vst_media_src     = vst_image_url( $vst_media_id );
$vst_media_alt     = vst_image_alt( $vst_media_id );
$vst_media_tag     = get_sub_field( 'media_tag' );
$vst_cta           = get_sub_field( 'cta' ); // `hero_cta` is a seamless clone of the shared cta link field — read by its OWN name.
$vst_cta_url       = vst_link_url( $vst_cta );
$vst_cta_label     = vst_link_label( $vst_cta );
$vst_cta_target    = vst_link_target( $vst_cta );
$vst_sec           = vst_section_setup(
	array(
		'class'  => 'hero',
		'layout' => 'hero',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <canvas id="net" aria-hidden="true"></canvas>
  <div class="hero-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

  <div class="wrap hero-core">
    <div class="hero-copy">
    <span class="hud-chip rv"><span class="dot"></span><?php echo esc_html( $vst_eyebrow ); ?></span>

    <h1 class="h-hero rv d1">
      <?php echo vst_accent_html( $vst_headline ); ?>
    </h1>

    <p class="hero-sub rv d2"><span class="pr">&gt;_</span> <?php echo esc_html( $vst_terminal_line ); ?><span class="caret" aria-hidden="true"></span></p>

    <?php if ( have_rows( 'meta_pills' ) ) : ?>
    <div class="hero-meta rv d3">
      <?php $vst_pill_i = 0; ?>
      <?php while ( have_rows( 'meta_pills' ) ) : the_row(); ?>
      <span class="meta-pill">
        <?php if ( 0 === $vst_pill_i % 2 ) : ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2.5" stroke="currentColor"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor"/></svg>
        <?php else : ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round"><path d="M12 21s7-5.4 7-11a7 7 0 1 0-14 0c0 5.6 7 11 7 11Z" stroke="currentColor"/><circle cx="12" cy="10" r="2.6" stroke="currentColor"/></svg>
        <?php endif; ?>
        <?php echo esc_html( get_sub_field( 'label' ) ); ?>
      </span>
      <?php ++$vst_pill_i; ?>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <div class="hero-cta rv d4">
      <?php if ( $vst_cta_url ) : ?>
      <a class="btn" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener">
        <?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <?php endif; ?>
    </div>
    </div>

    <div class="hero-media rv d2">
      <div class="portal" id="recap" data-video="austin" role="button" tabindex="0" aria-label="Play the Vistara recap — AI for Agencies, May 2026, Austin, Texas">
        <?php if ( $vst_media_src ) : ?>
        <img src="<?php echo esc_url( $vst_media_src ); ?>" alt="<?php echo esc_attr( $vst_media_alt ); ?>">
        <?php endif; ?>
        <span class="scan" aria-hidden="true"></span>
        <span class="veil" aria-hidden="true"></span>
        <span class="portal-tag"><span class="dot"></span><?php echo esc_html( $vst_media_tag ); ?></span>
        <span class="play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
      </div>
    </div>
  </div>
  <span class="scroll-cue" aria-hidden="true"></span>
</section>