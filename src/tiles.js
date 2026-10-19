/**
 * Asking again for a basemap tile that failed to load.
 *
 * Leaflet never does, and that is not an oversight to work around in one place:
 * `TileLayer._tileOnError` hands the tile straight to `_tileReady`, which stamps
 * `tile.loaded` with a timestamp and fires `tileerror`. A stamped tile is a
 * finished tile, so no later redraw, pan, resize or five-second poll re-requests
 * it. The grey square stays for the life of the page.
 *
 * Measured on this app, 40% of tiles answered 503: 10 of 16 blank, and still 10
 * of 16 blank twelve seconds after the host recovered and after a resize.
 *
 * Worth owning because of what the tile host is. OpenStreetMap's wiki files
 * `tile.openstreetmap.org` as a P2 best-effort service under a Tile Usage Policy,
 * answers 429 when it rate-limits, and keeps a published list of applications it
 * blocked for heavy use. A tile failing now and then is the contract we accepted
 * in ADR-0004, not a fault — so recovering from one belongs here, and Leaflet
 * will not do it.
 *
 * No dependency for this. `Leaflet.TileLayer.Fallback` is the plugin that exists
 * and it solves a different problem (substitute a lower zoom for a 404), while
 * `errorTileUrl` only paints a placeholder over the hole. Both leave the tile
 * spent.
 */

/**
 * The blank Leaflet parks in the `src` of a tile it is removing. `_tileReady`
 * ignores a tile by this same test, so re-requesting one would put a tile
 * Leaflet has already discarded back on the wire.
 */
const EMPTY_TILE_SRC_PREFIX = 'data:image/gif'

/**
 * How long to wait before each attempt, in order — and therefore also how many
 * attempts there are.
 *
 * Bounded and climbing, both for the same reason: an unbounded retry against a
 * server that is already refusing is the exact behaviour OpenStreetMap blocks
 * applications for. Three tries over ~17 seconds covers a rate-limit window or a
 * lost second of network, and stops well short of looking like a scraper.
 */
export const TILE_RETRY_DELAYS_MS = [1000, 4000, 12000]

/**
 * How many retries the whole page may spend, however many tiles are failing.
 *
 * The ladder above bounds one tile; this bounds the page. Measured against an
 * always-503 host, the ladder alone plus «give up after 8 spent tiles» produced
 * 170 requests in 24 s: the give-up check ran when a retry was SCHEDULED, so
 * every tile climbed its rungs together before any of them was counted spent.
 * `403` is what makes this a correctness matter: a blocked client that answers
 * by sending more requests is deepening its own block (OpenStreetMap's Blocked
 * applications page). An <img> error carries no status, so the app cannot tell a
 * 403 from a 503; it can tell «a few tiles failed» from «everything is failing».
 *
 * 16 covers a screenful of scattered blips; `tileLoaded` earns retries back.
 */
export const TILE_RETRY_BUDGET = 16

/**
 * Retries still available this page load.
 *
 * ponytail: module state, because there is exactly one map per page. Hand it to
 * the layer if a second map ever shares the page.
 */
let budget = TILE_RETRY_BUDGET

/** Only for tests, which would otherwise inherit the budget from each other. */
export const resetTileRetries = () => {
	budget = TILE_RETRY_BUDGET
}

/**
 * A tile loaded: the host is answering, so give one retry back.
 *
 * Capped at the budget so a long healthy session cannot bank retries for the day
 * the host starts refusing.
 */
export const tileLoaded = () => {
	budget = Math.min(budget + 1, TILE_RETRY_BUDGET)
}

/** Whether this tile has used up its attempts. */
export const isSpentTile = (tile) => (tile?._territorioRetries ?? 0) >= TILE_RETRY_DELAYS_MS.length

/**
 * The options for the OpenStreetMap raster layer, in one place so a test can pin
 * every one of them against the Tile Usage Policy
 * (https://operations.osmfoundation.org/policies/tiles/).
 *
 * What is deliberately ABSENT matters as much: `crossOrigin` (turns tile loads
 * into CORS requests), `detectRetina` (asks for z+1 tiles, four times the
 * requests), `errorTileUrl` (a placeholder over a tile Leaflet then calls done),
 * `maxNativeZoom`/`minNativeZoom` (upscaling instead of asking), `subdomains`
 * (OSM has one host), and `bounds`, which is absent because the owner decided
 * there is no pan lock: people may pan anywhere.
 */
export const tileLayerOptions = ({ attribution }) => ({
	attribution,
	// The owner capped zoom at 18; the app itself navigates to 17 at most, and
	// the deepest levels are the ones that cost OpenStreetMap the most. z8 stops a
	// zoomed-out view from requesting a whole country's tiles. L.map sets no
	// zoom limits of its own: the map picks these up because GridLayer.onAdd calls
	// map._addZoomLimit(this) and getMinZoom/getMaxZoom fall back to the layer's
	// (Leaflet 1.9.4), so they bind map and layer alike.
	minZoom: 8,
	maxZoom: 18,
	// Without it the world repeats sideways and every copy is another set of
	// requests for tiles already on screen.
	noWrap: true,
	// Tiles are requested when a pan or zoom ends, not on every frame of it.
	updateWhenIdle: true,
	// Leaflet 1.9.4, measured: a flyTo from z12 to z17 drops from ~255 to ~65
	// tile requests, because the layer stops loading each intermediate zoom.
	updateWhenZooming: false,
	// Nextcloud sends `Referrer-Policy: no-referrer`, so without this a tile
	// request has no `Referer`, and OSM's policy requires one. `origin` sends
	// scheme, host and port only, never which page or record was open.
	//
	// `keepBuffer` is left at its default on purpose: it only prunes tiles that
	// are already loaded and is NOT a guard against prefetching.
	referrerPolicy: 'origin',
})

/**
 * Schedule one more request for a tile that just failed.
 *
 * Returns whether an attempt was scheduled, so a caller can tell "we are still
 * trying" from "this tile is not coming".
 *
 * ponytail: the count lives on the element and only ever climbs, so a tile that
 * fails, recovers, then fails again late in a session has fewer tries left than
 * a fresh one. Bounding the total is the property that protects the tile server;
 * resetting on success would need a per-tile `tileload` hook for no measured
 * gain. Give each tile its own budget back if a long-lived session ever shows
 * holes that a pan fixes — panning builds new elements, so it already does.
 */
export function retryTile(tile) {
	// No parent means Leaflet removed the tile: the view moved on, and the fetch
	// would be for a square nobody is looking at.
	if (!tile || !tile.parentNode || isSpentTile(tile)) {
		return false
	}

	if (String(tile.src).startsWith(EMPTY_TILE_SRC_PREFIX)) {
		return false
	}

	const attempt = tile._territorioRetries ?? 0
	tile._territorioRetries = attempt + 1

	// Captured now, because by the time the timer runs Leaflet may have pointed
	// this element at its blank.
	const url = tile.src

	setTimeout(() => {
		if (!tile.parentNode) {
			return
		}

		// The budget is spent here and not when the retry is scheduled: a check at
		// schedule time lets every failing tile climb its ladder together before
		// anything has been counted, which is the 170-request run this replaced.
		if (budget <= 0) {
			return
		}

		budget -= 1

		// The same url, with nothing appended. A cache-busting parameter would miss
		// OpenStreetMap's CDN on every retry and turn each failed tile into a
		// guaranteed origin hit, which is the opposite of what their policy asks.
		//
		// Assigning the identical url is enough: an <img> whose last load failed is
		// in the `broken` state, and re-setting `src` on a broken image re-runs the
		// fetch rather than short-circuiting the way it does for one already loaded.
		// Measured in Chromium — a bare re-assignment issued exactly one new
		// request — after this line first cleared the attribute for a reason that
		// turned out not to exist.
		tile.src = url
	}, TILE_RETRY_DELAYS_MS[attempt])

	return true
}
