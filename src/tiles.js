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
 * in ADR-0004, not a fault — so recovering from one belongs here, and belongs
 * here whatever the configured tile server is: a clinic's own one drops requests
 * too, and Leaflet will not retry those either.
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
 * How many tiles may exhaust their attempts before we stop retrying at all.
 *
 * A handful of holes is a blip worth re-asking about. This many is not a blip:
 * the host is down, firewalled, or refusing us — and `403` is the one that makes
 * this a correctness matter rather than a nicety. A blocked client that answers
 * by sending four times the requests is deepening its own block, which is the
 * behaviour OpenStreetMap's Blocked applications page describes. An <img> error
 * carries no status, so the app cannot tell a 403 from a 503; it can tell «one
 * tile failed» from «everything is failing», and that is enough to stop.
 */
export const TILE_RETRY_GIVE_UP_AFTER = 8

/**
 * Tiles that have used up every attempt, this page load.
 *
 * ponytail: module state, because there is exactly one map per page. Hand it to
 * the layer if a second map ever shares the page.
 */
let spentTiles = 0

/** Only for tests, which would otherwise inherit the count from each other. */
export const resetTileRetries = () => {
	spentTiles = 0
}

/** Whether this tile has used up its attempts. */
export const isSpentTile = (tile) => (tile?._territorioRetries ?? 0) >= TILE_RETRY_DELAYS_MS.length

/** Whether enough tiles have failed outright that we have stopped asking. */
export const hasGivenUpOnTiles = () => spentTiles >= TILE_RETRY_GIVE_UP_AFTER

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
	if (!tile || !tile.parentNode || isSpentTile(tile) || hasGivenUpOnTiles()) {
		return false
	}

	if (String(tile.src).startsWith(EMPTY_TILE_SRC_PREFIX)) {
		return false
	}

	const attempt = tile._territorioRetries ?? 0
	tile._territorioRetries = attempt + 1

	if (isSpentTile(tile)) {
		spentTiles += 1
	}

	// Captured now, because by the time the timer runs Leaflet may have pointed
	// this element at its blank.
	const url = tile.src

	setTimeout(() => {
		if (!tile.parentNode) {
			return
		}

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
