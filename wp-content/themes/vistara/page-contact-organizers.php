<?php
/**
 * Page: Contact Organizers.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

$hero    = function_exists( 'get_field' ) ? (array) get_field( 'hero_section' ) : array();
$contact = function_exists( 'get_field' ) ? (array) get_field( 'contact_info_section' ) : array();
$form    = function_exists( 'get_field' ) ? (array) get_field( 'form_section' ) : array();
$form_notice = '';

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['vst_contact_submit'] ) ) {
  if ( ! isset( $_POST['vst_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vst_contact_nonce'] ) ), 'vst_contact_query' ) ) {
    $form_notice = __( 'Your request could not be verified. Please try again.', 'vistara' );
  } else {
    $name    = sanitize_text_field( wp_unslash( $_POST['vst_contact_name'] ?? '' ) );
    $email   = sanitize_email( wp_unslash( $_POST['vst_contact_email'] ?? '' ) );
    $phone   = sanitize_text_field( wp_unslash( $_POST['vst_contact_phone'] ?? '' ) );
    $query   = sanitize_textarea_field( wp_unslash( $_POST['vst_contact_query'] ?? '' ) );

    if ( '' === $name || ! is_email( $email ) || '' === $query ) {
      $form_notice = __( 'Please complete your name, email, and query before sending.', 'vistara' );
    } else {
      $recipient = sanitize_email( $form['recipient_email'] ?? '' );
      $recipient = is_email( $recipient ) ? $recipient : get_option( 'admin_email' );
      $subject   = sprintf( __( 'Vistara contact query from %s', 'vistara' ), $name );
      $message   = sprintf( "Name: %s\nEmail: %s\nContact: %s\n\nQuery:\n%s", $name, $email, $phone ?: __( 'Not provided', 'vistara' ), $query );

      if ( wp_mail( $recipient, $subject, $message, array( 'Reply-To: ' . $email ) ) ) {
        wp_safe_redirect( add_query_arg( 'contact_sent', '1', get_permalink() ) );
        exit;
      }
      $form_notice = __( 'We could not send your query right now. Please try again later.', 'vistara' );
    }
  }
}

get_header();
?>
<main class="vst-page vst-contact">
  <section class="vst-page-hero vst-contact-hero">
    <div class="wrap vst-page-hero-in">
      <div class="vst-page-copy">
        <span class="hud-chip rv"><span class="dot"></span><?php echo esc_html( $hero['badge_text'] ?? '' ); ?></span>
        <h1 class="h-hero rv d1"><?php echo vst_multiline_html( $hero['hero_title'] ?? get_the_title() ); ?></h1>
        <p class="hero-sub rv d2"><span class="pr">&gt;_</span> <?php echo esc_html( $hero['hero_subtitle'] ?? '' ); ?><span class="caret" aria-hidden="true"></span></p>
      </div>
      <div class="vst-contact-signal rv d2" aria-hidden="true"><span>@</span></div>
    </div>
  </section>

  <section class="vst-page-section vst-contact-info">
    <div class="wrap">
      <div class="vst-section-heading">
        <span class="marker rv"><i></i><b>//</b> <?php echo esc_html( $contact['section_badge'] ?? 'CONTACT' ); ?></span>
        <h2 class="h-sec rv d1"><?php echo esc_html( $contact['section_title'] ?? 'Choose a conversation' ); ?></h2>
      </div>
      <?php if ( ! empty( $contact['cards'] ) && is_array( $contact['cards'] ) ) : ?>
      <div class="vst-contact-grid">
        <?php foreach ( $contact['cards'] as $index => $card ) : ?>
        <article class="vst-contact-card rv d<?php echo esc_attr( min( 4, (int) $index + 1 ) ); ?>">
          <span class="vst-card-index">0<?php echo esc_html( $index + 1 ); ?></span>
          <h3><?php echo esc_html( $card['category_title'] ?? '' ); ?></h3>
          <?php if ( ! empty( $card['description'] ) ) : ?><p><?php echo esc_html( $card['description'] ); ?></p><?php endif; ?>
          <?php if ( ! empty( $card['email'] ) ) : ?><a class="vst-email" href="mailto:<?php echo esc_attr( antispambot( $card['email'] ) ); ?>"><?php echo esc_html( antispambot( $card['email'] ) ); ?><span aria-hidden="true">&rarr;</span></a><?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="vst-page-section vst-contact-form-section">
    <div class="wrap vst-contact-form-grid">
      <div class="vst-section-intro rv">
        <span class="marker"><i></i><b>//</b> <?php echo esc_html( $form['badge_text'] ?? '' ); ?></span>
        <h2 class="h-sec"><?php echo esc_html( $form['title'] ?? '' ); ?></h2>
      </div>
      <div class="vst-form-copy rv d1">
        <?php if ( isset( $_GET['contact_sent'] ) && '1' === $_GET['contact_sent'] ) : ?>
          <p class="vst-form-notice vst-form-success" role="status"><?php esc_html_e( 'Your query has been sent. The Vistara team will be in touch soon.', 'vistara' ); ?></p>
        <?php elseif ( $form_notice ) : ?>
          <p class="vst-form-notice vst-form-error" role="alert"><?php echo esc_html( $form_notice ); ?></p>
        <?php endif; ?>
        <form class="vst-contact-form" method="post" action="<?php echo esc_url( get_permalink() ); ?>">
          <?php wp_nonce_field( 'vst_contact_query', 'vst_contact_nonce' ); ?>
          <div class="vst-form-row">
            <label for="vst-contact-name"><?php esc_html_e( 'Name', 'vistara' ); ?> <span>*</span></label>
            <input id="vst-contact-name" name="vst_contact_name" type="text" autocomplete="name" required>
          </div>
          <div class="vst-form-row">
            <label for="vst-contact-email"><?php esc_html_e( 'Email', 'vistara' ); ?> <span>*</span></label>
            <input id="vst-contact-email" name="vst_contact_email" type="email" autocomplete="email" required>
          </div>
          <div class="vst-form-row">
            <label for="vst-contact-phone"><?php esc_html_e( 'Contact', 'vistara' ); ?></label>
            <input id="vst-contact-phone" name="vst_contact_phone" type="tel" autocomplete="tel">
          </div>
          <div class="vst-form-row">
            <label for="vst-contact-query"><?php esc_html_e( 'Query', 'vistara' ); ?> <span>*</span></label>
            <textarea id="vst-contact-query" name="vst_contact_query" rows="5" required></textarea>
          </div>
          <button class="btn" type="submit" name="vst_contact_submit" value="1">
            <?php echo esc_html( $form['submit_label'] ?? __( 'SEND QUERY', 'vistara' ) ); ?>
            <span aria-hidden="true">&rarr;</span>
          </button>
        </form>
      </div>
    </div>
  </section>
</main>
<?php get_footer(); ?>
