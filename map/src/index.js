/*
 * Mappa di wp-package (oc:8952): il componente FeatureCollectionMap di wm-package (Vue 3 e OpenLayers, il campo Nova
 * delle mappe di tracce e POI) compilato in un file solo, `assets/dist/wp-package-map.js`, che espone
 * `window.wmWpMap`. Il componente si usa com'è, senza modifiche: la cartella arriva da GitHub al commit scritto in
 * package.json (scripts/scarica-componente.sh). La ricerca, lo shortcode [wp_package_map] e ogni altra pagina
 * (dettaglio di track, POI, layer) passano da qui.
 *
 *   window.wmWpMap.mount(element, options) → { update(geojson, { keepView, keepPopup }), destroy() }
 *   update inquadra i dati nuovi; con keepView: true la vista resta dov'è (per disegnare i dati man mano che arrivano e
 *   inquadrare una volta sola alla fine; prop preserveViewOnReload del componente); con keepPopup: true il riquadro
 *   aperto resta (per cambiare i colori dopo una scelta fatta nel riquadro)
 *   options: geojson      FeatureCollection da disegnare (oppure url)
 *            url          indirizzo di un GeoJSON da scaricare, se manca geojson
 *            height       altezza in pixel (420, come la mappa dei risultati della tavola 2 del wireframe)
 *            padding      margine dell'inquadratura sui dati, in pixel (40)
 *            slopeChart   profilo altimetrico sotto la mappa al clic su una linea (false)
 *            onFeatureClick(properties)  clic su una feature; il puntatore a mano compare sulle feature con `url`
 *            popup(properties) → HTMLElement|null  riquadro sul punto cliccato di una feature (con `id`), con il
 *                         pulsante per chiuderlo; Esc, un clic fuori dalle feature o un aggiornamento lo chiudono
 *            onReady({ map, features })  mappa disegnata (map è la Map di OpenLayers)
 *            fullScreen   pulsante «schermo intero» sotto i +/− (false): controllo FullScreen di OpenLayers, aggiunto
 *                         qui perché il componente non lo ha; dove il browser non permette lo schermo intero di un
 *                         elemento della pagina OpenLayers lo nasconde (classe ol-unsupported)
 *            tiles        { url, maxZoom, attributions, viewMaxZoom }: carta di base al posto di OpenStreetMap, che il
 *                         componente ha fissa (includes/map.php la legge dal config.json dell'app, come la webapp);
 *                         maxZoom è l'ultimo zoom delle tile (oltre si ingrandiscono), viewMaxZoom quello della vista
 *            labels       { zoomIn, zoomOut, fullScreen, exitFullScreen, closePopup }: nomi dei pulsanti nella lingua
 *                         della pagina
 *                         (OpenLayers li ha in inglese e il componente non li fa cambiare)
 * Lo stile delle feature viene dalle loro properties (strokeColor, strokeWidth, lineLabel, tooltip, pointFillColor…):
 * vedi il README del componente in wm-package, src/Nova/Fields/FeatureCollectionMap/README.md.
 */
import { createApp, h, markRaw, ref, shallowReactive } from 'vue';
import FullScreen from 'ol/control/FullScreen';
import Overlay from 'ol/Overlay';
import TileLayer from 'ol/layer/Tile';
import XYZ from 'ol/source/XYZ';
import FeatureCollectionMap from '../vendor/wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';
// Stili di OpenLayers (controlli, attribuzione): in Nova li carica resources/sass/field.scss del campo, che qui non c'è
import 'ol/ol.css';
import './map.css';

// Un GeoJSON di centinaia di tracce non deve diventare reattivo: Vue avvolgerebbe ogni coordinata
function raw(geojson) {
  return geojson && typeof geojson === 'object' ? markRaw(geojson) : null;
}

// Icone del pulsante dello schermo intero: quella della tavola del wireframe (`.map-full`, frecce agli angoli) e
// la stessa con gli angoli verso l'interno per uscire
const ICON_ENTER = 'M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5M4 4l6 6M20 4l-6 6M20 20l-6-6M4 20l6-6';
const ICON_EXIT = 'M9 4v5H4M15 4v5h5M20 15h-5v5M4 15h5v5';

function icon(path) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const line = document.createElementNS(ns, 'path');
  line.setAttribute('d', path);
  line.setAttribute('fill', 'none');
  line.setAttribute('stroke', 'currentColor');
  line.setAttribute('stroke-width', '2');
  line.setAttribute('stroke-linecap', 'round');
  line.setAttribute('stroke-linejoin', 'round');
  svg.appendChild(line);
  return svg;
}

// Riquadro sul punto cliccato: un Overlay di OpenLayers (resta attaccato al punto spostando la mappa, e si vede anche a
// schermo intero). singleclick e non click: un doppio clic resta lo zoom
function setupPopup(element, olMap, build, label) {
  const box = document.createElement('div');
  box.className = 'wmwp-map-popup';
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'wmwp-map-popup__close';
  close.setAttribute('aria-label', label || 'Close');
  close.setAttribute('title', label || 'Close');
  close.textContent = '×';
  const content = document.createElement('div');
  content.className = 'wmwp-map-popup__content';
  box.append(close, content);
  const overlay = new Overlay({ element: box, positioning: 'bottom-center', offset: [0, -14], autoPan: { animation: { duration: 250 }, margin: 24 } });
  olMap.addOverlay(overlay);
  const hide = () => {
    overlay.setPosition(undefined);
    content.textContent = '';
  };
  close.addEventListener('click', hide);
  element.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hide();
    }
  });
  olMap.on('singleclick', (event) => {
    const feature = olMap.forEachFeatureAtPixel(event.pixel, (candidate) => (candidate.get('id') != null ? candidate : undefined), { hitTolerance: 6 });
    const node = feature ? build(feature.getProperties()) : null;
    if (!node) {
      hide();
      return;
    }
    content.textContent = '';
    content.appendChild(node);
    overlay.setPosition(event.coordinate);
  });
  return hide;
}

function name(button, label) {
  if (button && label) {
    button.setAttribute('title', label);
    button.setAttribute('aria-label', label);
  }
}

export function mount(element, options = {}) {
  const data = shallowReactive({ geojson: raw(options.geojson), keepView: false });
  const onClick = typeof options.onFeatureClick === 'function' ? options.onFeatureClick : null;
  const popup = typeof options.popup === 'function' ? options.popup : null;
  let hidePopup = () => {};
  let pointer = false;
  // Il componente: dopo il mount la sua `map` (la Map di OpenLayers) serve per aggiungere i controlli
  const component = ref(null);

  const app = createApp({
    render: () => h(FeatureCollectionMap, {
      ref: component,
      inlineGeojson: data.geojson,
      preserveViewOnReload: data.keepView,
      geojsonUrl: options.url || '',
      height: options.height || 420,
      padding: options.padding ?? 40,
      enableSlopeChart: !!options.slopeChart,
      // Senza `link` nelle properties il componente non apre niente da sé e manda action 'none': il clic lo decide
      // chi usa la mappa (la ricerca apre il percorso nella stessa scheda)
      onFeatureClick: (event) => {
        if (onClick && event.action === 'none') {
          onClick(event.properties);
        }
      },
      onMapReady: (event) => {
        // Il componente mette la mano solo sulle feature con `link`: qui anche su quelle con `url`. Il listener si
        // aggiunge una volta sola e dopo quello del componente, così vince
        if (!pointer && (onClick || popup)) {
          pointer = true;
          event.map.on('pointermove', (move) => {
            const clickable = (feature) => feature.get('url') || (popup && feature.get('id') != null);
            const target = move.map.forEachFeatureAtPixel(move.pixel, (feature) => (clickable(feature) ? feature : undefined), { hitTolerance: 6 });
            if (target) {
              move.map.getTargetElement().style.cursor = 'pointer';
            }
          });
        }
        if (typeof options.onReady === 'function') {
          options.onReady({ map: event.map, features: event.features });
        }
      }
    })
  });
  element.classList.add('wmwp-map');
  app.mount(element);
  // La mappa nasce nel mount del componente: i pulsanti dello zoom ci sono già
  const labels = options.labels || {};
  name(element.querySelector('.ol-zoom-in'), labels.zoomIn);
  name(element.querySelector('.ol-zoom-out'), labels.zoomOut);
  const olMap = component.value && component.value.map;
  // Carta di base: il componente crea un solo layer di tile (OpenStreetMap); qui gli si cambia la sorgente
  const tiles = options.tiles;
  if (tiles && tiles.url && olMap) {
    const base = olMap.getLayers().getArray().find((layer) => layer instanceof TileLayer);
    if (base) {
      // attributionsCollapsible false: l'attribuzione si vede sempre, come nella webapp e come quella di OpenStreetMap
      // del componente (senza, OpenLayers la chiude dietro un pulsante «i»)
      base.setSource(new XYZ({
        url: tiles.url,
        maxZoom: tiles.maxZoom || 19,
        attributions: tiles.attributions || undefined,
        attributionsCollapsible: false
      }));
    }
    if (tiles.viewMaxZoom) {
      olMap.getView().setMaxZoom(tiles.viewMaxZoom);
    }
  }
  if (popup && olMap) {
    hidePopup = setupPopup(element, olMap, popup, labels.closePopup);
  }
  if (options.fullScreen && olMap) {
    const control = new FullScreen({
      label: icon(ICON_ENTER),
      labelActive: icon(ICON_EXIT),
      tipLabel: labels.fullScreen || 'Full screen'
    });
    olMap.addControl(control);
    const button = control.element.querySelector('button');
    name(button, labels.fullScreen);
    control.on('enterfullscreen', () => name(button, labels.exitFullScreen || labels.fullScreen));
    control.on('leavefullscreen', () => name(button, labels.fullScreen));
  }

  return {
    update(geojson, updateOptions = {}) {
      if (!updateOptions.keepPopup) {
        hidePopup();
      }
      data.keepView = !!updateOptions.keepView;
      data.geojson = raw(geojson);
    },
    destroy() {
      app.unmount();
    }
  };
}
