/**
 * Where the map opens (ADR-0021, CONTEXT.md "Comuna extent"): the Comuna
 * extent first, then the bounds of the Unidades Vecinales the install imported,
 * then the bounds of its records, then continental Chile. Pure on purpose: no
 * Leaflet, no DOM, so the choice is testable without a map.
 *
 * The records rung exists because the map's minimum zoom (8) makes a
 * country-wide fit impossible: "Chile" is only the middle of the country, so an
 * install that has data but neither a chosen Comuna nor Unidades Vecinales would
 * open with its own records off screen. An install with data opens where its
 * data is.
 */

/**
 * Continental Chile, `[[south, west], [north, east]]` as Leaflet wants it.
 *
 * What an install with no known Comuna and no imported Unidades Vecinales opens
 * on: the middle of the country at the widest zoom the map allows. fitBounds
 * runs before the basemap exists; when the OSM tile layer (minZoom 8) is added,
 * Leaflet's Map._updateZoomLevels raises the zoom to that minimum (only while
 * the map sets no minZoom of its own), so any Chile-sized box ends on its
 * centre (about -36.75, -71.2) at zoom 8; the numbers only fix the centre. A
 * country fact, not a clinic fact. It is frozen because callers
 * receive it by reference and one mutation would change every later opening.
 */
export const CHILE_BOUNDS = Object.freeze([
	Object.freeze([-56, -76]),
	Object.freeze([-17.5, -66.4]),
])

/**
 * A corner pair is usable only when both corners are finite numbers and the box
 * has area. Anything else is skipped by the caller rather than repaired: a
 * guessed extent opens the map on the wrong place and nobody notices, while a
 * skipped one just falls to the next source.
 */
function isUsable(bounds) {
	if (!Array.isArray(bounds) || bounds.length !== 2) {
		return false
	}

	const [sw, ne] = bounds
	if (!Array.isArray(sw) || !Array.isArray(ne) || sw.length !== 2 || ne.length !== 2) {
		return false
	}

	const [south, west] = sw
	const [north, east] = ne

	// Number.isFinite is false for strings, so '-33.5' is refused as well.
	return [south, west, north, east].every(Number.isFinite) && south < north && west < east
}

/**
 * `extent` is the Comuna extent the server derived from the CUT, or null.
 * `unitBounds` is what `boundsOf` answers and `recordBounds` what
 * `boundsOfRecords` answers: `[[s, w], [n, e]]` or null. A lone Point makes a
 * zero-size `recordBounds`, which is skipped like any box without area.
 * The source is returned so a caller (and a test) can tell which one won.
 *
 * Callers always pass an object; `= {}` only covers an omitted argument, so
 * `initialView(null)` throws by design rather than being quietly tolerated.
 */
export function initialView({ extent, unitBounds, recordBounds } = {}) {
	if (isUsable(extent)) {
		return { bounds: extent, source: 'extent' }
	}

	if (isUsable(unitBounds)) {
		return { bounds: unitBounds, source: 'units' }
	}

	if (isUsable(recordBounds)) {
		return { bounds: recordBounds, source: 'records' }
	}

	return { bounds: CHILE_BOUNDS, source: 'chile' }
}
