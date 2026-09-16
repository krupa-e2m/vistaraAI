<?php
/**
 * Section: ticker  (FC layout `ticker`)
 *
 * Source fragment : spec/fragments/home/01-ticker.html  (home / section id `ticker`)
 * Transplanted verbatim; only the marquee text is dynamic.
 *
 * JS hooks carried by this markup (spec.js_hooks):
 *   #tk / .tk span  — the script does tk.innerHTML = tk.innerHTML.repeat(6) at runtime to
 *                     build the seamless marquee. ONE authored instance only; do not loop.
 *
 * DECOMPOSITION NOTE (flagged in spec section `ticker` as an open question):
 * the source span is `Pause <svg> Reflect <svg> Accelerate <svg>` — three words with an
 * identical decorative spark icon after each. The approved schema is a single text field
 * whose sample is "Pause · Reflect · Accelerate", i.e. the middot marks each icon slot.
 * The template therefore splits the authored value on · and emits the verbatim spark SVG
 * after each segment, reproducing the source byte-for-byte for the authored sample.
 *
 * LINT (justified WARNs — lint_partial.py --fragment):
 *   WARN <svg> 1 < 3, <path> 1 < 3 — the three identical spark icons are one prototype
 *   inside the segment loop above; three segments render three icons. Expected reduction.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_ticker_text  = (string) get_sub_field( 'ticker_text' );
$vst_ticker_parts = array_values( array_filter( array_map( 'trim', explode( '·', $vst_ticker_text ) ), 'strlen' ) );
$vst_sec          = vst_section_setup(
	array(
		'class'       => 'ticker',
		'layout'      => 'ticker',
		'extra_attrs' => 'aria-hidden="true"',
	)
);

if ( ! $vst_ticker_parts ) {
	return;
}

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<div<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="tk" id="tk">
    <span><?php foreach ( $vst_ticker_parts as $vst_i => $vst_part ) : ?><?php echo ( $vst_i ? ' ' : '' ) . esc_html( $vst_part ) . ' '; ?><svg class="sk" viewBox="0 0 20 20"><path d="M10 0l2.4 7.6L20 10l-7.6 2.4L10 20l-2.4-7.6L0 10l7.6-2.4L10 0Z" fill="#F0793E"/></svg><?php endforeach; ?></span>
  </div>
</div>