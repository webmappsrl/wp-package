/*
 * Mappa di wp-package (oc:8952): il componente FeatureCollectionMap di wm-package (Vue 3 e OpenLayers, il campo Nova
 * delle mappe di tracce e POI) compilato in un file solo, `assets/dist/wp-package-map.js`, che espone
 * `window.wmWpMap`. Il componente si usa com'è, senza modifiche, dal submodule map/wm-package: la versione è il commit
 * del submodule, e per una nuova si aggiorna il submodule. La ricerca, lo shortcode [wmwp_map] e ogni altra pagina
 * (dettaglio di track, POI, layer) passano da qui.
 *
 *   window.wmWpMap.mount(element, options) → { update(geojson, { keepView, keepPopup }), destroy() }
 *   update inquadra i dati nuovi; con keepView: true la vista resta dov'è (per disegnare i dati man mano che arrivano e
 *   inquadrare una volta sola alla fine; prop preserveViewOnReload del componente); con keepPopup: true il riquadro
 *   aperto resta (per cambiare i colori dopo una scelta fatta nel riquadro)
 *   options: geojson      FeatureCollection da disegnare (oppure url)
 *            url          indirizzo di un GeoJSON da scaricare, se manca geojson (da non confondere con la `url`
 *                         nelle properties di una feature, sotto)
 *            height       altezza in pixel (420, come la mappa dei risultati della tavola 2 del wireframe)
 *            padding      margine dell'inquadratura sui dati, in pixel (40)
 *            slopeChart   profilo altimetrico sotto la mappa al clic su una linea (false)
 *            onFeatureClick(properties)  clic su una feature senza `link` nelle properties (con `link` il componente
 *                         apre l'indirizzo da sé). Il puntatore a mano compare sulle feature con `url` nelle properties
 *                         e, se c'è popup, su quelle con `id`; la mappa non apre mai la `url`: lo decide chi la usa
 *            popup(properties) → HTMLElement|null  riquadro sul punto cliccato di una feature (con `id`), con il
 *                         pulsante per chiuderlo; Esc, un clic fuori dalle feature o un aggiornamento lo chiudono
 *            onReady({ map, features })  mappa disegnata (map è la Map di OpenLayers)
 *            fullScreen   pulsante «schermo intero» sotto i +/− (false): controllo FullScreen di OpenLayers, aggiunto
 *                         qui perché il componente non lo ha; dove il browser non permette lo schermo intero di un
 *                         elemento della pagina OpenLayers lo nasconde (classe ol-unsupported)
 *            tiles        { url, maxZoom, attributions, viewMaxZoom }: carta di base al posto di OpenStreetMap, che il
 *                         componente ha fissa (includes/map.php la legge dal config.json dell'app, come la webapp);
 *                         maxZoom è l'ultimo zoom delle tile (oltre si ingrandiscono), viewMaxZoom quello della vista
 *            labels       { zoomIn, zoomOut, fullScreen, exitFullScreen, closePopup, keyboard }: nomi dei pulsanti
 *                         nella lingua della pagina (OpenLayers li ha in inglese e il componente non li fa cambiare);
 *                         keyboard è il nome della mappa quando prende il focus, con i tasti da usare
 * Accessibilità, aggiunta qui perché il componente non la ha (oc:8952, WCAG 2.1 AA):
 *   - tastiera (2.1.1): la mappa prende il focus con Tab, si sposta con le frecce e si ingrandisce con + e −
 *     (KeyboardPan e KeyboardZoom di OpenLayers; il componente le spegne con `keyboard: false`);
 *   - tooltip al passaggio del mouse (1.4.13): si chiude con Esc senza muovere il puntatore, e resta mentre il
 *     puntatore ci passa sopra.
 * Lo stile delle feature viene dalle loro properties (strokeColor, strokeWidth, lineLabel, tooltip, pointFillColor…):
 * vedi il README del componente in wm-package, src/Nova/Fields/FeatureCollectionMap/README.md.
 */
import { createApp, h, markRaw, ref, shallowReactive } from 'vue';
import FullScreen from 'ol/control/FullScreen';
import KeyboardPan from 'ol/interaction/KeyboardPan';
import KeyboardZoom from 'ol/interaction/KeyboardZoom';
import Overlay from 'ol/Overlay';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import { Fill, Icon, Style, Text } from 'ol/style';
import XYZ from 'ol/source/XYZ';
import FeatureCollectionMap from '../wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';
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
  const onKey = (event) => {
    if (event.key === 'Escape') {
      hide();
    }
  };
  element.addEventListener('keydown', onKey);
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
  return { hide, remove: () => element.removeEventListener('keydown', onKey) };
}

// La mappa si usa da tastiera: prende il focus (tabindex), e frecce e +/− arrivano a OpenLayers, che li ascolta sul
// target della mappa. role application: con uno screen reader in modalità lettura le frecce andrebbero al cursore
// virtuale e non alla mappa
function setupKeyboard(olMap, label) {
  const target = olMap.getTargetElement();
  if (!target) {
    return;
  }
  target.setAttribute('tabindex', '0');
  target.setAttribute('role', 'application');
  // un attributo e non una classe: Vue riscrive `class` del contenitore a ogni aggiornamento
  target.setAttribute('data-wmwp-map-surface', '');
  if (label) {
    target.setAttribute('aria-label', label);
  }
  olMap.addInteraction(new KeyboardPan());
  olMap.addInteraction(new KeyboardZoom());
}

// Tooltip del componente (.map-tooltip, un Overlay a 10px sopra il puntatore). Esc lo nasconde finché il componente
// non lo chiude o non ne cambia il testo (puntatore su un'altra linea); sopra il tooltip il puntatore non arriva alla
// mappa, così il componente non lo chiude, e l'Overlay si avvicina al puntatore (4px, meno della tolleranza di 6px
// del componente) perché ci si possa arrivare senza lasciare la linea
function setupTooltip(element, olMap) {
  const tip = element.querySelector('.map-tooltip');
  if (!tip) {
    return () => {};
  }
  const overlay = olMap.getOverlays().getArray().find((item) => item.getElement() === tip);
  if (overlay) {
    overlay.setOffset([0, -4]);
  }
  tip.setAttribute('data-wmwp-map-tooltip', '');
  tip.addEventListener('pointermove', (event) => event.stopPropagation());
  let dismissed = null;
  const onKey = (event) => {
    if (event.key === 'Escape' && tip.style.display !== 'none' && tip.textContent.trim()) {
      dismissed = tip.textContent;
      tip.style.visibility = 'hidden';
    }
  };
  document.addEventListener('keydown', onKey);
  const observer = new MutationObserver(() => {
    if (dismissed !== null && (tip.style.display === 'none' || tip.textContent !== dismissed)) {
      dismissed = null;
      tip.style.visibility = '';
    }
  });
  observer.observe(tip, { attributes: true, attributeFilter: ['style'], childList: true, characterData: true, subtree: true });
  // Per destroy(): il listener sta su document e resterebbe dopo lo smontaggio
  return () => {
    document.removeEventListener('keydown', onKey);
    observer.disconnect();
  };
}

function name(button, label) {
  if (button && label) {
    button.setAttribute('title', label);
    button.setAttribute('aria-label', label);
  }
}

// Bandiera a scacchi dell'arrivo (oc:8969), grande quanto il cerchio che il componente disegna per l'arrivo (raggio
// 12, bordo bianco di 3: assets/js/track-map.js). Sta nello stesso layer, come stile in più del punto
// (getAdditionalPointStyles del componente): sopra la traccia e sotto i punti di interesse disegnati dopo, che la
// coprono al centro lasciando vedere l'anello a scacchi intorno, come il verde della partenza
const FLAG = new Style({
  image: new Icon({
    src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30"><defs>'
      + '<pattern id="s" width="6" height="6" patternUnits="userSpaceOnUse"><rect width="6" height="6" fill="#fff"/>'
      + '<rect width="3" height="3" fill="#1A1A1A"/><rect x="3" y="3" width="3" height="3" fill="#1A1A1A"/></pattern></defs>'
      + '<circle cx="15" cy="15" r="12" fill="url(#s)" stroke="#fff" stroke-width="3"/></svg>'
    ),
    width: 30,
    height: 30
  })
});

// Numeri sui punti (oc:8969): il componente scrive testi solo lungo le linee; i punti con `number` nelle properties
// (assets/js/track-map.js, detail()) lo portano al centro del cerchio, bianco, in un layer sopra quello del componente
function numberLayer(olMap) {
  const source = new VectorSource();
  const layer = new VectorLayer({
    source,
    zIndex: 1000,
    style: (feature) => new Style({
      text: new Text({
        text: String(feature.get('number')),
        font: 'bold 11px sans-serif',
        fill: new Fill({ color: '#FFFFFF' })
      })
    })
  });
  olMap.addLayer(layer);
  const format = new GeoJSON();
  return (geojson) => {
    source.clear();
    const list = (geojson && geojson.features) || [];
    const numbered = list.filter((feature) => feature && feature.geometry && feature.geometry.type === 'Point'
      && feature.properties && feature.properties.number);
    if (numbered.length) {
      source.addFeatures(format.readFeatures({ type: 'FeatureCollection', features: numbered }, {
        dataProjection: 'EPSG:4326',
        featureProjection: olMap.getView().getProjection()
      }));
    }
  };
}

export function mount(element, options = {}) {
  const data = shallowReactive({ geojson: raw(options.geojson), keepView: false });
  const onClick = typeof options.onFeatureClick === 'function' ? options.onFeatureClick : null;
  const popup = typeof options.popup === 'function' ? options.popup : null;
  let hidePopup = () => {};
  // Listener fuori dal componente (su document e sull'elemento della pagina), da togliere in destroy()
  const cleanups = [];
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
      getAdditionalPointStyles: (feature) => (feature.get('endpoint') === 'end' ? FLAG : null),
      // Senza `link` nelle properties il componente non apre niente da sé e manda action 'none': il clic lo decide
      // chi usa la mappa (la ricerca non passa onFeatureClick e apre il riquadro con popup)
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
    const box = setupPopup(element, olMap, popup, labels.closePopup);
    hidePopup = box.hide;
    cleanups.push(box.remove);
  }
  const numbers = olMap ? numberLayer(olMap) : () => {};
  numbers(options.geojson);
  if (olMap) {
    setupKeyboard(olMap, labels.keyboard);
    cleanups.push(setupTooltip(element, olMap));
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
      numbers(geojson);
    },
    destroy() {
      cleanups.forEach((remove) => remove());
      app.unmount();
    }
  };
}
