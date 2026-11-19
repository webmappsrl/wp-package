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
// Avviso e percorribilità (oc:9000): la lettura è del plugin, qui una resa semplice che il tema sostituisce
$notice      = wmwp_track_notice( $properties, (string) $detail['lang'] );
$walkability = wmwp_walkability( $properties, (string) $detail['lang'] );
$date        = function ( ?string $iso ): string {
	$time = null === $iso ? false : strtotime( $iso );
	return false === $time ? '' : sprintf( '<time datetime="%s">%s</time>', esc_attr( gmdate( 'c', $time ) ), esc_html( wp_date( get_option( 'date_format' ), $time ) ) );
};
$lang_attr   = function ( ?string $text_lang ) use ( $detail ): string {
	return null !== $text_lang && strtolower( substr( (string) $detail['lang'], 0, 2 ) ) !== $text_lang ? ' lang="' . esc_attr( $text_lang ) . '"' : '';
};

wmwp_template_header();
?>
<main id="main" class="wmwp-detail-page">
	<article class="wmwp-detail-page__inner">
		<?php if ( null !== $notice ) : ?>
			<div class="wmwp-detail-page__notice" role="note">
				<strong><?php esc_html_e( 'Notice', 'wp-package' ); ?></strong>
				<span<?php echo $lang_attr( $notice['lang'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- attributo già escapato ?>><?php echo esc_html( $notice['message'] ); ?></span>
				<?php echo $date( $notice['updated_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- markup già escapato ?>
			</div>
		<?php endif; ?>
		<h1 class="wmwp-detail-page__title"><?php echo esc_html( $detail['name'] ); ?></h1>
		<?php if ( ! $walkability['absent'] ) : ?>
			<p class="wmwp-detail-page__walkability">
				<?php
				/* translators: %s: walkability status, e.g. Walkable */
				printf( esc_html__( 'Walkability: %s', 'wp-package' ), '<span' . $lang_attr( $walkability['name_lang'] ) . '>' . esc_html( $walkability['name'] ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- markup già escapato
				?>
				<?php if ( null !== $walkability['updated_at'] ) : ?>
					<?php
					/* translators: %s: date of the last update */
					printf( esc_html__( '(updated on %s)', 'wp-package' ), $date( $walkability['updated_at'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- markup già escapato
					?>
				<?php endif; ?>
				<?php if ( '' !== $walkability['note'] ) : ?>
					<span<?php echo $lang_attr( $walkability['note_lang'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- attributo già escapato ?>><?php echo esc_html( $walkability['note'] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php echo do_shortcode( '[wmwp_map tracks="' . (int) $detail['id'] . '"]' ); ?>
		<?php if ( '' !== $description ) : ?>
			<div class="wmwp-detail-page__description"><?php echo wp_kses_post( $description ); ?></div>
		<?php endif; ?>
	</article>
</main>
<?php
wmwp_template_footer();
