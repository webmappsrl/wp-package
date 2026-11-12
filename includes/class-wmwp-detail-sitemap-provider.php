<?php
/**
 * Sitemap dei dettagli (oc:8969): tracce e POI dell'indice, in ogni lingua, nella sitemap del core (`wp-sitemap.xml`).
 * Come tutta la sitemap del core, esiste solo con il sito indicizzabile (`blog_public = 1`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMWP_Detail_Sitemap_Provider extends WP_Sitemaps_Provider {

	const NAME     = 'wmwpdetail';
	const PER_PAGE = 2000;

	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
	}

	/**
	 * Sottotipi: tracce e POI.
	 *
	 * @return array<string, object>
	 */
	public function get_object_subtypes() {
		return [
			'track' => (object) [ 'name' => 'track' ],
			'poi'   => (object) [ 'name' => 'poi' ],
		];
	}

	/**
	 * Tutti gli indirizzi di un sottotipo: un elemento per lingua.
	 *
	 * @return list<string>
	 */
	private function locations( string $subtype ): array {
		$index = wmwp_detail_index();
		$urls  = [];
		foreach ( (array) ( $index['urls'][ $subtype ] ?? [] ) as $id => $by_lang ) {
			foreach ( array_keys( (array) $by_lang ) as $lang ) {
				$url = wmwp_detail_url( $subtype, (int) $id, (string) $lang );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * @param int    $page_num       Pagina, da 1.
	 * @param string $object_subtype `track` o `poi`.
	 * @return list<array{loc: string}>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$page = array_slice( $this->locations( (string) $object_subtype ), ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		return array_map(
			function ( string $url ): array {
				return [ 'loc' => $url ];
			},
			$page
		);
	}

	/** @param string $object_subtype `track` o `poi`. */
	public function get_max_num_pages( $object_subtype = '' ) {
		return (int) max( 1, ceil( count( $this->locations( (string) $object_subtype ) ) / self::PER_PAGE ) );
	}
}
