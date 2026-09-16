<?php
/**
 * Section: speakers  (FC layout `speakers`)
 *
 * Source fragment : spec/fragments/home/09-speakers.html  (home / section id `speakers`)
 * Transplanted verbatim. The 11 .sp cards now come from the `speaker` CPT (client feedback
 * 2026-08-19 — repeatable entities live in a CPT, not an inline page repeater): every
 * published Speaker post renders here, ordered by menu_order (the Order box) then date.
 * Name = post title, photo = featured image, role = ACF `role` field. The source's
 * hardcoded id="speakers" (footer-nav anchor) still comes from common_settings.sec_id.
 *
 * NOTE ON THE PROTOTYPE CLASS: the source's first card is class="sp rv " WITH a trailing
 * space (the delay slot is empty for every 4th card). The template reproduces that byte for
 * byte — 'sp rv ' plus d1/d2/d3 derived from index % 4, exactly matching the source's
 * '', d1, d2, d3, '', d1, … sequence across the 11 cards.
 *
 * JS hooks carried by this markup: .rv scroll-reveal class on every card.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow  = get_sub_field( 'eyebrow' );
$vst_heading  = get_sub_field( 'heading' );
$vst_body     = get_sub_field( 'body' );
$vst_speakers = get_posts(
	array(
		'post_type'      => 'speaker',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => array(
			'menu_order' => 'ASC',
			'date'       => 'ASC',
		),
	)
);
$vst_sec      = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'speakers',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap center">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <p class="lede rv d2" style="margin:1.3rem auto 0"><?php echo vst_multiline_html( $vst_body ); ?></p>
    <div class="sp-grid">
      <?php foreach ( $vst_speakers as $vst_sp_i => $vst_speaker ) : ?>
      <?php
      $vst_photo_id  = get_post_thumbnail_id( $vst_speaker );
      $vst_photo_src = vst_image_url( $vst_photo_id );
      $vst_photo_alt = vst_image_alt( $vst_photo_id, $vst_speaker->post_title );
      ?>
      <div class="sp rv <?php echo esc_attr( $vst_sp_i % 4 ? 'd' . ( $vst_sp_i % 4 ) : '' ); ?>">
        <div class="ph"><?php if ( $vst_photo_src ) : ?><img src="<?php echo esc_url( $vst_photo_src ); ?>" alt="<?php echo esc_attr( $vst_photo_alt ); ?>"><?php endif; ?></div>
        <div class="nm"><h3><?php echo esc_html( $vst_speaker->post_title ); ?></h3><p><?php echo esc_html( (string) get_field( 'role', $vst_speaker->ID ) ); ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
