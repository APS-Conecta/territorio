import { describe, expect, it } from 'vitest'
import { suggestions } from '../../src/boundaries.js'
import {
	chosen,
	cornersOf,
	hintFor,
	nextActive,
	placeDetail,
	placesMatching,
	searchStatus,
} from '../../src/streets.js'

/**
 * Finding a street on the map (OmniboxControl.vue).
 *
 * The server has already split every name by place (BoundaryService::names()).
 * These pin what the browser decides from there: what typing offers, which
 * Unidad Vecinal tells two rows apart, where the keyboard goes, where the map
 * goes — and that nothing is ever picked for the person (ADR-0007).
 */
const PLACES = [
	{ name: 'Pasaje Dos', south: -33.5231, west: -70.5911, north: -33.5226, east: -70.5899, count: 1 },
	{ name: 'Pasaje Dos', south: -33.5402, west: -70.5712, north: -33.5398, east: -70.5703, count: 2 },
	{ name: 'Avenida Vicuña Mackenna', south: -33.56, west: -70.6, north: -33.45, east: -70.59, count: 128 },
	{ name: 'Los Toros', south: -33.51, west: -70.58, north: -33.509, east: -70.575, count: 3 },
	{ name: 'Pasaje Los Toros Norte', south: -33.52, west: -70.58, north: -33.519, east: -70.579, count: 1 },
]

/** A rectangle of a Unidad Vecinal, from `south` to `north`, across the whole fixture. */
const band = (south, north) => ({
	type: 'Polygon',
	coordinates: [[[-70.62, south], [-70.55, south], [-70.55, north], [-70.62, north], [-70.62, south]]],
})

// Two Unidades Vecinales, one above the other at -33.53: the first Pasaje Dos
// is in the northern one and the second in the southern one.
const UNIDADES = [
	{ id: 1, name: 'Villa Los Quillayes', geometry: band(-33.53, -33.50) },
	{ id: 2, name: 'Población Santa Julia', geometry: band(-33.56, -33.53) },
]

describe('what typing offers', () => {
	/** The criterion: two streets with one name are two rows, in the server's order. */
	it('offers each place a name stands for as its own row', () => {
		const offered = placesMatching(PLACES, 'pasaje dos')

		expect(offered.map((place) => place.name)).toEqual(['Pasaje Dos', 'Pasaje Dos'])
		expect(offered.map((place) => place.count)).toEqual([1, 2])
	})

	it('ignores accents and case, like every other search here', () => {
		expect(placesMatching(PLACES, 'VICUNA')[0]?.name).toBe('Avenida Vicuña Mackenna')
	})

	/** One idea of "the closer match" for the app's two street lists. */
	it('ranks names exactly as the Sector editor does', () => {
		const lines = PLACES.map((place, id) => ({ id, name: place.name }))

		expect(placesMatching(PLACES, 'los toros').map((place) => place.name))
			.toEqual(suggestions(lines, 'los toros').map((group) => group.name))
		expect(placesMatching(PLACES, 'los toros').map((place) => place.name))
			.toEqual(['Los Toros', 'Pasaje Los Toros Norte'])
	})

	it('offers nothing for an empty query, rather than everything', () => {
		expect(placesMatching(PLACES, '')).toEqual([])
		expect(placesMatching(PLACES, '   ')).toEqual([])
	})

	it('stops at a length somebody can read', () => {
		const many = Array.from({ length: 40 }, (_, i) => ({ ...PLACES[0], name: `Calle ${i}` }))

		expect(placesMatching(many, 'calle')).toHaveLength(12)
	})

	it('leaves the list it was handed in the order it came', () => {
		const held = [...PLACES]
		placesMatching(held, 'a')

		expect(held).toEqual(PLACES)
	})
})

describe('the place hint', () => {
	it('names the Unidad Vecinal a place\'s centre falls in', () => {
		expect(hintFor(PLACES[0], UNIDADES)).toBe('Villa Los Quillayes')
		expect(hintFor(PLACES[1], UNIDADES)).toBe('Población Santa Julia')
	})

	/** A street at the comuna's edge: say nothing rather than guess. */
	it('says nothing for a place outside every Unidad Vecinal', () => {
		const away = { name: 'Camino Lejano', south: -33.7, west: -70.9, north: -33.69, east: -70.89, count: 1 }

		expect(hintFor(away, UNIDADES)).toBe('')
		expect(hintFor(PLACES[0], [])).toBe('')
	})

	/** One broken record must not take the search down with it. */
	it('skips a Unidad Vecinal with no ring to test against', () => {
		const broken = [
			{ id: 3, name: 'Sin forma', geometry: null },
			{ id: 4, name: 'Un punto', geometry: { type: 'Point', coordinates: [-70.59, -33.52] } },
			{ id: 5, name: 'Anillo nulo', geometry: { type: 'Polygon', coordinates: null } },
			...UNIDADES,
		]

		expect(hintFor(PLACES[0], broken)).toBe('Villa Los Quillayes')
	})

	it('says where and how much, and only how much when it cannot say where', () => {
		expect(placeDetail('Villa Los Quillayes', 1)).toBe('Villa Los Quillayes · 1 tramo')
		expect(placeDetail('Villa Los Quillayes', 3)).toBe('Villa Los Quillayes · 3 tramos')
		expect(placeDetail('', 128)).toBe('128 tramos')
	})
})

describe('the keyboard', () => {
	it('moves down from nothing to the first, and stops at the last', () => {
		expect(nextActive(-1, 3, 'ArrowDown')).toBe(0)
		expect(nextActive(0, 3, 'ArrowDown')).toBe(1)
		expect(nextActive(2, 3, 'ArrowDown')).toBe(2)
	})

	it('moves up from nothing to the last, and stops at the first', () => {
		expect(nextActive(-1, 3, 'ArrowUp')).toBe(2)
		expect(nextActive(2, 3, 'ArrowUp')).toBe(1)
		expect(nextActive(0, 3, 'ArrowUp')).toBe(0)
	})

	/** Home, End and every letter belong to the text being typed. */
	it('leaves the active option alone for any other key', () => {
		for (const key of ['Home', 'End', 'a', 'Enter', 'Tab']) {
			expect(nextActive(1, 3, key)).toBe(1)
		}
	})

	it('has nothing to make active in an empty list', () => {
		expect(nextActive(-1, 0, 'ArrowDown')).toBe(-1)
		expect(nextActive(4, 0, 'ArrowUp')).toBe(-1)
	})

	/** ADR-0007: Enter with nothing chosen is not "the first match", even when it is the only one. */
	it('picks the active place and never one nobody chose', () => {
		const offered = placesMatching(PLACES, 'pasaje dos')

		expect(chosen(offered, -1)).toBeNull()
		expect(chosen([offered[0]], -1)).toBeNull()
		expect(chosen(offered, 1)).toBe(offered[1])
		expect(chosen(offered, 5)).toBeNull()
	})
})

describe('where the map goes', () => {
	/** Leaflet reads [lat, lng]; GeoJSON and the server's boxes read west before south. */
	it('hands Leaflet the box latitude first', () => {
		expect(cornersOf(PLACES[0])).toEqual([[-33.5231, -70.5911], [-33.5226, -70.5899]])
	})
})

describe('what it says when there is no list', () => {
	const state = (overrides) => ({ loading: false, failed: false, places: PLACES, query: '', matches: [], ...overrides })

	it('says it is loading while the first answer is on its way', () => {
		expect(searchStatus(state({ loading: true, places: null }))).toBe('Cargando las calles…')
	})

	/** L1-28: a list that failed to load is not a map with no streets. */
	it('says the load failed, and how to try again', () => {
		expect(searchStatus(state({ failed: true, places: null })))
			.toBe('No se pudieron cargar las calles. Escriba de nuevo para volver a intentarlo.')
	})

	/** Production today: no Cadastral Dataset. Said as soon as it is known, before anything is typed. */
	it('says so when this map has no streets at all', () => {
		expect(searchStatus(state({ places: [] }))).toBe('No hay calles cargadas en este mapa.')
		expect(searchStatus(state({ places: [], query: 'pasaje' }))).toBe('No hay calles cargadas en este mapa.')
	})

	it('says so when nothing has the name typed', () => {
		expect(searchStatus(state({ query: 'zzz', matches: [] }))).toBe('Ninguna calle con ese nombre.')
	})

	it('says nothing while there is a list, or nothing typed', () => {
		expect(searchStatus(state({ query: 'pasaje', matches: [PLACES[0]] }))).toBe('')
		expect(searchStatus(state({ query: '' }))).toBe('')
		expect(searchStatus(state({ places: null }))).toBe('')
	})
})
