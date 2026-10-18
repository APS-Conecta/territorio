import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * The street search in the Omnibox, and the two controls it replaced: Leaflet's
 * «Buscar una calle» (SearchControl.vue) and Geoman's own toolbar button.
 *
 * What the search DECIDES — which rows match, which Unidad Vecinal a row sits in,
 * what to say when there is nothing — is streets.js's and assets.js's, and their
 * tests cover it. These pin that the Omnibox and App.vue go through those
 * decisions, and keep clear of the things that were each a bug elsewhere: a map
 * move with no guard for a map that is not built yet, a cadastre name parsed as
 * markup (#62), a street list proxied row by row, and a unit read off the
 * FILTERED set. They carry over what search-control.test.js pinned.
 *
 * Source text, for the reason sidebar.test.js gives: nothing in this repo mounts
 * a component. That a pick really lands on its street, and that two streets of
 * one name are offered as two rows, is tools/browser-check.py --only redesign's.
 */
// fileURLToPath, not .pathname: see imports.test.js.
const SRC = fileURLToPath(new URL('../../src/', import.meta.url))
const read = (path) => readFileSync(`${SRC}${path}`, 'utf8')

const omnibox = read('components/OmniboxControl.vue')
const map = read('components/TerritorioMap.vue')
const app = read('App.vue')

/** The body of one method, from its name to the next method at the same indent. */
const methodOf = (source, name) => {
	const at = source.search(new RegExp(`\\n\\t\\t(async )?${name}\\(`))
	expect(at, `${name}() is in the file`).toBeGreaterThan(-1)
	return source.slice(at, source.indexOf('\n\t\t},', at))
}

describe('where a pick takes the map', () => {
	const fit = methodOf(map, 'fitStreetBounds')

	/** A street is a box, and a short pasaje fitted to its box would zoom to a house. */
	it('fits the chosen box and goes no closer than zoom 17', () => {
		expect(fit).toContain('fitBounds(bounds,')
		expect(fit).toContain('maxZoom: 17')
	})

	/** The null-map error, root-caused three times: a move before the map exists. */
	it('does nothing while the map is not built', () => {
		expect(fit).toMatch(/this\.map === null/)
	})

	it('is what App.vue calls for a street pick, with the server\'s four edges', () => {
		const go = methodOf(app, 'goToStreet')
		expect(go).toContain('[[street.south, street.west], [street.north, street.east]]')
		expect(go).toContain('fitStreetBounds(bounds)')
	})
})

describe('what the keyboard can pick', () => {
	/** ADR-0007: Enter takes the highlighted row or nothing, never «the first match». */
	it('takes the active row only', () => {
		const pick = methodOf(omnibox, 'selectActiveResult')
		expect(pick).toContain('this.activeIndex >= 0')
		expect(omnibox).not.toMatch(/results\[0\]/)
	})
})

describe('what the search says and asks', () => {
	/** #62: these names come out of an imported cadastre, not from anyone here. */
	it('writes every name as text, never as markup', () => {
		expect(omnibox).not.toMatch(/v-html|innerHTML|insertAdjacentHTML/)
		expect(omnibox).toContain('{{ result.name }}')
	})

	/** Plain, not OCS: ADR-0020 keeps the published surface at three. */
	it('asks the plain route', () => {
		const load = methodOf(app, 'loadStreets')
		expect(load).toContain("generateUrl('/apps/territorio/boundaries/names')")
		expect(app).not.toMatch(/ocs\//i)
	})

	/** What streets.js's searchStatus message tells the person to do after a failure. */
	it('asks again on the next keystroke after a failure, and never twice at once', () => {
		expect(methodOf(app, 'onOmniboxInput')).toContain('this.loadStreets()')
	})

	/** A street name matches no record: filtering the list on it only shows «Nada coincide». */
	it('does not filter the list or the map while a street is typed', () => {
		expect(methodOf(app, 'onOmniboxInput')).not.toContain('this.search(')
		expect(methodOf(app, 'loadStreets')).toMatch(/if \(this\.streetsLoaded \|\| this\.streetsLoading\)/)
		expect(methodOf(app, 'loadStreets')).toContain('this.streetsFailed = true')
	})

	/** About five thousand rows: a Proxy per row is what SearchControl kept them out of. */
	it('keeps the street list out of Vue\'s reactivity', () => {
		expect(app).toContain("import { markRaw } from 'vue'")
		expect(methodOf(app, 'loadStreets')).toContain('this.streets = markRaw(data)')
	})
})

describe('how same-named streets are told apart', () => {
	/** Hiding the divisions in Capas, or «pasaje» in «Buscar», must not take the hints away. */
	it('reads the Unidades Vecinales off the unfiltered divisions, never off the filtered features', () => {
		expect(app).toMatch(/unidadesVecinales\(\) \{\s*return this\.divisions\.filter\(/)
		expect(app).toContain(':unidades="unidadesVecinales"')
	})

	it('shows where a street is and how much of it there is', () => {
		expect(omnibox).toContain('placeDetail(hintFor(street, this.unidades), street.count)')
	})
})

describe('the controls this replaced stay gone', () => {
	it('mounts no second street search on the map', () => {
		expect(map).not.toContain('SearchControl')
	})

	it('draws with DrawSectorButton and not Geoman\'s own toolbar', () => {
		expect(map).not.toMatch(/pm\.addControls\(/)
		expect(map).toContain('toggleDrawPolygon()')
	})
})
