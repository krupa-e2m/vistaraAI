<?php
/**
 * Vistara — 404 template.
 *
 * No 404 design exists in the single-page source (spec/site-spec.json has one page). Renders
 * inside the same page chrome (header/footer/ambient decoration/modal/edge) so a missing URL
 * still looks like the site rather than a bare WP default.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="sec">
	<div class="wrap center">
		<span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php esc_html_e( '404', 'vistara' ); ?></span>
		<h2 class="h-sec rv d1" style="margin-top:1.2rem"><?php esc_html_e( 'Page not found', 'vistara' ); ?></h2>
		<p class="lede rv d2" style="margin:1.1rem auto 0"><?php esc_html_e( 'The page you are looking for does not exist. Head back to the homepage.', 'vistara' ); ?></p>
		<div class="rv d3" style="margin-top:1.6rem">
			<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to Home', 'vistara' ); ?></a>
		</div>
	</div>
</section>
<?php
get_footer();
