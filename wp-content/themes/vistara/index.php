<?php
/**
 * Vistara — fallback template.
 *
 * This theme is a single flexible-content page-builder theme (page-flexible.php owns every
 * real page). index.php is the required WordPress fallback for any request that doesn't
 * match a more specific template (e.g. a page not assigned the Flexible Page template) — it
 * renders plain post/page content so nothing 500s, but is not part of the designed site.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="wrap" style="padding:4rem 0">
	<?php
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class(); ?> id="post-<?php the_ID(); ?>">
				<h1><?php the_title(); ?></h1>
				<div><?php the_content(); ?></div>
			</article>
			<?php
		endwhile;
	else :
		esc_html_e( 'Nothing found.', 'vistara' );
	endif;
	?>
</div>
<?php
get_footer();
