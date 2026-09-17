<?php
/**
 * Page: About Vistara.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

get_header();

$hero    = function_exists( 'get_field' ) ? (array) get_field( 'hero_section' ) : array();
$mission = function_exists( 'get_field' ) ? (array) get_field( 'mission_origin_section' ) : array();
$cta     = function_exists( 'get_field' ) ? (array) get_field( 'cta_section' ) : array();

$hero_cta       = isset( $hero['hero_cta'] ) ? $hero['hero_cta'] : array();
$cta_button     = isset( $cta['cta_button'] ) ? $cta['cta_button'] : array();
$secondary_btn  = isset( $cta['secondary_button'] ) ? $cta['secondary_button'] : array();
?>
<main class="vst-page vst-about">
  <section class="vst-page-hero">
    <div class="wrap vst-page-hero-in">
      <div class="vst-page-copy">
        <span class="hud-chip rv"><span class="dot"></span><?php echo esc_html( $hero['badge_text'] ?? '' ); ?></span>
        <h1 class="h-hero rv d1"><?php echo vst_multiline_html( $hero['hero_title'] ?? get_the_title() ); ?></h1>
        <p class="hero-sub rv d2"><span class="pr">&gt;_</span> <?php echo esc_html( $hero['hero_subtitle'] ?? '' ); ?><span class="caret" aria-hidden="true"></span></p>
        <?php if ( vst_link_url( $hero_cta ) ) : ?>
        <div class="hero-cta rv d3">
          <a class="btn" href="<?php echo esc_url( vst_link_url( $hero_cta ) ); ?>" target="<?php echo esc_attr( vst_link_target( $hero_cta ) ); ?>" rel="noopener">
            <?php echo esc_html( vst_link_label( $hero_cta, __( 'Learn more', 'vistara' ) ) ); ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
        </div>
        <?php endif; ?>
      </div>
      <div class="vst-page-orbit" aria-hidden="true"><span></span><i></i><b></b></div>
    </div>
  </section>

  <section class="vst-page-section vst-mission">
    <div class="wrap vst-mission-grid">
      <div class="vst-section-intro">
        <span class="marker rv"><i></i><b>//</b> <?php echo esc_html( $mission['section_badge'] ?? '' ); ?></span>
        <h2 class="h-sec rv d1"><?php echo esc_html( $mission['section_title'] ?? '' ); ?></h2>
        <?php if ( ! empty( $mission['terminal_quote'] ) ) : ?>
        <p class="vst-terminal rv d2"><span>&gt;_</span> <?php echo esc_html( $mission['terminal_quote'] ); ?></p>
        <?php endif; ?>
      </div>
      <div class="vst-mission-content rv d2">
        <?php echo wp_kses_post( $mission['mission_content'] ?? '' ); ?>
      </div>
    </div>
    <?php if ( ! empty( $mission['origin_highlights'] ) && is_array( $mission['origin_highlights'] ) ) : ?>
    <div class="wrap vst-highlight-grid">
      <?php foreach ( $mission['origin_highlights'] as $index => $highlight ) : ?>
      <article class="vst-highlight rv d<?php echo esc_attr( min( 4, (int) $index + 1 ) ); ?>">
        <span class="vst-highlight-number"><?php echo esc_html( $highlight['highlight_number'] ?? sprintf( '%02d', $index + 1 ) ); ?></span>
        <h3><?php echo esc_html( $highlight['highlight_title'] ?? '' ); ?></h3>
        <p><?php echo esc_html( $highlight['highlight_description'] ?? '' ); ?></p>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="vst-page-section vst-page-cta">
    <div class="wrap vst-cta-panel rv">
      <span class="marker"><i></i><b>//</b> <?php echo esc_html( $cta['cta_badge'] ?? '' ); ?></span>
      <h2 class="h-sec"><?php echo esc_html( $cta['cta_headline'] ?? '' ); ?></h2>
      <p class="lede"><?php echo vst_multiline_html( $cta['cta_subheadline'] ?? '' ); ?></p>
      <div class="vst-cta-actions">
        <?php foreach ( array( $cta_button, $secondary_btn ) as $index => $link ) : ?>
          <?php if ( vst_link_url( $link ) ) : ?>
          <a class="btn<?php echo 1 === $index ? ' btn-ghost' : ''; ?>" href="<?php echo esc_url( vst_link_url( $link ) ); ?>" target="<?php echo esc_attr( vst_link_target( $link ) ); ?>" rel="noopener"><?php echo esc_html( vst_link_label( $link ) ); ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>
<?php get_footer(); ?>
