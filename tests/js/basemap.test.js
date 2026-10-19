import { beforeEach, describe, expect, it, vi } from 'vitest'

// src/basemap.js imports Leaflet at module top, and `leaflet-src.js` touches
// `window` while loading, which a DOM-less run does not have. The mock only lets
// the module load; the wiring under test is what the layer is built with and
// what a failed or loaded tile is handed to.
vi.mock('leaflet', () => ({ default: { tileLayer: vi.fn() } }))

// The basemap hands every failed tile to `retryTile` and every loaded one to
// `tileLoaded`; what it hands them is what these cases pin, so both are spies.
vi.mock('../../src/tiles.js', async (importOriginal) => ({
	...await importOriginal(),
	retryTile: vi.fn(),
	tileLoaded: vi.fn(),
}))

import L from 'leaflet'
import * as basemap from '../../src/basemap.js'
import { retryTile, tileLayerOptions, tileLoaded } from '../../src/tiles.js'

const { addBasemap, OSM_ATTRIBUTION, OSM_TILE_URL } = basemap

/**
 * The basemap's wiring: which URL and options the layer is built with, and what a
 * tile that failed or loaded is handed to.
 *
 * ADR-0021: the basemap is OpenStreetMap's tile service, one constant, no setting
 * and no second form. `tileLayerOptions` and `retryTile` are tested on their own
 * (`tiles.test.js`); this is the seam between them and Leaflet, which nothing else
 * covers. A layer whose `tileload` handler forgot `tileLoaded()` would pass every
 * other test and slowly lose its retries.
 */
describe('the basemap', () => {
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
		L.tileLayer.mockReset()
		L.tileLayer.mockReturnValue(layer)
		hooks = { attribution: 'credit', onLoaded: vi.fn(), onFailed: vi.fn() }
		retryTile.mockClear()
		tileLoaded.mockClear()
		addBasemap({}, hooks)
	})

	it('is OpenStreetMap\'s tile service, byte for byte', () => {
		// Exact, with no query string and no cache-busting parameter: the Tile Usage
		// Policy's "exact URL" row.
		expect(OSM_TILE_URL).toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
		expect(OSM_TILE_URL).not.toContain('?')
	})

	it('exports the URL and the credit, and nothing that remains of the archive', () => {
		expect(Object.keys(basemap).sort()).toEqual(['OSM_ATTRIBUTION', 'OSM_TILE_URL', 'addBasemap'])
		expect(OSM_ATTRIBUTION).toContain('https://www.openstreetmap.org/copyright')
	})

	it('builds the layer from the constant url and the policy options', () => {
		expect(L.tileLayer).toHaveBeenCalledTimes(1)
		expect(L.tileLayer).toHaveBeenCalledWith(OSM_TILE_URL, tileLayerOptions({ attribution: 'credit' }))
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
