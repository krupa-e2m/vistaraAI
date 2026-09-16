<?php
/**
 * Section: behind_vistara  (FC layout `behind_vistara`)
 *
 * Source fragment : spec/fragments/home/13-behind-vistara.html  (home / section id `pos-13`)
 * Transplanted verbatim.
 *
 * JS hooks carried by this markup: .rv / .d1-.d3 scroll-reveal + stagger classes.
 *
 * WYSIWYG DEVIATION (approved plan decision #6): `body_1` is an ACF wysiwyg, not a plain text
 * field, because the source embeds an inline <span class="e2m-chip"><img alt="E2M"></span> logo
 * badge mid-sentence which restricted wp_kses (em/span/strong/br) cannot carry. It is rendered
 * through vst_wysiwyg_inline_html() so a single authored paragraph is UNWRAPPED into the
 * source's <p class="lede rv d2"> instead of nesting a second <p> inside it. If an editor adds
 * a second paragraph the value is emitted whole and the extra paragraphs render as siblings
 * without the .lede class — the documented, non-breaking degradation.
 * Seed content must include the inline <img> chip at its authored position.
 *
 * LINT (justified WARN/ERROR — lint_partial.py --fragment):
 *   ERROR tag <img> MISSING — the source's only <img> is the E2M chip INSIDE the `body_1`
 *     wysiwyg value (see above). It renders from the DB via wp_kses_post; hardcoding it in the
 *     template would make it non-editable and would leave the surrounding sentence unable to
 *     move around it. Accepted checker limitation, not a dropped node.
 *   WARN <span> 1 < 3 — the .e2m-chip span (inside body_1) and the closing statement's
 *     <span class="grad-word"> (inside closing_statement, restricted wp_kses) both come from
 *     field values; only the .marker span is template markup.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow = get_sub_field( 'eyebrow' );
$vst_heading = get_sub_field( 'heading' );
$vst_body_1  = get_sub_field( 'body_1' );
$vst_body_2  = get_sub_field( 'body_2' );
$vst_closing = get_sub_field( 'closing_statement' );
$vst_sec     = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'behind_vistara',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap behind">
    <span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
    <h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
    <p class="lede rv d2"><?php echo vst_wysiwyg_inline_html( $vst_body_1 ); ?></p>
    <p class="lede rv d2"><?php echo vst_multiline_html( $vst_body_2 ); ?></p>
    <p class="ride rv d3"><?php echo vst_accent_html( $vst_closing ); ?></p>
  </div>
</section>