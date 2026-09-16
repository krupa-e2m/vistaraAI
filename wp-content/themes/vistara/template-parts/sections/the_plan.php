<?php
/**
 * Section: the_plan  (FC layout `the_plan`)
 *
 * Source fragment : spec/fragments/home/03-the-plan.html  (home / section id `pos-03`)
 * Transplanted verbatim. Media panel is FIRST in source order (.wrap.grid-2.flip = media
 * left / copy right) — the flipped variant of media_intro; kept as its own layout because
 * the field set also differs (adds terminal_line + cta), so the merge test fails.
 * Decorative nodes kept: .term-bar's three <i> "window chrome" dots, the `>_ ` prompt and
 * the blinking .caret.
 *
 * JS hooks carried by this markup:
 *   [data-waitlist] — WAITLIST_URL overwrites the CTA href on load (keep the attribute).
 *   .rv / .d1-.d4   — scroll-reveal + stagger classes.
 *
 * MEDIA: shared Media Block clone (seamless => sub-fields read by their OWN names). The
 * source only ships an <img>; the video branch is additive and only renders when an editor
 * switches media_type to Video. <picture>/<source> only when a mobile image is uploaded.
 *
 * LINT (justified WARN/ERROR — lint_partial.py --fragment):
 *   ERROR tag <em> MISSING — both <em> wraps (pause, reflect / accelerate) live INSIDE the
 *     `terminal_line` value (plan: "Restricted HTML — preserve the 3 <em> wraps"); restricted
 *     wp_kses allows <em>, so they render from the DB. Hardcoding them would make part of the
 *     sentence non-editable — the defect the coverage gate forbids. Checker limitation.
 *   WARN <span> 4 < 5 — the heading's <span class="grad-word"> now comes from `heading`.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow         = get_sub_field( 'eyebrow' );
$vst_heading         = get_sub_field( 'heading' );
$vst_body            = get_sub_field( 'body' );
$vst_terminal_line   = get_sub_field( 'terminal_line' );
$vst_media_tag       = get_sub_field( 'media_tag' );
$vst_media_type      = get_sub_field( 'media_type' );
$vst_media_id        = get_sub_field( 'image' );
$vst_media_mobile_id = get_sub_field( 'mobile_img' );
$vst_media_src       = vst_image_url( $vst_media_id );
$vst_media_alt       = vst_image_alt( $vst_media_id );
$vst_media           = array(
	'video_type'          => get_sub_field( 'video_type' ),
	'custom_video'        => get_sub_field( 'custom_video' ),
	'custom_video_mobile' => get_sub_field( 'custom_video_mobile' ),
	'third_party_url'     => get_sub_field( 'third_party_url' ),
	'poster'              => $vst_media_id,
	'label'               => $vst_media_tag,
);
$vst_cta             = get_sub_field( 'cta' );
$vst_cta_url         = vst_link_url( $vst_cta );
$vst_cta_label       = vst_link_label( $vst_cta );
$vst_cta_target      = vst_link_target( $vst_cta );
$vst_sec             = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'the_plan',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap grid-2 flip">
    <div class="g-media rv d2">
      <div class="holo holo-glow">
        <?php if ( 'video' === $vst_media_type ) : ?>
        <?php vst_media_video_html( $vst_media ); ?>
        <?php elseif ( $vst_media_src ) : ?>
        <?php echo vst_picture_open_html( $vst_media_mobile_id ); ?><img src="<?php echo esc_url( $vst_media_src ); ?>" alt="<?php echo esc_attr( $vst_media_alt ); ?>"><?php echo vst_picture_close_html( $vst_media_mobile_id ); ?>
        <?php endif; ?>
        <span class="htag"><?php echo esc_html( $vst_media_tag ); ?></span>
      </div>
    </div>
    <div>
      <span class="marker rv"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
      <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
      <p class="lede rv d2" style="margin-top:1.4rem"><?php echo vst_multiline_html( $vst_body ); ?></p>
      <div class="term rv d3" role="presentation">
        <div class="term-bar"><i></i><i></i><i></i></div>
        <div class="term-body"><span class="pr">&gt;_ </span><?php echo vst_accent_html( $vst_terminal_line ); ?><span class="caret" aria-hidden="true"></span></div>
      </div>
      <?php if ( $vst_cta_url ) : ?>
      <a class="btn rv d4" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>