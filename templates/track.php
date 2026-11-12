<?php
/**
 * Dettaglio di una traccia (oc:8969): template minimo del plugin, da sostituire copiandolo in `wp-package/track.php`
 * del tema. `$detail` è wmwp_detail_current(): type, id, lang, path, name, url, urls (lingua → URL) e feature (il JSON
 * dello shard, letto a ogni richiesta). Le chiavi del JSON possono mancare: ogni dato va controllato prima di usarlo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$detail      = wmwp_detail_current();
$properties  = (array) ( $detail['feature']['properties'] ?? [] );
$description = wmwp_detail_text( $properties['description'] ?? null, (string) $detail['lang'] );

wmwp_template_header();
?>
<main id="main" class="wmwp-detail-page">
	<article class="wmwp-detail-page__inner">
		<h1 class="wmwp-detail-page__title"><?php echo esc_html( $detail['name'] ); ?></h1>
		<?php echo do_shortcode( '[wmwp_map tracks="' . (int) $detail['id'] . '"]' ); ?>
		<?php if ( '' !== $description ) : ?>
			<div class="wmwp-detail-page__description"><?php echo wp_kses_post( $description ); ?></div>
		<?php endif; ?>
	</article>
</main>
<?php
wmwp_template_footer();
