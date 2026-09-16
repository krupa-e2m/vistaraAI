<?php
/**
 * Vistara — header.
 *
 * Source : source/html/_extracted/index.html lines 2-10 (<head>) and
 *          spec/fragments/shared/header.html (the <header class="nav" id="nav"> chrome).
 * Transplanted verbatim; only the logo image / tagline / CTA (Theme Settings options fields)
 * and the page <title>/meta description (WP's own document_title / SEO area) are dynamic.
 *
 * Global chrome owned here (page-flexible.php deliberately omits it — see its own header
 * comment): the five ambient decoration divs (.field / .rails / .spot / .grain / #progress)
 * that sit in <body> before <header> in source. #nav / #progress are live JS hooks (scroll
 * listener toggles `.on` on #nav, sets #progress width to scroll percentage) — their ids
 * must stay exactly as in source.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

$vst_site_logo_id = function_exists( 'get_field' ) ? get_field( 'site_logo', 'option' ) : 0;
$vst_logo_src      = vst_image_url( $vst_site_logo_id );
$vst_logo_alt      = vst_image_alt( $vst_site_logo_id, __( 'Vistara', 'vistara' ) );
$vst_tagline       = function_exists( 'get_field' ) ? get_field( 'brand_tagline', 'option' ) : '';
$vst_header_cta    = function_exists( 'get_field' ) ? get_field( 'header_cta', 'option' ) : '';
$vst_cta_url       = vst_link_url( $vst_header_cta );
$vst_cta_label     = vst_link_label( $vst_header_cta );
$vst_cta_target    = vst_link_target( $vst_header_cta );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="field" aria-hidden="true"></div>
<div class="rails" aria-hidden="true"></div>
<div class="spot" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>
<div class="progress" id="progress" aria-hidden="true"></div>

<header class="nav" id="nav">
  <div class="wrap nav-in">
    <a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Vistara — The AI Conference by E2M', 'vistara' ); ?>">
      <?php if ( $vst_logo_src ) : ?>
      <img class="logo-img" src="<?php echo esc_url( $vst_logo_src ); ?>" alt="<?php echo esc_attr( $vst_logo_alt ); ?>">
      <?php endif; ?>
      <span class="logo-tag"><?php echo esc_html( $vst_tagline ); ?></span>
    </a>
    <?php if ( $vst_cta_url ) : ?>
    <a class="btn btn-sm" data-waitlist href="<?php echo esc_url( $vst_cta_url ); ?>" target="<?php echo esc_attr( $vst_cta_target ); ?>" rel="noopener"><?php echo esc_html( $vst_cta_label ); ?></a>
    <?php endif; ?>
  </div>
</header>
