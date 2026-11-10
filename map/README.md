# Build della mappa

`assets/dist/wp-package-map.js` e `.css` sono il componente `FeatureCollectionMap` di
[wm-package](https://github.com/webmappsrl/wm-package) (`src/Nova/Fields/FeatureCollectionMap/resources/js`, Vue 3
e OpenLayers 9) compilato in un file solo con Vite, con `src/index.js` che lo monta ed espone `window.wmWpMap`
(README del plugin, «Mappa»). Il risultato è versionato: si ricompila solo quando cambia `src/` o il commit di
wm-package.

```bash
git submodule update --init map/wm-package   # una volta, dopo il clone (o git clone --recursive)
cd map
npm install      # Node 18 o successivo
npm run build    # scrive ../assets/dist/
```

## Il componente di wm-package

Il componente si usa com'è, senza copiarlo nel plugin e senza modificarlo: viene dal submodule `map/wm-package`
(scrum del 10/11, 14:15, Giuseppe: «gli dobbiamo inserire il submodule wm-package. È da lì che si va a prendere tutto
il codice. Non gli mettere un URL»). La versione del componente è il commit del submodule (oggi `f05142f7`, `main` di
wm-package); `.gitmodules` segue `main`. Per una versione nuova:

```bash
git submodule update --remote map/wm-package   # o git -C map/wm-package checkout <commit>
cd map && npm run build
```

poi si prova la mappa della ricerca nel browser e si committano insieme il submodule e `assets/dist/`. Se il submodule
manca, `npm run build` si ferma con «Manca il submodule wm-package». WordPress non ne ha bisogno: usa il compilato in
`assets/dist/` (l'archivio di GitHub che scarica l'installazione non contiene i submodule).

wm-package non è una dipendenza npm perché il suo `package.json` porta con sé `chartjs-node-canvas` e quindi `canvas`,
un modulo nativo che qui non serve (con la dipendenza da GitHub l'installazione era di 113 MB e 9 minuti). Le
dipendenze del componente (`vue`, `ol`, `chart.js`, `html2canvas`) sono in `devDependencies` alle versioni del suo
`package.json` in wm-package: aggiornando il submodule vanno ricontrollate.

Il compilato cambia anche solo perché cambia il percorso dei file: Vue e il minificatore calcolano dal percorso alcuni
nomi (`data-v-…`, variabili di `v-bind`, nomi brevi del JS). Due build dello stesso commit da cartelle diverse hanno
la stessa lunghezza ma non gli stessi byte: si confrontano nel browser.

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
