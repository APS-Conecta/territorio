/**
 * Finding a street on the map — the decisions behind the search box under
 * «¿Dónde estoy?» (the Omnibox, OmniboxControl.vue, now).
 *
 * The server sends every street the Cadastral Dataset names, once per place
 * (BoundaryService::names()): «Pasaje Dos» in sixteen places is sixteen rows,
 * each with its own box and no shape. Everything here is about offering those
 * rows so a person can tell them apart and choose one — ADR-0007 on the search
 * side: nothing here ever picks for them.
 *
 * Pure, like locate.js beside LocateControl.vue: the control does the DOM and
 * the map, and the deciding lives here, where it can be tested.
 */
import { booleanPointInPolygon } from '@turf/boolean-point-in-polygon'
import { fold } from './filters.js'

/**
 * The Unidad Vecinal a place's box centre falls in, by name — the words that
 * tell sixteen «Pasaje Dos» apart.
 *
 * The Unidades Vecinales are ordinary records the map already holds (their
 * rings draw the comuna's ground), so this costs no request, and it runs only
 * for the dozen rows on screen. The centre of the box rather than the street
 * itself: for a pasaje they are the same block, and for an avenue running
 * through several, one of them is still a fair answer to "which one".
 *
 * Empty when the centre is in none — a street at the comuna's edge — rather
 * than a guess. The row still stands on its own, with its count.
 *
 * @param {{south: number, west: number, north: number, east: number}} place
 * @param {Array<{name: string, geometry: ?object}>} unidades
 * @return {string}
 */
export function hintFor(place, unidades) {
	const centre = [(place.west + place.east) / 2, (place.south + place.north) / 2]
	const inside = unidades.find((unidad) => isArea(unidad.geometry)
		&& booleanPointInPolygon(centre, unidad.geometry))

	return inside?.name ?? ''
}

/**
 * Only a ring can hold a point. Anything else is skipped rather than handed to
 * turf — a `coordinates: null` the reader let through throws there, and one
 * broken Unidad Vecinal must not take the search down with it (the same guard
 * `boundsOf()` keeps in taxonomy.js).
 */
const isArea = (geometry) => (geometry?.type === 'Polygon' || geometry?.type === 'MultiPolygon')
	&& Array.isArray(geometry.coordinates)

/**
 * The line under a row's name: where it is and how much of it there is —
 * «Villa Los Quillayes · 3 tramos», or «3 tramos» alone when the centre is in
 * no Unidad Vecinal.
 *
 * «tramo» because that is what the Sector editor already calls one line of the
 * cadastre («Tramos del límite»).
 */
export function placeDetail(hint, count) {
	const stretches = count === 1 ? '1 tramo' : `${count} tramos`

	return hint === '' ? stretches : `${hint} · ${stretches}`
}

/**
 * What the search says when it has no list to show, or '' when it has nothing
 * to say.
 *
 * Four answers, not one (L1-28): still loading, failed to load, a map with no
 * streets imported, and a name nobody has. They mean four different things to
 * do next, and an empty list says none of them. `places` is null until the
 * first load answers, so «no streets on this map» is only ever said about an
 * answer, never about a request still on its way.
 *
 * @param {{loading: boolean, failed: boolean, places: ?Array, query: string, matches: Array}} state
 * @return {string}
 */
export function searchStatus({ loading, failed, places, query, matches }) {
	if (loading) {
		return 'Cargando las calles…'
	}

	if (failed) {
		return 'No se pudieron cargar las calles. Escriba de nuevo para volver a intentarlo.'
	}

	if (places !== null && places.length === 0) {
		return 'No hay calles cargadas en este mapa.'
	}

	if (places !== null && fold(query).trim() !== '' && matches.length === 0) {
		return 'Ninguna calle con ese nombre.'
	}

	return ''
}

/**
 * Asynchronously prefetch boundary streets during browser idle time
 * to warm up the combobox cache without blocking main-thread rendering.
 *
 * @param {Function} fetcher
 */
export function prefetchStreets(fetcher) {
	if (typeof window === 'undefined' || typeof fetcher !== 'function') {
		return
	}
	const schedule = window.requestIdleCallback ?? ((cb) => setTimeout(cb, 1000))
	schedule(() => {
		try {
			fetcher()
		} catch {
			// Non-critical background prefetch
		}
	})
}

