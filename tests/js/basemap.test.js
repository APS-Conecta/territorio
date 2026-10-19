import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { TILE_RETRY_DELAYS_MS } from '../../src/tiles.js'

// src/basemap.js imports Leaflet and protomaps at module top — the fork builds
// real layers — and neither survives a DOM-less run: `leaflet-src.js` touches
// `window` while loading. `archiveWatch` touches neither. This file imports no
// Leaflet and drives the campaign entirely through the injected probe and say;
// the mocks only let the module load at all.
vi.mock('leaflet', () => ({ default: { tileLayer: vi.fn() } }))
vi.mock('protomaps-leaflet', () => ({ leafletLayer: () => ({}) }))

// The raster branch hands every failed tile to `retryTile` and every loaded one to
// `tileLoaded`; what it hands them is what these cases pin, so both are spies.
vi.mock('../../src/tiles.js', async (importOriginal) => ({
	...await importOriginal(),
	retryTile: vi.fn(),
	tileLoaded: vi.fn(),
}))

import L from 'leaflet'
import { addBasemap, archiveWatch } from '../../src/basemap.js'
import { retryTile, tileLayerOptions, tileLoaded } from '../../src/tiles.js'

/**
 * Watching a PMTiles archive without a browser.
 *
 * The watch under test is the same code the map wires to `moveend`: one probe
 * per rung of the ladder `tiles.js` owns, a verdict said only when it changes,
 * and a way for a tile that arrived to end the asking. Everything the browser
 * would supply — the archive, the tile event, the clock — is injected here,
 * which is why these cases can pin the campaign's timing exactly.
 */

/**
 * A probe whose answers arrive in the order given; once the queue runs dry it
 * keeps answering `false`, the way an archive that is down stays down. A
 * queued Promise is settled by the test whenever it chooses, which is how the
 * mid-campaign races are staged.
 */
const scriptedProbe = (answers) => {
	const queue = [...answers]
	const calls = []
	return {
		calls,
		probe: () => {
			const answer = queue.length > 0 ? queue.shift() : false
			calls.push(answer)
			return answer instanceof Promise ? answer : Promise.resolve(answer)
		},
	}
}

/** A promise the test settles by hand. */
const held = () => {
	let resolve
	const promise = new Promise((r) => { resolve = r })
	return { promise, resolve }
}

describe('archiveWatch', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/** Let the campaign's probe promise land without moving any timer. */
	const settle = () => vi.advanceTimersByTimeAsync(0)

	/** Start a campaign and run its whole ladder down, all answers landing. */
	const runCampaign = async (watch) => {
		watch.check()
		await settle()
		for (const delay of TILE_RETRY_DELAYS_MS) {
			await vi.advanceTimersByTimeAsync(delay)
			await settle()
		}
	}

	it('announces unreachable only once the whole ladder is spent', async () => {
		const probe = scriptedProbe([false])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		watch.check()
		await settle()
		await vi.advanceTimersByTimeAsync(TILE_RETRY_DELAYS_MS[0])
		// The mount probe failed, and the first retry with it. No verdict
		// yet: a rate-limit window or a lost second of network must not
		// banner a map moments after it loaded.
		expect(verdicts).toEqual([])
		expect(probe.calls).toHaveLength(2)

		await vi.advanceTimersByTimeAsync(TILE_RETRY_DELAYS_MS[1])
		expect(verdicts).toEqual([])

		await vi.advanceTimersByTimeAsync(TILE_RETRY_DELAYS_MS[2])
		// One mount probe plus one per rung, and only now — every rung spent —
		// is the archive pronounced unreachable.
		expect(verdicts).toEqual([false])
		expect(probe.calls).toHaveLength(TILE_RETRY_DELAYS_MS.length + 1)
	})

	it('says a verdict once, however many campaigns agree', async () => {
		const probe = scriptedProbe([])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		await runCampaign(watch)
		// The next `moveend` asks again, and the archive is still down. The
		// verdict has not changed, so nothing is said a second time.
		await runCampaign(watch)
		expect(verdicts).toEqual([false])
	})

	it('says reachable once for a healthy archive, however often it is checked', async () => {
		const probe = scriptedProbe([true, true, true])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		watch.check()
		await settle()
		expect(verdicts).toEqual([true])

		watch.check()
		await settle()
		expect(verdicts).toEqual([true])
	})

	it('flips the verdict back when a re-check finds the archive again', async () => {
		const probe = scriptedProbe([false, false, false, false, true])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		await runCampaign(watch)
		expect(verdicts).toEqual([false])

		// The archive came back; the next `moveend` is what notices, and the
		// caller's layer rebuild rides on this flip.
		watch.check()
		await settle()
		expect(verdicts).toEqual([false, true])
	})

	it('cancels the retries when a tile arrives mid-campaign', async () => {
		const probe = scriptedProbe([false])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		watch.check()
		await settle()
		// The mount probe failed and the first retry is waiting on its timer.
		watch.tileArrived()

		await vi.advanceTimersByTimeAsync(
			TILE_RETRY_DELAYS_MS.reduce((total, delay) => total + delay, 0),
		)
		// Reachable, said once — and no retry ever ran: a tile arriving is
		// better evidence than any probe.
		expect(verdicts).toEqual([true])
		expect(probe.calls).toHaveLength(1)
	})

	it('ignores a probe that lands after tileArrived() ended its campaign', async () => {
		const slowAnswer = held()
		const probe = scriptedProbe([slowAnswer.promise])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		watch.check()
		// The probe is still in flight when the tile arrives.
		watch.tileArrived()
		expect(verdicts).toEqual([true])

		// The stale answer — false — lands late. It must not announce, and it
		// must not schedule the next rung either.
		slowAnswer.resolve(false)
		await vi.advanceTimersByTimeAsync(
			TILE_RETRY_DELAYS_MS.reduce((total, delay) => total + delay, 0),
		)
		expect(verdicts).toEqual([true])
		expect(probe.calls).toHaveLength(1)
	})

	it('leaves a running campaign alone when check() comes round again', async () => {
		const slowAnswer = held()
		const probe = scriptedProbe([slowAnswer.promise, true])
		const verdicts = []
		const watch = archiveWatch(probe.probe, (reachable) => verdicts.push(reachable))

		watch.check()
		// A `moveend` while the mount probe is still in flight: a campaign is
		// already asking, so this one starts nothing and asks nothing.
		watch.check()
		await settle()
		expect(probe.calls).toHaveLength(1)

		slowAnswer.resolve(true)
		await settle()
		expect(verdicts).toEqual([true])
	})
})

/**
 * The raster basemap's wiring: which options the layer is built with, and what a
 * tile that failed or loaded is handed to.
 *
 * `tileLayerOptions` and `retryTile` are tested on their own (`tiles.test.js`); this is
 * the seam between them and Leaflet, which nothing else covers. A layer whose `tileload`
 * handler forgot `tileLoaded()` would pass every other test and slowly lose its retries.
 */
describe('the raster basemap', () => {
	const URL = 'https://tile.example/{z}/{x}/{y}.png'
	let handlers
	let hooks

	beforeEach(() => {
		handlers = {}
		const layer = {
			on: (name, handler) => {
				handlers[name] = handler
				return layer
			},
			addTo: vi.fn(() => layer),
		}
		L.tileLayer.mockReturnValue(layer)
		hooks = { attribution: 'credit', onLoaded: vi.fn(), onFailed: vi.fn() }
		retryTile.mockClear()
		tileLoaded.mockClear()
		addBasemap({}, { kind: 'raster', url: URL }, hooks)
	})

	it('builds the layer from the served url and the policy options', () => {
		expect(L.tileLayer).toHaveBeenCalledWith(URL, tileLayerOptions({ attribution: 'credit' }))
	})

	it('announces failure while no tile has ever loaded, and retries the tile', () => {
		const tile = {}
		handlers.tileerror({ tile })

		expect(hooks.onFailed).toHaveBeenCalledTimes(1)
		expect(retryTile).toHaveBeenCalledWith(tile)
	})

	it('hands a loaded tile to the retry budget and says the basemap is reachable', () => {
		handlers.tileload()

		expect(tileLoaded).toHaveBeenCalledTimes(1)
		expect(hooks.onLoaded).toHaveBeenCalledTimes(1)
	})

	it('stops announcing failure once a tile has loaded, but still retries', () => {
		handlers.tileload()
		const tile = {}
		handlers.tileerror({ tile })

		expect(hooks.onFailed).not.toHaveBeenCalled()
		expect(retryTile).toHaveBeenCalledWith(tile)
	})
})
