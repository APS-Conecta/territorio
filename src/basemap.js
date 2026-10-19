/**
 * The basemap: OpenStreetMap's standard tile service, loaded directly by each
 * user's browser and used as its Tile Usage Policy asks (ADR-0021).
 *
 * Out of TerritorioMap.vue (L1-32): which Leaflet layer to build and how to
 * notice it failed — no drawing, no pins, no draft.
 */
import L from 'leaflet'
import { retryTile, tileLayerOptions, tileLoaded } from './tiles.js'

/**
 * The tile service, and the only place this app names it.
 *
 * A constant and not a setting (ADR-0021): a setting that accepts any URL is a
 * second basemap path and a server-side request surface, and the policy needs
 * none. The price is that an OpenStreetMap block is fixed by a release that
 * changes this line. `PageController::BASEMAP_HOST` is the same host for the
 * Content-Security-Policy; a test compares the two.
 *
 * Exact, as the policy asks: no query string, no cache-busting parameter.
 */
export const OSM_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'

/**
 * OpenStreetMap's own credit, as markup. Leaflet writes attribution with
 * innerHTML, so this is the app's markup and never a stored string.
 */
export const OSM_ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright"'
	+ ' target="_blank" rel="noreferrer noopener">OpenStreetMap</a> contributors'

/**
 * Draw the basemap.
 *
 * @param {object} map the Leaflet map
 * @param {{attribution: string, onLoaded: Function, onFailed: Function}} hooks
 *        `onLoaded`/`onFailed` announce the LATEST verdict on the basemap's
 *        reachability — a verdict, not an event to count. The parent binds its
 *        banner to the pair. Failure is announced only before the first loaded
 *        tile: a healthy server still 404s tiles at the edge of coverage and
 *        still drops the odd one to a rate-limit blip, which `retryTile` owns,
 *        and the banner must not pin itself over either.
 */
export function addBasemap(map, hooks) {
	let loaded = false

	L.tileLayer(OSM_TILE_URL, tileLayerOptions({ attribution: hooks.attribution }))
		// Tiles failing is not an error the app should die on: the clinic may
		// be offline, or the tile host firewalled. Pan, zoom and everything
		// drawn on top keep working, so we note it and carry on.
		.on('tileerror', (event) => {
			if (!loaded) {
				hooks.onFailed()
			}

			// Leaflet stamps a failed tile as finished and never asks for it
			// again, so without this the grey square stays for the life of the
			// page — no pan, resize or poll brings it back. A bounded few
			// retries heal a 429 or a lost second of network (`tiles.js`).
			retryTile(event.tile)
		})
		.on('tileload', () => {
			loaded = true
			tileLoaded()
			hooks.onLoaded()
		})
		.addTo(map)
}
