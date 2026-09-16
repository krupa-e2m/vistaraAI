<?php
/**
 * Section: the_energy  (FC layout `the_energy`)
 *
 * Source fragment : spec/fragments/home/10-the-energy.html  (home / section id `pos-10`)
 * Transplanted verbatim, including the .portal's decorative .scan / .veil / .play children.
 * `media` is a PLAIN image field here (approved decision #2: source parity, no image/video
 * toggle) — the [data-video] hook and its JS zoom fallback work directly against this <img>.
 *
 * JS hooks carried by this markup:
 *   [data-video]  — .portal (data-video="denver") opens the shared #modal; VIDEO_URLS is empty
 *                   in source so it falls back to zooming the child <img>. The attribute and
 *                   the <img> must stay together.
 *   .rv / .d1-.d2 — scroll-reveal + stagger classes.
 *
 * STATIC BY DESIGN:
 *   .portal aria-label — its wording ("Play the Vistara recap — September 2025, Denver,
 *     Colorado") differs from media_tag ("September 2025 · Denver, Colorado"), so deriving it
 *     would change the rendered text. Same manual-sync note the plan records for hero.
 *   data-video="denver" — a VIDEO_URLS config key, not content.
 *
 * LINT (justified WARN/ERROR — lint_partial.py --fragment):
 *   ERROR tag <br> MISSING — the source's single <br> lives INSIDE the `heading` value (plan:
 *     "preserve <span class=\"grad-word\"> around 'Austin turned it up.' (own line after
 *     <br>)"). Restricted wp_kses allows <br>, so it renders from the DB; hardcoding it would
 *     make half the headline non-editable. Accepted checker limitation, not a dropped node.
 *   WARN <span> 6 < 7 — same cause: the heading's grad-word span comes from the field value.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow   = get_sub_field( 'eyebrow' );
$vst_heading   = get_sub_field( 'heading' );
$vst_media_id  = get_sub_field( 'media' );
$vst_media_src = vst_image_url( $vst_media_id );
$vst_media_alt = vst_image_alt( $vst_media_id );
$vst_media_tag = get_sub_field( 'media_tag' );
$vst_sec       = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'the_energy',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap center">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <div class="portal portal-narrow rv d2" data-video="denver" role="button" tabindex="0" aria-label="Play the Vistara recap — September 2025, Denver, Colorado" style="margin-top:3.2rem">
      <?php if ( $vst_media_src ) : ?>
      <img src="<?php echo esc_url( $vst_media_src ); ?>" alt="<?php echo esc_attr( $vst_media_alt ); ?>">
      <?php endif; ?>
      <span class="scan" aria-hidden="true"></span>
      <span class="veil" aria-hidden="true"></span>
      <span class="portal-tag"><span class="dot"></span><?php echo esc_html( $vst_media_tag ); ?></span>
      <span class="play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
    </div>
  </div>
</section>