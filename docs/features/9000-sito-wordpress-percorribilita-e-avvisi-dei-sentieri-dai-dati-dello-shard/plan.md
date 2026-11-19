> Ticket: oc:9000

# Sito WordPress: percorribilità e avvisi dei sentieri, dai dati dello shard — piano del plugin

Il piano è uno solo, con il repo indicato in ogni task:
`wp-forestas/docs/features/9000-sito-wordpress-percorribilita-e-avvisi-dei-sentieri-dai-dati-dello-shard/plan.md`.
Qui l'elenco dei task che toccano questo repo, nell'ordine in cui si eseguono.

- [x] **Task 2** — letture del dettaglio dei dati di `wm-package` (`includes/detail-data.php`, `wmwp_text()` e le
      sei `wmwp_detail_*()`), test `tests/php/detail-data.php`.
- [x] **Task 3** — famiglia del tipo delle tracce indicata dal tema (`wmwp_track_kind_taxonomy()`, `kind_field`),
      con il confronto prima/dopo di HTML e indirizzi dei dettagli.
- [x] **Task 4** — registro degli stati (`includes/status.php`: `wmwp_status_families()`, `wmwp_status_level()`,
      `wmwp_status_name()`, `wmwp_walkability_taxonomy()`), test `tests/php/status.php`.
- [x] **Task 5** — percorribilità e avviso del dettaglio (`includes/detail-status.php`: `wmwp_walkability()`,
      `wmwp_track_notice()`; template di base), test `tests/php/detail-status.php`.
- [x] **Task 8** — valore per i risultati senza campo (`missing` in `search-logic.js`), test JS.
- [x] **Task 10** — sorgente dei POI (`wmwp_poi_search_copy`, `GET /wp-json/wmwp/v1/pois`, `source-pois.js`), test
      PHP e JS.
- [x] **Task 12** — Version, `CHANGELOG.md`, traduzioni, disattivazione e `uninstall.php`.
- [ ] **Dopo il merge del plugin** — `WP_PACKAGE_REF` in `wp-forestas` (task 12 del piano unico, passo 4).
