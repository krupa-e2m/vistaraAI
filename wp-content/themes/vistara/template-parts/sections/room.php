<?php
/**
 * Section: room  (FC layout `room`)
 *
 * Source fragment : spec/fragments/home/07-room.html  (home / section id `room`)
 * Transplanted verbatim. The 8 .tile items become the `audience_tiles` repeater around ONE
 * prototype tile; the source's hardcoded id="room" (footer-nav "Who Vistara Is For" anchor)
 * now comes from common_settings.sec_id — set it to `room` on this row.
 *
 * JS hooks carried by this markup:
 *   [data-waitlist] — WAITLIST_URL overwrites the CTA href on load (keep the attribute).
 *   .rv / .d1-.d3   — scroll-reveal + stagger; the per-tile delay class is derived from the
 *                     repeater index (index % 4 => '', d1, d2, d3), matching source exactly.
 *
 * STATIC BY DESIGN (approved plan):
 *   audience_tiles.icon — static_content entry #3: the spec explicitly flags these as "not a
 *     swappable image asset" (a unique decorative inline SVG per tile). All 8 source SVGs are
 *     kept verbatim as positional branches selected by index % 8, so no icon markup was
 *     re-authored and a 9th row would cycle back to the first icon.
 *
 * LINT (justified WARNs — lint_partial.py --fragment): <div> 5<12 and <p> 5<12 are the
 *   8-tile repeater collapsing to one prototype. <span> and <svg> counts match the fragment
 *   exactly because every one of the 8 icon branches is present in the template.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow    = get_sub_field( 'eyebrow' );
$vst_heading    = get_sub_field( 'heading' );
$vst_body_1     = get_sub_field( 'body_1' );
$vst_body_2     = get_sub_field( 'body_2' );
$vst_list_lead  = get_sub_field( 'list_lead' );
$vst_closing    = get_sub_field( 'closing_statement' );
$vst_cta        = get_sub_field( 'cta' );
$vst_cta_url    = vst_link_url( $vst_cta );
$vst_cta_label  = vst_link_label( $vst_cta );
$vst_cta_target = vst_link_target( $vst_cta );
$vst_sec        = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'room',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap center">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <div class="rv d2" style="margin-top:1.3rem">
      <p class="lede" style="margin:0 auto"><?php echo vst_multiline_html( $vst_body_1 ); ?></p>
      <p class="lede" style="margin:.3rem auto 0"><?php echo vst_multiline_html( $vst_body_2 ); ?></p>
    </div>
    <p class="includes rv d2"><?php echo esc_html( $vst_list_lead ); ?></p>

    <div class="tiles">
      <?php $vst_tile_i = 0; ?>
      <?php while ( have_rows( 'audience_tiles' ) ) : the_row(); ?>
      <?php $vst_tile_ico = $vst_tile_i % 8; ?>
      <div class="tile rv<?php echo esc_attr( $vst_tile_i % 4 ? ' d' . ( $vst_tile_i % 4 ) : '' ); ?>">
        <?php if ( 0 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 8.5h18M9.5 13l-2 2 2 2M14.5 13l2 2-2 2"/></svg></span>
        <?php elseif ( 1 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.3 3.9 5.2 3.9 8.5s-1.3 6.2-3.9 8.5c-2.6-2.3-3.9-5.2-3.9-8.5s1.3-6.2 3.9-8.5Z"/></svg></span>
        <?php elseif ( 2 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7.5"/><path d="M16.5 16.5 21 21M8 11h6M11 8v6"/></svg></span>
        <?php elseif ( 3 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><path d="M4.5 15.5a8 8 0 1 1 15 0"/><path d="M12 13.5 15.5 9"/><circle cx="12" cy="14" r="1.6"/><path d="M6 19.5h12"/></svg></span>
        <?php elseif ( 4 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M3.5 9h17M13 16.5l4.5-4.5c.7-.7 1.8-.7 2.4 0"/><path d="M8 13.5h2.5"/></svg></span>
        <?php elseif ( 5 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="2.2"/><circle cx="12" cy="4.5" r="1.6"/><circle cx="12" cy="19.5" r="1.6"/><circle cx="5" cy="8" r="1.6"/><circle cx="19" cy="8" r="1.6"/><circle cx="5" cy="16" r="1.6"/><circle cx="19" cy="16" r="1.6"/><path d="M12 9.8V6.1M12 14.2v3.7M10.1 11 6.4 8.9M13.9 11l3.7-2.1M10.1 13l-3.7 2.1M13.9 13l3.7 2.1"/></svg></span>
        <?php elseif ( 6 === $vst_tile_ico ) : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><path d="M4 10v4a1.5 1.5 0 0 0 1.5 1.5H8l6.5 3.5V5L8 8.5H5.5A1.5 1.5 0 0 0 4 10Z"/><path d="M8 15.5V19a1 1 0 0 0 1 1h1.5M17.5 9.5c.8 1.5.8 3.5 0 5M20 8c1.4 2.4 1.4 5.6 0 8"/></svg></span>
        <?php else : ?>
        <span class="tile-ico"><svg viewBox="0 0 24 24"><rect x="3.5" y="5.5" width="15" height="15" rx="3"/><path d="M8.5 16.5 11 10l2.5 6.5M9.5 14.5h3"/><path d="M19 3l.9 2.1L22 6l-2.1.9L19 9l-.9-2.1L16 6l2.1-.9L19 3Z" fill="currentColor" stroke="none"/></svg></span>
        <?php endif; ?>
        <p><?php echo esc_html( get_sub_field( 'label' ) ); ?></p>
      </div>
      <?php ++$vst_tile_i; ?>
      <?php endwhile; ?>
    </div>

    <p class="lede room-close rv"><?php echo vst_multiline_html( $vst_closing ); ?></p>
    <div class="room-cta rv d1">
      <?php if ( $vst_cta_url ) : ?>
      <a class="btn" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>