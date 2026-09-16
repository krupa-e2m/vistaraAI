<?php
/**
 * Section: participants  (FC layout `participants`)
 *
 * Source fragment : spec/fragments/home/12-participants.html  (home / section id `pos-12`)
 * Transplanted verbatim, including the #stageBlur / .play decorations and both .tnav arrows.
 * The 6 .thumb buttons become the `testimonial_thumbs` repeater around ONE prototype button.
 *
 * The featured stage (#stageFg / #stageImg / data-video) is NOT a separate field: per spec it
 * always mirrors thumb[0] (the script calls setStage(0) on load). The template therefore
 * derives the stage image + data-video key from the FIRST repeater row, so no authored value
 * can ever desync from what the JS shows.
 *
 * The repeater is read as an ARRAY (get_sub_field) rather than with have_rows(), because row 0
 * is needed before the loop — and a plain foreach cannot poison ACF's have_rows cursor for
 * later partials (the early-break footgun in html-acf-standards).
 *
 * JS hooks carried by this markup:
 *   #stageFg / #stageImg / #stageBlur / #tThumbs / #tPrev / #tNext / .thumb / [data-tv] —
 *     clicking or arrow-navigating a thumb swaps #stageImg src, the #stageBlur background and
 *     the .is-active class. data-tv must stay unique per row and match a VIDEO_URLS key.
 *   [data-video] — the stage opens the shared #modal (all VIDEO_URLS values are empty in
 *     source, so it falls back to zooming the stage <img>).
 *   .rv / .d1     — scroll-reveal + stagger classes.
 *
 * SPEC MISMATCH FLAGGED (spec section pos-12 notes): the source script's comments claim p1-p3
 * are "featured" and p4-p6 a "strip", but the markup is one unified 6-thumb row feeding one
 * stage. The template follows the MARKUP (one repeater), as the spec recommends.
 *
 * LINT (justified WARNs — lint_partial.py --fragment): <button> 3<8, <img> 2<7 — the
 *   6-thumb repeater collapsing to one prototype. <svg>/<path> counts match the fragment.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow    = get_sub_field( 'eyebrow' );
$vst_heading    = get_sub_field( 'heading' );
$vst_thumbs     = get_sub_field( 'testimonial_thumbs' );
$vst_thumbs     = is_array( $vst_thumbs ) ? array_values( $vst_thumbs ) : array();
$vst_first      = isset( $vst_thumbs[0] ) ? $vst_thumbs[0] : array();
$vst_stage_key  = isset( $vst_first['video_key'] ) ? (string) $vst_first['video_key'] : '';
$vst_stage_id   = isset( $vst_first['thumbnail'] ) ? $vst_first['thumbnail'] : 0;
$vst_stage_src  = vst_image_url( $vst_stage_id );
$vst_stage_alt  = vst_image_alt( $vst_stage_id );
$vst_sec        = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'participants',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="center">
      <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
      <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    </div>
    <div class="stage-wrap rv d1">
      <div class="stage">
        <div class="stage-media" id="stageFg" data-video="<?php echo esc_attr( $vst_stage_key ); ?>" role="button" tabindex="0" aria-label="Play this participant testimonial">
          <span class="stage-blur" id="stageBlur" aria-hidden="true"></span>
          <?php if ( $vst_stage_src ) : ?>
          <img id="stageImg" src="<?php echo esc_url( $vst_stage_src ); ?>" alt="<?php echo esc_attr( $vst_stage_alt ); ?>">
          <?php endif; ?>
          <span class="play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
        </div>
        <button class="tnav prev" id="tPrev" aria-label="Previous testimonial"><svg viewBox="0 0 24 24"><path d="M14.5 6 9 12l5.5 6"/></svg></button>
        <button class="tnav next" id="tNext" aria-label="Next testimonial"><svg viewBox="0 0 24 24"><path d="M9.5 6 15 12l-5.5 6"/></svg></button>
      </div>
      <div class="thumbs" id="tThumbs">
        <?php foreach ( $vst_thumbs as $vst_t_i => $vst_thumb_row ) : ?>
        <?php
        $vst_thumb_id  = isset( $vst_thumb_row['thumbnail'] ) ? $vst_thumb_row['thumbnail'] : 0;
        $vst_thumb_src = vst_image_url( $vst_thumb_id );
        $vst_thumb_alt = vst_image_alt( $vst_thumb_id );
        $vst_thumb_key = isset( $vst_thumb_row['video_key'] ) ? (string) $vst_thumb_row['video_key'] : '';
        ?>
        <button class="thumb<?php echo $vst_t_i ? '' : ' is-active'; ?>" data-tv="<?php echo esc_attr( $vst_thumb_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: testimonial position. */ __( 'Show participant testimonial %d', 'vistara' ), $vst_t_i + 1 ) ); ?>"><?php if ( $vst_thumb_src ) : ?><img src="<?php echo esc_url( $vst_thumb_src ); ?>" alt="<?php echo esc_attr( $vst_thumb_alt ); ?>"><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>