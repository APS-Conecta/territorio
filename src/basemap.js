/**
 * The basemap, in whichever of its two forms this install serves (ADR-0019).
 *
 * Out of TerritorioMap.vue (L1-32): the fork is a decision about which Leaflet
 * layer to build and how to notice it failed — no drawing, no pins, no draft.
 * Everything here existed verbatim in the component under the same names.
 */
import L from 'leaflet'
import { retryTile, TILE_RETRY_DELAYS_MS } from './tiles.js'
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
 *        `onLoaded`/`onFailed` announce the LATEST verdict on the basemap's
 *        reachability — a verdict, not an event to count or latch. The parent
 *        binds its banner to the pair; neither branch lets a mid-session blip
 *        through, because raster only announces failure before its first
 *        loaded tile, and the archive watch retries before it ever says
 *        unreachable.
 */
export function addBasemap(map, basemap, hooks) {
	if (basemap.kind === 'pmtiles') {
		addPmtilesBasemap(map, basemap, hooks)
		return
	}

	addRasterBasemap(map, basemap, hooks)
}

/**
 * Watch a PMTiles archive and announce its reachability as a verdict.
 *
 * A single probe at mount — what this module used to do — can only ever say
 * "down": once the banner latched, no reload meant no second chance. A
 * campaign, probe and retry up a ladder and re-check whenever `check()` is
 * called, is what lets the verdict flip back on its own.
 *
 * The ladder is the raster one from `tiles.js`, imported and not duplicated:
 * the same bounded, climbing answer to the same question — how long to keep
 * asking a host that is refusing you before you believe it.
 *
 * `say(reachable)` fires only when the verdict CHANGES, so the caller can
 * bind a banner to it without counting events.
 *
 * @param {() => Promise<boolean>} probe asks the archive once
 * @param {(reachable: boolean) => void} say receives each new verdict
 * @param {{delays?: number[], schedule?: Function}} timing injectable for the
 *        tests; production takes `tiles.js`'s ladder and setTimeout
 * @return {{check: Function, tileArrived: Function}} the watch's two handles
 */
export function archiveWatch(probe, say, { delays = TILE_RETRY_DELAYS_MS, schedule = setTimeout } = {}) {
	let verdict = null
	let active = 0
	let sequence = 0
	let timer = null

	const announce = (reachable) => {
		if (verdict === reachable) {
			return
		}

		verdict = reachable
		say(reachable)
	}

	const attempt = (id, retriesLeft) => {
		probe().then((reachable) => {
			// A campaign is aborted by setting its number aside, so a probe that
			// lands after `tileArrived()` ended it answers a question nobody is
			// asking — and must not schedule its retry.
			if (id !== active) {
				return
			}

			if (reachable) {
				active = 0
				announce(true)
				return
			}

			if (retriesLeft === 0) {
				// Ladder spent. Only now is the archive pronounced unreachable —
				// a rate-limit window or a lost second of network is not one.
				active = 0
				announce(false)
				return
			}

			timer = schedule(() => {
				timer = null
				attempt(id, retriesLeft - 1)
			}, delays[delays.length - retriesLeft])
		})
	}

	return {
		/**
		 * Start a campaign if none is running: at mount, and again on every
		 * `moveend`. One already in flight — probe pending or retry waiting —
		 * is left alone.
		 */
		check() {
			if (active !== 0) {
				return
			}

			sequence += 1
			active = sequence
			attempt(sequence, delays.length)
		},

		/**
		 * A tile arrived, so the archive is answering whatever the ladder still
		 * thinks: announce reachable now and abort the standing campaign.
		 */
		tileArrived() {
			if (timer !== null) {
				clearTimeout(timer)
				timer = null
			}

			active = 0
			announce(true)
		},
	}
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
	let layer = null

	// The layer, built by a function because it is built more than once. A
	// tile whose fetch failed is stamped loaded by Leaflet anyway —
	// `protomaps-leaflet` swallows the rejection, paints the blank and calls
	// its done callback unconditionally — and Leaflet never asks for a stamped
	// tile again, the same lesson `tiles.js` records. So a layer that lived
	// through an outage cannot repaint on its own when the archive returns:
	// it is dropped and rebuilt whole, the swap-everything idiom every group
	// on this map already uses.
	function draw() {
		layer?.remove()
		layer = leafletLayer({
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
		}).addTo(map)
	}

	// No `tileerror` handler, because there is no such event to hook: the
	// done callback is unconditional, fired for a tile whose fetch failed
	// exactly like one that painted — which is also why there is no
	// `tileload` → `watch.tileArrived()` here. That event fires over a blank
	// map too, so it cannot mean "the archive answered"; the verdicts are the
	// probe campaigns' alone, and the probe reads the archive's first seven
	// bytes rather than trusting any tile event.
	let unreachable = false
	const watch = archiveWatch(
		() => archiveReachable(basemap.url),
		(reachable) => {
			if (reachable) {
				if (unreachable) {
					// Back, after having been down: rebuild, for the reason
					// `draw` records — the tiles the old layer lost are stamped
					// loaded and will never be asked for again.
					draw()
				}

				unreachable = false
				hooks.onLoaded()
				return
			}

			unreachable = true
			hooks.onFailed()
		},
	)

	draw()
	// Every campaign ends in a verdict, so each pan gives a down archive
	// another chance — and costs a healthy one a seven-byte Range request.
	map.on('moveend', watch.check)
	watch.check()
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
	// The latch this module's parent used to compute, kept here: failure is
	// only worth announcing while NO tile has ever loaded. A healthy server
	// still 404s tiles at the edge of coverage, and a healthy session still
	// drops the odd tile to a rate-limit blip — `retryTile` owns those. The
	// banner must not pin itself over either.
	let loaded = false

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
			hooks.onLoaded()
		})
		.addTo(map)
}
