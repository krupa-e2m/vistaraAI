<?php
/**
 * Template Name: Flexible Page
 *
 * The single page-builder template for the Vistara theme. It renders nothing itself: every
 * band of the page comes from one row of the `page_sections` flexible-content field, and each
 * layout maps 1:1 to a partial in template-parts/sections/{layout}.php.
 *
 * Field group : "Page Sections" (group_5e94723303ab0), located on
 *               page_template == page-flexible.php  (never post_type == page).
 * FC field    : `page_sections` — the project contract name from
 *               project-config.json theme.flexible_content_field. NOTE: the shared
 *               html-acf-coverage checker hardcodes the generic name "sections", so it reports
 *               one `fc_root_missing` finding against this project; that is a known false
 *               positive and the field must NOT be renamed to satisfy it.
 *
 * Global chrome is NOT rendered here — header.php / footer.php own it:
 *   - the five ambient decoration divs (.field, .rails, .spot, .grain, #progress) that sit in
 *     <body> before the header,
 *   - the single shared lightbox shell (#modal / #modalSlot / [data-close] / [data-gnav]) used
 *     by both the video and gallery hooks — it must exist exactly ONCE per page,
 *   - the decorative .edge gradient bar at the very bottom.
 * The source page has no <main> element and none is added here, so the section partials remain
 * direct siblings of the header/footer exactly as in the source DOM.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

get_header();

$vst_page_id = get_queried_object_id();

if ( ! function_exists( 'have_rows' ) ) {

	/*
	 * ACF is not active. Never render a blank page: fall back to the editor content and let
	 * the admin notice registered in functions.php explain the missing dependency.
	 */
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;

} elseif ( have_rows( 'page_sections', $vst_page_id ) ) {

	while ( have_rows( 'page_sections', $vst_page_id ) ) :
		the_row();

		$vst_layout = get_row_layout();

		/*
		 * Files are template-parts/sections/{layout}.php, so the slug is concatenated rather
		 * than passed as get_template_part()'s $name argument (which would look for
		 * template-parts/sections-{layout}.php).
		 */
		get_template_part( 'template-parts/sections/' . $vst_layout );

	endwhile;

}

get_footer();
