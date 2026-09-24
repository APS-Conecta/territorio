/**
 * The basemap, in whichever of its two forms this install serves (ADR-0019).
 *
 * Out of TerritorioMap.vue (L1-32): the fork is a decision about which Leaflet
 * layer to build and how to notice it failed — no drawing, no pins, no draft.
 * Everything here existed verbatim in the component under the same names.
 */
import L from 'leaflet'
import { retryTile } from './tiles.js'
import { leafletLayer } from 'protomaps-leaflet'

/**
 * OpenStreetMap's own credit, as markup, from here and never from configuration.
 * Leaflet writes attribution with innerHTML, so a stored string is escaped
 * before it goes anywhere near the control (see TerritorioMap's attributionHtml).
 */
export const OSM_ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright"'
	+ ' target="_blank" rel="noreferrer noopener">OpenStreetMap</a> contributors'

/** How far the map lets a person zoom, whichever basemap is drawing it. */
const MAX_ZOOM = 19

/**
 * The deepest zoom the Protomaps archive actually holds (ADR-0019).
 *
 * Its own header says `max zoom 15`, and Protomaps document z15 as sufficient
 * for street-level mapping. Past it a vector layer OVERZOOMS — it keeps scaling
 * the z15 data instead of asking for a z16 tile that is not in the file — so the
 * map still zooms to 19 and simply stops fetching. Leaving this unset makes it
 * ask for tiles the archive does not have, at every zoom past 15.
 */
const PMTILES_MAX_DATA_ZOOM = 15

/**
 * The first seven bytes of a PMTiles archive spell its own name.
 *
 * Checked because a 206 is not proof: a proxy or an error page can answer a
 * range request with something that is not an archive, and the failure then
 * looks like an empty map rather than a bad URL.
 */
const PMTILES_MAGIC = 'PMTiles'

/**
 * Draw the basemap, in whichever of its two forms this install serves.
 *
 * ADR-0019: a PMTiles archive is one file the browser reads with Range
 * requests, and a raster URL is a directory of images. They need different
 * Leaflet layers, different failure handling and different CSP directives
 * (`PageController` owns that last one), so the fork is here and once.
 *
 * @param {object} map the Leaflet map
 * @param {{kind: string, url: string}} basemap the stored setting
 * @param {{attribution: string, onLoaded: Function, onFailed: Function}} hooks
 *        `onLoaded`/`onFailed` latch the parent's banner flags
 */
export function addBasemap(map, basemap, hooks) {
	if (basemap.kind === 'pmtiles') {
		addPmtilesBasemap(map, basemap, hooks)
		return
	}

	addRasterBasemap(map, basemap, hooks)
}

/**
 * The Protomaps vector basemap, drawn to canvas by `protomaps-leaflet`.
 *
 * That library is in maintenance mode and its own docs point new projects
 * at MapLibre. It is still the right one here for the reason those docs
 * give for keeping it: this map is Leaflet all the way down — geoman draws
 * on it, markercluster clusters on it, and the pickable pane orders it — and
 * MapLibre would mean rewriting every one of those. The same docs call it
 * suited to non-interactive layers, which is exactly what a basemap under
 * our own overlays is: nothing here is ever clicked.
 */
function addPmtilesBasemap(map, basemap, hooks) {
	leafletLayer({
		url: basemap.url,
		attribution: hooks.attribution,
		// Spanish labels from the archive itself, so place names match the
		// rest of the interface instead of arriving in English.
		lang: 'es',
		// `light`, and the brightness that goes with it is not decoration:
		// see the `--vector` filter at the bottom of TerritorioMap.vue. Bare
		// `light` earth is darker than the raster land every ratio in
		// map-legibility § 2 was measured over, which drops the worst
		// dot-on-tint pair to 2.65:1 — below WCAG 1.4.11's floor and worse
		// than the 3.04:1 it replaced. `white` would clear it at 3.44:1 but
		// renders a basemap so pale the street grid is hard to read, and
		// reading the street grid is how a Sector gets drawn at all.
		flavor: 'light',
		maxZoom: MAX_ZOOM,
		maxDataZoom: PMTILES_MAX_DATA_ZOOM,
	})
		.on('tileload', () => {
			hooks.onLoaded()
		})
		.addTo(map)

	// And NOT a `tileerror` handler, because there is no such event here:
	// `protomaps-leaflet` calls Leaflet's done callback as `done(undefined,
	// tile)` unconditionally, so a tile that never rendered is
	// indistinguishable from one that did. Without the check below a
	// wrong URL is a blank map with no warning and nothing in the console.
	archiveReachable(basemap.url).then((ok) => (ok ? hooks.onLoaded() : hooks.onFailed()))
}

/**
 * Say whether the archive is actually there, once, at mount.
 *
 * Reads the first seven bytes rather than trusting a status: a 206 only
 * says something answered. What has to be true is that the server honours
 * Range AND that what comes back is an archive — a server that ignores
 * Range would make the reader pull a gigabyte for every tile, and an error
 * page can arrive with a perfectly good status code.
 *
 * @param {string} url the archive's address
 * @return {Promise<boolean>} whether the archive answered and spelled its name
 */
export async function archiveReachable(url) {
	try {
		const response = await fetch(url, { headers: { Range: `bytes=0-${PMTILES_MAGIC.length - 1}` } })

		if (response.status !== 206) {
			// Not an error to read the body of: if Range was ignored this is
			// the whole archive, and reading it would do the damage the
			// check exists to prevent.
			response.body?.cancel()
			return false
		}

		if (new TextDecoder().decode(await response.arrayBuffer()) !== PMTILES_MAGIC) {
			return false
		}

		return true
	} catch {
		// Offline, blocked, or a name that does not resolve. The map, the
		// pins and everything drawn on top keep working without it.
		return false
	}
}

/**
 * A directory of `{z}/{x}/{y}` images — OpenStreetMap's shape, and any clone of it.
 */
function addRasterBasemap(map, basemap, hooks) {
	L.tileLayer(basemap.url, {
		attribution: hooks.attribution,
		maxZoom: MAX_ZOOM,
		// Nextcloud serves every page with `Referrer-Policy: no-referrer`, so
		// without this a tile request carries no `Referer` at all — measured.
		// A browser also supplies its own `User-Agent`, which names Chrome and
		// not this app, so the two things OpenStreetMap's Tile Usage Policy
		// asks an application to identify itself by are both absent and the
		// traffic is indistinguishable from anonymous scraping. That is the
		// profile they rate-limit and then answer `403`.
		//
		// `origin` and not the browser default: it sends the scheme, host and
		// port and never a path, so a third party learns which instance asked
		// and never which page of it, nor which record was open.
		referrerPolicy: 'origin',
	})
		// Tiles failing is not an error the app should die on: the clinic may
		// be offline, or the tile host firewalled. Pan, zoom and everything
		// drawn on top keep working, so we note it and carry on.
		.on('tileerror', (event) => {
			hooks.onFailed()

			// Leaflet stamps a failed tile as finished and never asks for it
			// again, so without this the grey square stays for the life of the
			// page — no pan, resize or poll brings it back. A bounded few
			// retries heal a 429 or a lost second of network (`tiles.js`).
			retryTile(event.tile)
		})
		.on('tileload', () => {
			hooks.onLoaded()
		})
		.addTo(map)
}
