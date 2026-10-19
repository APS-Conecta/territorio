import { describe, expect, it } from 'vitest'
import { blockOsm } from './setup-no-osm.js'

/**
 * No unit test may reach openstreetmap.org. A test that scraped the tile service
 * is how an application ends up on OpenStreetMap's blocked list, so the setup
 * file makes the attempt throw instead of trusting each test to mock correctly.
 * The three paths are tested on stand-ins because this suite runs without a DOM.
 */
const OSM_TILE = 'https://tile.openstreetmap.org/12/1244/2452.png'

describe('the OpenStreetMap guard', () => {
	it('is installed on the real fetch for every test', () => {
		expect(() => fetch(OSM_TILE)).toThrow(/openstreetmap\.org/)
		expect(() => fetch(new URL('https://www.openstreetmap.org/copyright'))).toThrow()
		expect(() => fetch(new Request(OSM_TILE))).toThrow()
	})

	it('leaves every other host alone', async () => {
		const response = await fetch('data:text/plain,hi')

		expect(await response.text()).toBe('hi')
	})

	it('does not mistake a lookalike host for OpenStreetMap', () => {
		const target = { fetch: (url) => url }
		blockOsm(target)

		expect(target.fetch('https://notopenstreetmap.org/x')).toBe('https://notopenstreetmap.org/x')
	})

	it('blocks XMLHttpRequest.open', () => {
		const opened = []
		const target = { XMLHttpRequest: class { open(method, url) { opened.push(url) } } }
		blockOsm(target)
		const xhr = new target.XMLHttpRequest()

		expect(() => xhr.open('GET', OSM_TILE)).toThrow(/openstreetmap\.org/)
		xhr.open('GET', 'https://example.org/a')
		expect(opened).toEqual(['https://example.org/a'])
	})

	it('blocks the src setter of an image', () => {
		const assigned = []
		const target = {
			HTMLImageElement: class {
				set src(value) { assigned.push(value) }
			},
		}
		blockOsm(target)
		const img = new target.HTMLImageElement()

		expect(() => { img.src = OSM_TILE }).toThrow(/openstreetmap\.org/)
		img.src = 'https://example.org/a.png'
		expect(assigned).toEqual(['https://example.org/a.png'])
	})
})
