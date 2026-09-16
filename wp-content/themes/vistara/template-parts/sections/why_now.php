<?php
/**
 * Section: why_now  (FC layout `why_now`)
 *
 * Source fragment : spec/fragments/home/05-why-now.html  (home / section id `pos-05`)
 * Transplanted verbatim. The two .diff cards are NOT a repeater: approved plan decision #4
 * models them as two named fixed groups (verdict_card_negative = .diff-no with the red − ghost
 * glyph and X icon, verdict_card_positive = .diff-ok with the green + ghost glyph and check
 * icon), because the pair is a fixed asymmetric "bad outcome / good outcome" design. Both
 * cards' decorative .ghost glyphs and .d-ico SVGs are kept verbatim in place.
 *
 * Clone read contract: common_settings is a seamless clone, so its sub-fields are read by
 * their own names inside vst_section_setup(); the two verdict groups are plain ACF groups and
 * are read as arrays.
 *
 * JS hooks carried by this markup: .rv / .d1-.d3 scroll-reveal + stagger classes.
 *
 * LINT: `php -l` clean; lint_partial.py --fragment reports no findings.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow  = get_sub_field( 'eyebrow' );
$vst_heading  = get_sub_field( 'heading' );
$vst_quote    = get_sub_field( 'quote' );
$vst_body     = get_sub_field( 'body' );
$vst_closing  = get_sub_field( 'closing_statement' );
$vst_card_neg = (array) get_sub_field( 'verdict_card_negative' );
$vst_card_pos = (array) get_sub_field( 'verdict_card_positive' );
$vst_sec      = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'why_now',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap center">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <p class="quote rv d2"><?php echo vst_multiline_html( $vst_quote ); ?></p>
    <p class="lede rv d2" style="margin:0 auto"><?php echo vst_multiline_html( $vst_body ); ?></p>

    <div class="verdict">
      <div class="diff diff-no rv d2">
        <span class="ghost" aria-hidden="true">−</span>
        <span class="d-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/></svg></span>
        <h3><?php echo vst_accent_html( isset( $vst_card_neg['heading'] ) ? $vst_card_neg['heading'] : '' ); ?></h3>
        <p><?php echo vst_multiline_html( isset( $vst_card_neg['body'] ) ? $vst_card_neg['body'] : '' ); ?></p>
      </div>
      <div class="diff diff-ok rv d3">
        <span class="ghost" aria-hidden="true">+</span>
        <span class="d-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.5 12.5 10 18 19.5 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <h3><?php echo vst_accent_html( isset( $vst_card_pos['heading'] ) ? $vst_card_pos['heading'] : '' ); ?></h3>
        <p><?php echo vst_multiline_html( isset( $vst_card_pos['body'] ) ? $vst_card_pos['body'] : '' ); ?></p>
      </div>
    </div>

    <p class="why-close rv"><?php echo vst_multiline_html( $vst_closing ); ?></p>
  </div>
</section>