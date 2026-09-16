<?php
/**
 * Section: pro_tip_callout  (FC layout `pro_tip_callout`)
 *
 * Source fragment : spec/fragments/home/06-pro-tip.html  (home / section id `pos-06`)
 * Transplanted verbatim. .note is the page's single CREAM canvas artifact (light card on an
 * all-dark page); its rotating conic-gradient border and the decorative .bulb light-bulb SVG
 * are purely visual and stay in place. The cream styling is the transplanted source CSS —
 * common_settings colour/padding overrides emit nothing unless an editor sets them.
 *
 * JS hooks carried by this markup:
 *   [data-waitlist] — WAITLIST_URL overwrites the CTA href on load (keep the attribute).
 *   .rv             — scroll-reveal class on the .note card.
 *
 * LINT: `php -l` clean; lint_partial.py --fragment reports no findings.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_heading    = get_sub_field( 'heading' );
$vst_lead       = get_sub_field( 'lead' );
$vst_body       = get_sub_field( 'body' );
$vst_cta        = get_sub_field( 'cta' );
$vst_cta_url    = vst_link_url( $vst_cta );
$vst_cta_label  = vst_link_label( $vst_cta );
$vst_cta_target = vst_link_target( $vst_cta );
$vst_sec        = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'pro_tip_callout',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="note rv">
      <span class="bulb" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M9.5 17.5h5M10 20.5h4M12 3a6 6 0 0 0-3.7 10.7c.7.6 1.2 1.4 1.2 2.3h5c0-.9.5-1.7 1.2-2.3A6 6 0 0 0 12 3Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </span>
      <h2 class="h-sec"><?php echo vst_accent_html( $vst_heading ); ?></h2>
      <p class="lead"><?php echo vst_multiline_html( $vst_lead ); ?></p>
      <p><?php echo vst_multiline_html( $vst_body ); ?></p>
      <?php if ( $vst_cta_url ) : ?>
      <a class="btn" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>