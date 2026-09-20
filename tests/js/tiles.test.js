import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	TILE_RETRY_DELAYS_MS,
	TILE_RETRY_GIVE_UP_AFTER,
	hasGivenUpOnTiles,
	isSpentTile,
	resetTileRetries,
	retryTile,
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
	it('stops retrying altogether once enough tiles have failed outright', () => {
		// One tile failing is a blip. This many is the host refusing us — and the
		// answer to being refused is not four times the requests. `403` is what
		// makes it matter: OpenStreetMap blocks applications, and a blocked client
		// that retries harder is deepening its own block.
		const exhaust = () => {
			const tile = tileStub()
			for (const delay of TILE_RETRY_DELAYS_MS) {
				retryTile(tile)
				vi.advanceTimersByTime(delay)
			}
			return tile
		}

		for (let i = 0; i < TILE_RETRY_GIVE_UP_AFTER; i++) {
			exhaust()
		}

		expect(hasGivenUpOnTiles()).toBe(true)

		// A brand new tile, with every attempt still unspent, is not retried.
		const fresh = tileStub()
		expect(retryTile(fresh)).toBe(false)
		vi.runAllTimers()
		expect(fresh.writes).toEqual([])
	})

	it('keeps retrying while failures stay occasional', () => {
		const tile = tileStub()

		// Well under the ceiling: a few scattered holes must still heal.
		expect(retryTile(tile)).toBe(true)
		expect(hasGivenUpOnTiles()).toBe(false)

		vi.advanceTimersByTime(TILE_RETRY_DELAYS_MS[0])
		expect(tile.writes).toHaveLength(1)
	})
})
