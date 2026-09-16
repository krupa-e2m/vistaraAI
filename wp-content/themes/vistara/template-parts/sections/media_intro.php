<?php
/**
 * Section: media_intro  (FC layout `media_intro`)
 *
 * Source fragment : spec/fragments/home/02-what.html  (home / section id `what`) — TOP HALF.
 * Approved plan decision #3 splits that one <section> into media_intro (this 2-col grid) +
 * lockup_band (the centred band below). This partial keeps the section/.wrap shell and the
 * .grid-2 block verbatim; the .lockup block was removed and lives in lockup_band.php.
 * The source's hardcoded id="what" (footer-nav anchor target) now comes from
 * common_settings.sec_id — set it to `what` on this row.
 *
 * JS hooks carried by this markup: .rv / .d1-.d2 scroll-reveal + stagger classes.
 *
 * MEDIA: uses the shared Media Block clone (seamless => sub-fields read by their OWN
 * names: media_type / video_type / image / mobile_img / custom_video /
 * custom_video_mobile / third_party_url). The source only ever ships an <img>, so the
 * image branch is the verbatim source node; the video branch is additive and only renders
 * when an editor switches media_type to Video. A <picture>/<source> wrapper appears ONLY
 * when a separate mobile image is uploaded.
 *
 * LINT (justified WARNs — lint_partial.py):
 *   Against the whole 02-what.html fragment the split shows up as missing <a>/<p>/<img>
 *   counts (they belong to lockup_band.php). Verified instead against the byte-sliced
 *   top half of the fragment: clean apart from
 *   WARN <span> 2 < 3 — the heading's <span class="accent"> now comes from the `heading`
 *   field value (restricted wp_kses keeps it).
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
$vst_sec             = vst_section_setup(
	array(
		/*
		 * QA fix (desktop 1920, iter 1, root cause #2): media_intro + lockup_band are the
		 * approved split (decision #3) of ONE source <section class="sec" id="what"> that
		 * had a single top/bottom .sec gutter (120px each) for the whole combined content —
		 * the internal gap between the grid-2 block and the .lockup block was `.lockup`'s
		 * OWN padding-top:110px, not a second section boundary. Rendering each half as its
		 * own <section class="sec"> duplicates that boundary (this partial's 120px bottom +
		 * lockup_band's 120px top = 240px of gutter the source never had, on top of the
		 * .lockup class's real 110px). `sec-what-top` (assets/scss/main.scss, additive, no
		 * source counterpart) zeroes ONLY this partial's bottom padding so `.lockup`'s own
		 * 110px is the sole gap again, matching source exactly, without touching the shared
		 * `.sec` rule every other section still relies on.
		 */
		'class'  => 'sec sec-what-top',
		'layout' => 'media_intro',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="grid-2">
      <div>
        <span class="marker rv"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
        <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
        <p class="lede rv d2" style="margin-top:1.4rem"><?php echo vst_multiline_html( $vst_body ); ?></p>
      </div>
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
    </div>
  </div>
</section>