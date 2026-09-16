<?php
/**
 * Section: schedule  (FC layout `schedule`)
 *
 * Source fragment : spec/fragments/home/08-schedule.html  (home / section id `schedule`)
 * Transplanted verbatim. The 3 .node items become the `schedule_nodes` repeater around ONE
 * prototype node — the RICHEST source node (item 1, the only one with two agenda lines), so
 * item_line_2 renders when set and its <p> is omitted when blank (approved decision #5: two
 * fixed optional text lines instead of a nested sub-repeater). The source's hardcoded
 * id="schedule" (footer-nav "Agenda" anchor) now comes from common_settings.sec_id — set it
 * to `schedule` on this row.
 *
 * JS hooks carried by this markup:
 *   [data-waitlist] — WAITLIST_URL overwrites each per-node CTA href on load.
 *   .rv / .d1-.d2   — scroll-reveal + stagger; the per-node delay class is derived from the
 *                     repeater index (row 1 => none, rows 2..3 => d1, d2), matching source.
 *
 * Clone read contract: the per-node `cta` is a seamless clone of the shared cta link field,
 * so it is read INSIDE the row by its OWN name — get_sub_field( 'cta' ), never a nested path.
 *
 * LINT (justified WARNs — lint_partial.py --fragment): <div> 6<12, <p> 2<4 and <a> 1<3 are
 *   the 3-node repeater collapsing to one prototype.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow = get_sub_field( 'eyebrow' );
$vst_heading = get_sub_field( 'heading' );
$vst_sec     = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'schedule',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="center" style="margin-bottom:3.2rem">
      <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
      <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    </div>

    <div class="timeline">
      <?php $vst_node_i = 0; ?>
      <?php while ( have_rows( 'schedule_nodes' ) ) : the_row(); ?>
      <?php
      $vst_line_1     = get_sub_field( 'item_line_1' );
      $vst_line_2     = get_sub_field( 'item_line_2' );
      $vst_cta        = get_sub_field( 'cta' );
      $vst_cta_url    = vst_link_url( $vst_cta );
      $vst_cta_label  = vst_link_label( $vst_cta );
      $vst_cta_target = vst_link_target( $vst_cta );
      ?>
      <div class="node rv<?php echo esc_attr( $vst_node_i ? ' d' . $vst_node_i : '' ); ?>">
        <div class="node-date"><?php echo esc_html( get_sub_field( 'date_label' ) ); ?></div>
        <div class="node-items">
          <?php if ( '' !== (string) $vst_line_1 ) : ?>
          <p><?php echo esc_html( $vst_line_1 ); ?></p>
          <?php endif; ?>
          <?php if ( '' !== (string) $vst_line_2 ) : ?>
          <p><?php echo esc_html( $vst_line_2 ); ?></p>
          <?php endif; ?>
        </div>
        <?php if ( $vst_cta_url ) : ?>
        <a class="btn btn-sm" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?></a>
        <?php endif; ?>
      </div>
      <?php ++$vst_node_i; ?>
      <?php endwhile; ?>
    </div>
  </div>
</section>