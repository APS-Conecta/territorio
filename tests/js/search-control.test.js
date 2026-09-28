import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * The street search under «¿Dónde estoy?» (SearchControl.vue), and where it is
 * mounted.
 *
 * What the search DECIDES is streets.js's, and streets.test.js tests it. These
 * pin that the control goes through those decisions, and that it keeps clear
 * of three things that were each a bug somewhere else in this app: an animated
 * map move (the null-map error, root-caused three times — TerritorioMap's
 * created()), a cadastre name parsed as markup (#62), and a view read off the
 * FILTERED set, which Capas and «Buscar» change underneath it.
 *
 * Source text, as in locate-control.test.js: nothing in this repo mounts a
 * component. That the map really lands on the chosen street, chosen from the
 * keyboard, is tools/browser-check.py's to measure.
 *
 * ponytail: reading the SFCs as strings, for the reason sidebar.test.js gives.
 */
// fileURLToPath, not .pathname: see imports.test.js.
const SRC = fileURLToPath(new URL('../../src/', import.meta.url))

const control = readFileSync(`${SRC}components/SearchControl.vue`, 'utf8')
const map = readFileSync(`${SRC}components/TerritorioMap.vue`, 'utf8')
const app = readFileSync(`${SRC}App.vue`, 'utf8')

/** The lines of code — not of comments — that move the map. */
const moves = control.split('\n')
	.filter((line) => !/^\s*(\/\/|\/\*|\*)/.test(line))
	.filter((line) => /\.(fitBounds|flyTo|flyToBounds|panTo|setView|setZoom)\(/.test(line))

describe('where a pick takes the map', () => {
	it('moves it once, to the chosen box, unanimated and no closer than zoom 17', () => {
		expect(moves).toHaveLength(1)
		expect(moves[0]).toContain('.fitBounds(cornersOf(place),')
		expect(moves[0]).toContain('animate: false')
		expect(moves[0]).toContain('maxZoom: 17')
	})
})

describe('what the keyboard can pick', () => {
	/** ADR-0007: Enter takes the active row or nothing. */
	it('goes through chosen(), and never reaches for the first match itself', () => {
		expect(control).toContain('chosen(this.options, this.active)')
		expect(control).not.toMatch(/options\[0\]/)
	})
})

describe('what the search says and asks', () => {
	/** #62: these names come out of an imported cadastre, not from anyone here. */
	it('writes every name as text, never as markup', () => {
		expect(control).toContain('.textContent = place.name')
		expect(control).not.toMatch(/\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML/)
	})

	/**
	 * One listener for both, so a failed load is retried by typing — which is
	 * what the failure message tells the person to do (streets.js, searchStatus).
	 */
	it('loads on focus and on every keystroke alike', () => {
		expect(control).toContain("L.DomEvent.on(input, 'focus input', () => this.ask())")
	})

	/** Plain, not OCS: ADR-0020 keeps the published surface at three. */
	it('asks the plain route beside the Sector editor\'s read', () => {
		expect(control).toContain("generateUrl('/apps/territorio/boundaries/names')")
		expect(control).not.toMatch(/ocs\//i)
	})
})

describe('where it is mounted', () => {
	it('waits for the map, and comes after «¿Dónde estoy?» so it stacks under it', () => {
		const locate = map.indexOf('<LocateControl v-if="mapReady"')
		const search = map.indexOf('<SearchControl v-if="mapReady"')

		// Both present first: -1 is less than any index.
		expect(locate).toBeGreaterThan(-1)
		expect(search).toBeGreaterThan(-1)
		expect(search).toBeGreaterThan(locate)
	})

	/** Hiding the divisions in Capas, or «pasaje» in «Buscar», must not take the hints away. */
	it('reads the Unidades Vecinales off the unfiltered divisions, never off the filtered features', () => {
		expect(map).toMatch(/unidades\(\) \{\s*return this\.divisions\.filter\(/)
		expect(map).toContain(':unidades="unidades"')

		const at = app.indexOf('<TerritorioMap')
		expect(at).toBeGreaterThan(-1)
		expect(app.slice(at, app.indexOf('/>', at))).toContain(':divisions="divisions"')
	})
})
