<?php
/**
 * Vistara — search results template.
 *
 * No search design exists in the single-page source. Renders inside the same page chrome,
 * using the design's own .sec / .lede / .btn classes so results don't look foreign to the
 * site, even though the source design has no search UI to transplant.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="sec">
	<div class="wrap center">
		<span class="marker rv" style="justify-content:center"><i></i><b>//</b> <?php esc_html_e( 'Search', 'vistara' ); ?></span>
		<h2 class="h-sec rv d1" style="margin-top:1.2rem">
			<?php
			printf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for: %s', 'vistara' ),
				'<span>' . esc_html( get_search_query() ) . '</span>'
			);
			?>
		</h2>

		<?php if ( have_posts() ) : ?>
		<div class="rv d2" style="margin-top:1.6rem;text-align:left">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<p class="lede">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</p>
				<?php
			endwhile;
			?>
		</div>
		<?php the_posts_pagination(); ?>
		<?php else : ?>
		<p class="lede rv d2" style="margin:1.1rem auto 0"><?php esc_html_e( 'No results found.', 'vistara' ); ?></p>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
