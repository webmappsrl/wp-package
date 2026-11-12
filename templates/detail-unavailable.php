<?php
/**
 * Dettaglio non disponibile (oc:8969): lo shard non ha risposto, la pagina esce con stato 503 e `Retry-After`.
 * Sostituibile copiandolo in `wp-package/detail-unavailable.php` del tema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wmwp_template_header();
?>
<main id="main" class="wmwp-detail-page wmwp-detail-page--unavailable">
	<div class="wmwp-detail-page__inner">
		<h1 class="wmwp-detail-page__title"><?php echo esc_html__( 'Page temporarily unavailable', 'wp-package' ); ?></h1>
		<p><?php echo esc_html__( 'The data of this page cannot be loaded right now. Please try again in a few minutes.', 'wp-package' ); ?></p>
	</div>
</main>
<?php
wmwp_template_footer();
