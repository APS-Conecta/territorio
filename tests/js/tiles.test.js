import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	TILE_RETRY_BUDGET,
	TILE_RETRY_DELAYS_MS,
	isSpentTile,
	resetTileRetries,
	retryTile,
	tileLayerOptions,
	tileLoaded,
} from '../../src/tiles.js'

/**
 * Asking again for a basemap tile that failed.
 *
 * Leaflet never does. `_tileOnError` hands the tile to `_tileReady`, which stamps
 * `tile.loaded` and fires `tileerror` — and a stamped tile is a finished tile, so
 * no later redraw, resize or poll ever re-requests it. Measured on this app
 * against `tile.openstreetmap.org` with 40% of tiles answered 503: 10 of 16 tiles
 * blank, and still 10 of 16 blank twelve seconds after the host recovered and
 * after a resize. The hole is permanent for the life of the page.
 *
 * That matters here because of what the tile host is. OpenStreetMap's own wiki
 * files `tile.openstreetmap.org` as a P2 best-effort service under a Tile Usage
 * Policy, answers 429 when it rate-limits, and keeps a page of applications it
 * has blocked outright for heavy use. Tiles failing occasionally is the contract,
 * not a fault — so recovering from it belongs in the app.
 *
 * The retry is deliberately BOUNDED and backs off, and that is the same document's
 * doing: an unbounded retry against a server that is already refusing is precisely
 * the behaviour that gets an application blocked. Three tries and stop.
 */

/**
 * A stand-in for the `<img>` Leaflet puts in the tile pane, recording every write
 * to `src`. A plain object because these tests run with no DOM (there is no jsdom
 * here, by choice) and because the helper is written to need nothing more: a
 * parent, a `src`, and somewhere to keep its own count.
 */
const tileStub = (url = 'https://tile.openstreetmap.org/12/1244/2452.png') => {
	const writes = []
	let current = url
	return {
		parentNode: {},
		writes,
		get src() {
			return current
		},
		set src(value) {
			writes.push(value)
			current = value
		},
	}
}

describe('retryTile', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		resetTileRetries()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('asks for the same tile again once the wait is over', () => {
		const tile = tileStub()

		expect(retryTile(tile)).toBe(true)
		// Nothing yet: retrying instantly is retrying into the same rate limit.
		expect(tile.writes).toEqual([])

		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		// The SAME url, with no cache-busting parameter bolted on. A unique query
		// string would defeat OpenStreetMap's CDN on every retry, which turns one
		// failed tile into a guaranteed origin hit — the opposite of what their
		// policy asks for.
		expect(tile.writes).toEqual(['https://tile.openstreetmap.org/12/1244/2452.png'])
	})

	it('gives up instead of hammering a host that keeps refusing', () => {
		const tile = tileStub()

		for (const delay of TILE_RETRY_DELAYS_MS) {
			expect(retryTile(tile)).toBe(true)
			vi.advanceTimersByTime(delay)
		}

		// Spent. The map keeps a hole here, and that is the correct outcome: this
		// tile may simply not exist — a 404 past the edge of coverage reaches this
		// handler identically, because an <img> error event carries no status.
		expect(retryTile(tile)).toBe(false)
		expect(isSpentTile(tile)).toBe(true)
		expect(tile.writes).toHaveLength(TILE_RETRY_DELAYS_MS.length)
	})

	it('waits longer each time', () => {
		const climbing = TILE_RETRY_DELAYS_MS.every(
			(delay, i) => i === 0 || delay > TILE_RETRY_DELAYS_MS[i - 1],
		)

		expect(climbing).toBe(true)
		expect(TILE_RETRY_DELAYS_MS[0]).toBeGreaterThan(0)
	})

	it('leaves a tile Leaflet has already dropped alone', () => {
		const tile = tileStub()
		tile.parentNode = null

		// Panning away removes the tile from the pane. Re-requesting it then costs
		// the tile server a fetch for a square nobody is looking at, and Leaflet
		// discards the result anyway.
		expect(retryTile(tile)).toBe(false)

		vi.runAllTimers()
		expect(tile.writes).toEqual([])
	})

	it('does not re-request a tile that is being torn down mid-wait', () => {
		const tile = tileStub()

		expect(retryTile(tile)).toBe(true)
		// Leaflet removed it while the retry was still pending — the ordinary case
		// when somebody pans during a bad patch of network.
		tile.parentNode = null
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(tile.writes).toEqual([])
	})

	it('ignores the blank Leaflet parks in a tile it is clearing', () => {
		const tile = tileStub('data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=')

		// `TileLayer._removeTile` sets this url, and `_tileReady` then ignores the
		// tile by the same test. Retrying it would put a removed tile back on the
		// wire, pointed at nothing.
		expect(retryTile(tile)).toBe(false)
		expect(tile.writes).toEqual([])
	})

	it('does not retry a tile that already used its attempts, however full the budget', () => {
		const tile = tileStub()
		tile._territorioRetries = TILE_RETRY_DELAYS_MS.length

		expect(retryTile(tile)).toBe(false)
		vi.runAllTimers()
		expect(tile.writes).toEqual([])
	})
})

describe('the global retry budget', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		resetTileRetries()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/**
	 * A host that refuses everything. Each write to `src` is one request on the
	 * wire, and each request fails 50 ms later, the way an <img> error does, and
	 * goes back through the same handler `basemap.js` wires to `tileerror`.
	 */
	const alwaysFailingHost = (count) => {
		const requests = []
		const tiles = Array.from({ length: count }, (_, i) => {
			const tile = tileStub(`https://tile.openstreetmap.org/12/${1244 + i}/2452.png`)
			const origin = `https://tile.openstreetmap.org/12/${1244 + i}/2452.png`
			Object.defineProperty(tile, 'src', {
				get: () => origin,
				set: (value) => {
					requests.push(value)
					setTimeout(() => retryTile(tile), 50)
				},
			})
			return { tile, origin }
		})
		for (const { tile } of tiles) {
			retryTile(tile)
		}
		return { requests, tiles }
	}

	it('caps the whole page, not each tile, at 16 retries against a host that always fails', () => {
		// Measured before this budget existed: 170 requests in 24 s against an
		// always-503 host, because every tile climbed the ladder in lockstep.
		const { requests } = alwaysFailingHost(30)

		vi.advanceTimersByTime(60_000)

		expect(TILE_RETRY_BUDGET).toBe(16)
		expect(requests).toHaveLength(TILE_RETRY_BUDGET)
	})

	it('never modifies a tile url: the retry is the original, with no query string', () => {
		// A unique query string misses OpenStreetMap's CDN on every retry, and the
		// Tile Usage Policy asks clients to honour the cache.
		const { requests, tiles } = alwaysFailingHost(30)

		vi.advanceTimersByTime(60_000)

		const originals = new Set(tiles.map(({ origin }) => origin))
		expect(requests.length).toBeGreaterThan(0)
		for (const url of requests) {
			expect(originals.has(url)).toBe(true)
			expect(url).not.toContain('?')
		}
	})

	it('accepts every retry when scheduled and spends the budget only when the timer fires', () => {
		const tiles = Array.from({ length: TILE_RETRY_BUDGET + 4 }, () => tileStub())

		// Scheduling consults nothing: all of them are "still trying".
		expect(tiles.map((tile) => retryTile(tile))).toEqual(tiles.map(() => true))
		expect(tiles.flatMap((tile) => tile.writes)).toEqual([])

		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(tiles.flatMap((tile) => tile.writes)).toHaveLength(TILE_RETRY_BUDGET)
	})

	it('lets a later fire do nothing once earlier fires spent the budget', () => {
		const early = Array.from({ length: TILE_RETRY_BUDGET }, () => tileStub())
		const late = tileStub()

		early.forEach((tile) => retryTile(tile))
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0] / 2)
		// Scheduled while the budget is still full: a check made now would pass it.
		expect(retryTile(late)).toBe(true)

		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(early.flatMap((tile) => tile.writes)).toHaveLength(TILE_RETRY_BUDGET)
		expect(late.writes).toEqual([])
	})

	it('sees a refill that arrives between scheduling and firing', () => {
		const early = Array.from({ length: TILE_RETRY_BUDGET }, () => tileStub())
		const late = tileStub()

		early.forEach((tile) => retryTile(tile))
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0] / 2)
		retryTile(late)
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0] / 2)
		// The early fires just spent everything; one tile loads before `late` fires.
		tileLoaded()
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(late.writes).toHaveLength(1)
	})

	it('refills one retry per loaded tile', () => {
		const spenders = Array.from({ length: TILE_RETRY_BUDGET }, () => tileStub())
		spenders.forEach((tile) => retryTile(tile))
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		tileLoaded()
		tileLoaded()
		tileLoaded()
		const fresh = Array.from({ length: 5 }, () => tileStub())
		fresh.forEach((tile) => retryTile(tile))
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(fresh.flatMap((tile) => tile.writes)).toHaveLength(3)
	})

	it('never refills above the budget', () => {
		// A page that loads hundreds of tiles must not bank a hundred retries for
		// the day the host starts refusing.
		for (let i = 0; i < 100; i++) {
			tileLoaded()
		}
		const tiles = Array.from({ length: TILE_RETRY_BUDGET + 10 }, () => tileStub())
		tiles.forEach((tile) => retryTile(tile))
		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])

		expect(tiles.flatMap((tile) => tile.writes)).toHaveLength(TILE_RETRY_BUDGET)
	})
})

describe('tileLayerOptions', () => {
	it('is exactly the set the OpenStreetMap Tile Usage Policy review settled on', () => {
		expect(tileLayerOptions({ attribution: 'osm' })).toEqual({
			attribution: 'osm',
			minZoom: 8,
			maxZoom: 18,
			noWrap: true,
			updateWhenIdle: true,
			updateWhenZooming: false,
			referrerPolicy: 'origin',
		})
	})

	it.each([
		'crossOrigin',
		'detectRetina',
		'errorTileUrl',
		'maxNativeZoom',
		'minNativeZoom',
		'bounds',
		'keepBuffer',
		'subdomains',
	])('does not set %s', (key) => {
		expect(tileLayerOptions({ attribution: 'osm' })).not.toHaveProperty(key)
	})
})
