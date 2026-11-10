<?php
/**
 * Scheletro della pagina di ricerca (oc:8952). Lo riempie assets/js/search.js; i risultati non si stampano
 * qui. Il tema lo sostituisce copiandolo in `wp-package/search.php`: gli attributi `data-wmwp` sono
 * quelli che il JS cerca.
 *
 * @var array $wmwp [ 'sources' => source registrate, 'params' => nomi dei parametri dell'indirizzo ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wmwp-search" data-wmwp="search">
	<div class="wmwp-search__tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Results by type', 'wp-package' ); ?>" data-wmwp="tabs"></div>
	<div class="wmwp-search__panel" role="tabpanel" id="wmwp-panel" data-wmwp="panel">
		<div class="wmwp-search__cols">
			<div class="wmwp-search__filters" id="wmwp-filters" data-wmwp="filters" hidden>
				<div class="wmwp-search__filters-head">
					<h2 class="wmwp-search__filters-title" id="wmwp-filters-title"><?php echo esc_html__( 'Filters', 'wp-package' ); ?></h2>
					<button type="button" class="wmwp-search__filters-close" data-wmwp="filters-close"><?php echo esc_html__( 'Show results', 'wp-package' ); ?></button>
				</div>
				<div class="wmwp-search__facets" data-wmwp="facets"></div>
			</div>
			<div class="wmwp-search__main">
				<form class="wmwp-search__form" role="search" method="get" action="" data-wmwp="form">
					<label class="wmwp-search__label" for="wmwp-q" data-wmwp="search-label"><?php echo esc_html__( 'Search the site', 'wp-package' ); ?></label>
					<div class="wmwp-search__field">
						<input class="wmwp-search__input" type="search" id="wmwp-q" name="<?php echo esc_attr( $wmwp['params']['q'] ); ?>" data-wmwp="input" autocomplete="off">
						<button class="wmwp-search__submit" type="submit"><?php echo esc_html__( 'Search', 'wp-package' ); ?></button>
					</div>
				</form>
				<button type="button" class="wmwp-search__filters-open" data-wmwp="filters-open" aria-controls="wmwp-filters" aria-expanded="false" hidden><?php echo esc_html__( 'Filters', 'wp-package' ); ?></button>
				<div class="wmwp-search__active" data-wmwp="active" hidden></div>
				<div class="wmwp-search__toolbar">
					<p class="wmwp-search__status" role="status" data-wmwp="status"></p>
					<?php
					// Selettore Elenco | Mappa (tavola 2 del wireframe di oc:8952, `.seg`): compare nei tab la cui source ha la
					// mappa. La vista sta nell'indirizzo (parametri `view` e `map`).
					?>
					<div class="wmwp-search__view" role="group" aria-label="<?php echo esc_attr__( 'Results view', 'wp-package' ); ?>" data-wmwp="view" hidden>
						<button type="button" data-view="list" aria-pressed="true"><?php echo esc_html__( 'List', 'wp-package' ); ?></button>
						<button type="button" data-view="map" aria-pressed="false"><?php echo esc_html__( 'Map', 'wp-package' ); ?></button>
					</div>
				</div>
				<div class="wmwp-search__results" data-wmwp="results" aria-busy="false"></div>
				<div class="wmwp-search__map" data-wmwp="map-view" hidden>
					<div class="wmwp-search__map-canvas" role="region" aria-label="<?php echo esc_attr__( 'Map of the results', 'wp-package' ); ?>" data-wmwp="map"></div>
					<p class="wmwp-search__map-note" data-wmwp="map-note"></p>
				</div>
				<nav class="wmwp-search__pager" data-wmwp="pager" aria-label="<?php echo esc_attr__( 'Result pages', 'wp-package' ); ?>" hidden></nav>
				<?php
				// Barra della selezione (tavola 2 del wireframe di oc:8952, `.selbar2`), in fondo mentre si scorre: compare
				// con almeno un risultato scelto. Il nome della regione lo scrive il JS dalla source.
				?>
				<div class="wmwp-selection" role="region" aria-label="<?php echo esc_attr__( 'Selection', 'wp-package' ); ?>" data-wmwp="selection" hidden>
					<div class="wmwp-selection__top">
						<p class="wmwp-selection__count" aria-live="polite" data-wmwp="selection-count"></p>
						<button type="button" class="wmwp-selection__clear" data-wmwp="selection-clear"><?php echo esc_html__( 'Deselect all', 'wp-package' ); ?></button>
					</div>
					<div class="wmwp-selection__group" role="group" aria-labelledby="wmwp-selection-tracks">
						<span class="wmwp-selection__group-label" id="wmwp-selection-tracks"><?php echo esc_html__( 'Tracks', 'wp-package' ); ?></span>
						<fieldset class="wmwp-selection__formats">
							<legend class="screen-reader-text"><?php echo esc_html__( 'Track format', 'wp-package' ); ?></legend>
							<label><input type="radio" name="wmwp-format" value="gpx" checked> GPX</label>
							<label><input type="radio" name="wmwp-format" value="kml"> KML</label>
							<label><input type="radio" name="wmwp-format" value="geojson"> GeoJSON</label>
						</fieldset>
						<button type="button" class="wmwp-selection__download" data-wmwp="selection-download"></button>
					</div>
					<p class="wmwp-selection__status" role="status" data-wmwp="selection-status"></p>
				</div>
			</div>
		</div>
	</div>
	<noscript>
		<form class="wmwp-search__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="wmwp-search__label" for="wmwp-s"><?php echo esc_html__( 'Search the site', 'wp-package' ); ?></label>
			<div class="wmwp-search__field">
				<input class="wmwp-search__input" type="search" id="wmwp-s" name="s">
				<?php // nojs=1: un tema che reindirizza ?s= alla pagina di ricerca non lo fa per questo form ?>
				<input type="hidden" name="nojs" value="1">
				<button class="wmwp-search__submit" type="submit"><?php echo esc_html__( 'Search', 'wp-package' ); ?></button>
			</div>
		</form>
	</noscript>
</div>
