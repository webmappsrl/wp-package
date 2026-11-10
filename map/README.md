# Build della mappa

`assets/dist/wp-package-map.js` e `.css` sono il componente `FeatureCollectionMap` di
[wm-package](https://github.com/webmappsrl/wm-package) (`src/Nova/Fields/FeatureCollectionMap/resources/js`, Vue 3
e OpenLayers 9) compilato in un file solo con Vite, con `src/index.js` che lo monta ed espone `window.wmWpMap`
(README del plugin, «Mappa»). Il risultato è versionato: si ricompila solo quando cambia `src/` o il commit di
wm-package.

```bash
cd map
npm install      # Node 18 o successivo
npm run build    # scarica il componente (se serve) e scrive ../assets/dist/
```

## Il componente di wm-package

Il componente si usa com'è, senza copiarlo nel plugin e senza modificarlo. `npm run build` scarica da GitHub solo la
sua cartella, al commit scritto in `package.json` (`config.wmPackageRef`), in `vendor/wm-package/`
(`scripts/scarica-componente.sh`, ignorata da git): un commit uguale non si riscarica. Per portare la mappa a una
versione più recente del componente si cambia `wmPackageRef` con l'hash di un commit di `main` di wm-package, poi
`npm run build`, e si controlla la mappa della ricerca nel browser.

wm-package non è una dipendenza npm perché il suo `package.json` porta con sé `chartjs-node-canvas` e quindi `canvas`,
un modulo nativo che qui non serve (con la dipendenza da GitHub l'installazione era di 113 MB e 9 minuti; così è di 10
secondi). Le dipendenze del componente (`vue`, `ol`, `chart.js`, `html2canvas`) sono in `devDependencies` alle
versioni del suo `package.json` in wm-package.

## Cosa aggiunge la build

- `ol/ol.css`, che in Nova carica `resources/sass/field.scss` del campo;
- `src/map.css`: nasconde il testo «Caricamento mappa...» (solo in italiano) e dà il fondo grigio della mappa;
- le chiamate a `console.log` del componente tolte (`esbuild.pure` in `vite.config.js`); `console.error` resta;
- il controllo `FullScreen` di OpenLayers (`fullScreen` in `mount()`), che il componente non ha: si aggiunge alla
  `map` del componente dopo il mount. Dove il browser non permette lo schermo intero di un elemento della pagina,
  OpenLayers gli mette la classe `ol-unsupported` e `ol.css` lo nasconde;
- la carta di base (`tiles` in `mount()`): il componente crea un solo layer di tile, OpenStreetMap; dopo il mount gli
  si cambia la sorgente con una `XYZ`, e la vista prende il `viewMaxZoom`. Si appoggia alla struttura interna del
  componente (un solo `TileLayer`): a ogni aggiornamento di `wmPackageRef` va riprovato;
- il riquadro al clic (`popup` in `mount()`): un `Overlay` di OpenLayers sul punto cliccato, con il pulsante per
  chiuderlo, sopra i controlli della mappa (su un telefono il «+» copriva «Chiudi»). Il popup del componente non si usa:
  ha le classi di Nova.
