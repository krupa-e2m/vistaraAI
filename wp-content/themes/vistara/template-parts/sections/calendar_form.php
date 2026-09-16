<?php
/**
 * Section: calendar_form  (FC layout `calendar_form`)
 *
 * Source fragment : spec/fragments/home/14-calendar.html  (home / section id `calendar`)
 * Transplanted verbatim, including the two decorative field SVG icons inside the .fld labels
 * and the placeholder-as-label pattern (the source has no <label> text). .cal is the page's
 * WHITE canvas artifact. The source's hardcoded id="calendar" (spec anchor map) now comes from
 * common_settings.sec_id — set it to `calendar` on this row.
 *
 * FORM ENGINE: none (project-config.json project.form_engine = "none", approved decision #1).
 * Per the form-engine rule the designed markup is kept and rendered from the ACF copy fields
 * (first_name_placeholder / email_placeholder / submit_label), with a placeholder comment where
 * the plugin embed will go. `form_embed` is the single wiring field: paste the chosen plugin's
 * shortcode there and it replaces the designed form wholesale. Nothing about submission storage
 * is modelled in ACF.
 *
 * JS hooks carried by this markup:
 *   #calForm — the script always preventDefault()s; with FORM_ENDPOINT empty (as in source) it
 *     opens WAITLIST_URL in a new tab and discards the data. Keep the id, the novalidate
 *     attribute and the input name attributes (first_name / email).
 *
 * WYSIWYG DEVIATION (approved plan decision #6): `consent_text` is an ACF wysiwyg so the two
 * inline links (Privacy Policy / Terms of Service) are authored naturally in the editor. It is
 * rendered through vst_wysiwyg_inline_html() so a single authored paragraph is UNWRAPPED into
 * the source's <p class="consent"> rather than nesting a second <p> inside it.
 *
 * LINT (justified WARN/ERRORs — lint_partial.py --fragment):
 *   ERROR tag <a> MISSING, ERROR tag <br> MISSING — the fragment's only <a> tags (the two
 *     consent links) and its only <br> live INSIDE the `consent_text` wysiwyg value. They
 *     render from the DB through wp_kses_post. Hardcoding them would make the consent copy and
 *     its link targets non-editable — the exact defect the coverage gate forbids. Accepted
 *     checker limitation, not dropped nodes.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

if ( vst_section_hidden() ) {
	return;
}

$vst_eyebrow      = get_sub_field( 'eyebrow' );
$vst_heading      = get_sub_field( 'heading' );
$vst_body         = get_sub_field( 'body' );
$vst_first_name   = get_sub_field( 'first_name_placeholder' );
$vst_email        = get_sub_field( 'email_placeholder' );
$vst_submit_label = get_sub_field( 'submit_label' );
$vst_consent      = get_sub_field( 'consent_text' );
$vst_form_embed   = get_sub_field( 'form_embed' );
$vst_sec          = vst_section_setup(
	array(
		'class'  => 'sec',
		'layout' => 'calendar_form',
	)
);

echo $vst_sec['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated + value-whitelisted in vst_section_setup().
?>
<section<?php echo $vst_sec['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in vst_section_setup(). ?>>
  <div class="wrap">
    <div class="cal rv">
      <span class="marker" style="justify-content:center"><i></i><b>//</b> <?php echo esc_html( $vst_eyebrow ); ?></span>
      <h2 class="h-sec" style="margin-top:1.1rem"><?php echo vst_accent_html( $vst_heading ); ?></h2>
      <p class="lede" style="margin:1.1rem auto 2.2rem"><?php echo vst_multiline_html( $vst_body ); ?></p>
      <?php if ( '' !== trim( (string) $vst_form_embed ) ) : ?>
      <?php echo do_shortcode( wp_kses_post( $vst_form_embed ) ); ?>
      <?php else : ?>
      <?php /* FORM ENGINE PLACEHOLDER — project-config form_engine is "none", so no plugin form is embedded yet. The designed markup below renders from the ACF copy fields and submits client-side only (see #calForm in the inline script). When a forms plugin is chosen, paste its shortcode into the `form_embed` field; this designed form is then replaced automatically by the branch above. */ ?>
      <form class="cal-form" id="calForm" novalidate>
        <label class="fld">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.4 3.6-5.3 7-5.3s6.2 1.9 7 5.3"/></svg>
          <input type="text" name="first_name" placeholder="<?php echo esc_attr( $vst_first_name ); ?>" autocomplete="given-name">
        </label>
        <label class="fld">
          <svg viewBox="0 0 24 24"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7 7.5 6 7.5-6"/></svg>
          <input type="email" name="email" placeholder="<?php echo esc_attr( $vst_email ); ?>" required autocomplete="email">
        </label>
        <button class="btn" type="submit"><?php echo esc_html( $vst_submit_label ); ?>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </form>
      <?php endif; ?>
      <p class="consent"><?php echo vst_wysiwyg_inline_html( $vst_consent ); ?></p>
    </div>
  </div>
</section>