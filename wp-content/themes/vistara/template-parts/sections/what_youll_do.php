<?php
/**
 * Section: what_youll_do  (FC layout `what_youll_do`)
 *
 * Source fragment : spec/fragments/home/04-what-youll-do.html  (home / section id `pos-04`)
 * Transplanted verbatim. .do-left is position:sticky on desktop; the 5 .cell tiles are the
 * `do_items` repeater — the PROTOTYPE is the richest source cell (item 3, the one carrying a
 * <span class="stat">), so the optional stat renders when set and the element is omitted
 * when blank (only 2 of 5 source items have one).
 *
 * JS hooks carried by this markup:
 *   .cell           — pointermove sets --cx/--cy per tile for the cursor-follow glow, so the
 *                     .cell class must stay on every repeater item.
 *   [data-waitlist] — WAITLIST_URL overwrites the CTA href on load.
 *   .rv / .d1-.d4   — scroll-reveal + stagger; the per-row delay class is derived from the
 *                     repeater index (row 1 => none, rows 2..5 => d1..d4), matching source.
 *
 * STATIC BY DESIGN (approved plan):
 *   do_items.index_label — static_content entry #2: positional counter, rendered with
 *                          sprintf( '%02d', index + 1 ) so reordering rows can never desync it.
 *
 * MEDIA: shared Media Block clone (seamless => sub-fields read by their OWN names). The
 * source only ships an <img>; the video branch is additive.
 *
 * LINT (justified WARNs — lint_partial.py --fragment): every count drop is the 5-cell
 *   repeater collapsing to one prototype —
 *   <div> 8<12, <span> 4<9, <h3> 1<5, <p> 3<7. Verified 1:1 against the source cell markup.
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
$vst_list_lead       = get_sub_field( 'list_lead' );
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
		'layout' => 'what_youll_do',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="do-grid">
      <div class="do-left">
        <span class="marker rv"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
        <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
        <p class="lede rv d2" style="margin-top:1.4rem"><?php echo vst_multiline_html( $vst_body ); ?></p>
        <div class="holo holo-glow rv d2">
          <?php if ( 'video' === $vst_media_type ) : ?>
          <?php vst_media_video_html( $vst_media ); ?>
          <?php elseif ( $vst_media_src ) : ?>
          <?php echo vst_picture_open_html( $vst_media_mobile_id ); ?><img src="<?php echo esc_url( $vst_media_src ); ?>" alt="<?php echo esc_attr( $vst_media_alt ); ?>"><?php echo vst_picture_close_html( $vst_media_mobile_id ); ?>
          <?php endif; ?>
          <span class="htag"><?php echo esc_html( $vst_media_tag ); ?></span>
        </div>
      </div>

      <div class="do-right">
        <p class="do-lead rv"><?php echo esc_html( $vst_list_lead ); ?></p>
        <div class="do-list">
          <?php $vst_cell_i = 0; ?>
          <?php while ( have_rows( 'do_items' ) ) : the_row(); ?>
          <?php $vst_stat = get_sub_field( 'stat_callout' ); ?>
          <div class="cell rv<?php echo esc_attr( $vst_cell_i ? ' d' . $vst_cell_i : '' ); ?>">
            <?php if ( '' !== (string) $vst_stat ) : ?>
            <span class="stat" aria-hidden="true"><?php echo esc_html( $vst_stat ); ?></span>
            <?php endif; ?>
            <span class="idx"><?php echo esc_html( sprintf( '%02d', $vst_cell_i + 1 ) ); ?></span>
            <h3><?php echo vst_accent_html( get_sub_field( 'heading' ) ); ?></h3>
            <p><?php echo vst_multiline_html( get_sub_field( 'body' ) ); ?></p>
          </div>
          <?php ++$vst_cell_i; ?>
          <?php endwhile; ?>
        </div>
        <div class="rv d2" style="margin-top:1.8rem">
          <?php if ( $vst_cta_url ) : ?>
          <a class="btn" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>