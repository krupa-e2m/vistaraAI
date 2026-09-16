<?php
/**
 * Section: gallery  (FC layout `gallery`)
 *
 * Source fragment : spec/fragments/home/11-gallery.html  (home / section id `pos-11`)
 * Transplanted verbatim. The 5 .gitem tiles come from the `gallery_photos` ACF Gallery
 * field (converted from a one-image-per-row repeater per client feedback 2026-08-19 —
 * editors can now multi-select images in one media-modal pass and drag to reorder). The
 * pager chrome (#gPagePrev / #gPageNext with their `hidden` attribute, #gDots, .gal-glow)
 * is generated/toggled by JS and is kept exactly as authored.
 *
 * The field returns an ARRAY of attachment IDs (return_format `id`) — the aria-labels need
 * the total count ("photo 3 of 5"), so a plain foreach over the array is used.
 *
 * JS hooks carried by this markup:
 *   .gal-mosaic / .gitem / [data-zoom] — the script reads the seeded .gitem <img> tags as the
 *     base gallery set and turns the mosaic into a paged carousel + lightbox when the total
 *     exceeds the slot count. Keep .gitem, data-zoom, role/tabindex and the .gidx span.
 *   #gPagePrev / #gPageNext / #gDots  — pager UI, shown by JS only when needed.
 *   .rv / .d1-.d2                     — scroll-reveal + stagger; the per-tile delay class is
 *     derived from the row index (0 => '', odd => d1, even => d2), matching source exactly.
 *   NOTE: the first .gitem spans 2x2 in the CSS grid (featured tile) purely by :first-child
 *   styling, so photo order alone controls it.
 *
 * STATIC BY DESIGN (approved plan):
 *   gallery index label — a positional counter that JS actively renumbers on carousel
 *   paging, so an authored value would be silently discarded. Rendered with
 *   sprintf( '%02d', index + 1 ).
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow = get_sub_field( 'eyebrow' );
$vst_heading = get_sub_field( 'heading' );
$vst_photos  = get_sub_field( 'gallery_photos' );
$vst_photos  = is_array( $vst_photos ) ? array_values( $vst_photos ) : array();
$vst_total   = count( $vst_photos );
$vst_sec     = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'gallery',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap center">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <div class="gal-shell rv d1">
      <div class="gal-stage">
        <button class="tnav prev" id="gPagePrev" hidden aria-label="Previous gallery photos"><svg viewBox="0 0 24 24"><path d="M14.5 6 9 12l5.5 6"/></svg></button>
    <div class="gal-mosaic">
      <?php foreach ( $vst_photos as $vst_g_i => $vst_photo_id ) : ?>
      <?php
      $vst_photo_id  = (int) $vst_photo_id;
      $vst_photo_src = vst_image_url( $vst_photo_id );
      $vst_photo_alt = vst_image_alt( $vst_photo_id );
      $vst_g_delay   = $vst_g_i ? 'd' . ( ( 1 === $vst_g_i % 2 ) ? 1 : 2 ) : '';
      ?>
      <div class="gitem rv <?php echo esc_attr( $vst_g_delay ); ?>" data-zoom role="button" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: photo position, 2: total photos. */ __( 'View Vistara gallery photo %1$d of %2$d', 'vistara' ), $vst_g_i + 1, $vst_total ) ); ?>">
        <span class="gidx"><?php echo esc_html( sprintf( '%02d', $vst_g_i + 1 ) ); ?></span>
        <?php if ( $vst_photo_src ) : ?>
        <img src="<?php echo esc_url( $vst_photo_src ); ?>" alt="<?php echo esc_attr( $vst_photo_alt ); ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
        <button class="tnav next" id="gPageNext" hidden aria-label="Next gallery photos"><svg viewBox="0 0 24 24"><path d="M9.5 6 15 12l-5.5 6"/></svg></button>
      </div>
      <div class="gdots" id="gDots"></div>
      <div class="gal-glow" aria-hidden="true"></div>
    </div>
  </div>
</section>
