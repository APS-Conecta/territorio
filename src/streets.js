/**
 * Finding a street on the map — the decisions behind the search box under
 * «¿Dónde estoy?» (SearchControl.vue).
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
import { closerFirst } from './boundaries.js'
import { fold } from './filters.js'

/**
 * The places whose name holds what was typed, the closer match first.
 *
 * The same needle and the same ranking as the Sector editor's `suggestions()`,
 * so the app's two street lists never disagree about what comes first. Unlike
 * it, nothing is grouped: the server already split each name by place, and two
 * rows with one name are two different streets. The sort is stable, so those
 * keep the server's order.
 *
 * Returns [] for an empty query rather than everything: five thousand rows is
 * not a suggestion.
 */
export function placesMatching(places, query, limit = 12) {
	const needle = fold(query).trim()
	if (needle === '') {
		return []
	}

	return places
		.filter((place) => fold(place.name).includes(needle))
		.sort(closerFirst)
		.slice(0, limit)
}

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
 * Which option is active after a key, in a list of `count` — -1 for none.
 *
 * The WAI-ARIA combobox's list movement: focus stays in the box and the active
 * option moves under it. ArrowDown from nothing is the first; ArrowUp from
 * nothing is the last; both stop at the ends rather than wrapping, so a person
 * reading twelve rows cannot lose their place. Every other key leaves it where
 * it is — Home and End belong to the text being typed.
 */
export function nextActive(active, count, key) {
	if (count === 0) {
		return -1
	}

	if (key === 'ArrowDown') {
		return Math.min(active + 1, count - 1)
	}

	if (key === 'ArrowUp') {
		return active < 0 ? count - 1 : Math.max(active - 1, 0)
	}

	return active
}

/**
 * The place Enter picks: the active one, or none.
 *
 * Never the first by default, even when it is the only one. ADR-0007: the
 * person chooses, and "the first match" is exactly the guess the app refuses
 * to make on their behalf.
 */
export const chosen = (options, active) => (active >= 0 ? options[active] ?? null : null)

/**
 * A place's box as Leaflet takes it: [[south, west], [north, east]] — latitude
 * first, the opposite of GeoJSON's [lon, lat]. One place for that order, so it
 * is written once and tested once.
 */
export const cornersOf = (place) => [[place.south, place.west], [place.north, place.east]]

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
