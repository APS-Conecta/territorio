/**
 * Turning picked lines into a Sector polygon (issue #14).
 *
 * ADR-0007 says a Sector's limit is built from picked Boundary features, and the
 * awkward part is that a comuna does not hold blocks — it holds street
 * fragments, in no order, and picking by name picks every fragment of that name
 * including the ones that overlap. Ordering them, closing the ring and dropping
 * the repeats are three problems that all disappear if the question is asked
 * differently: cut the picked lines into every enclosed face they make, and keep
 * the one the person clicked inside.
 *
 * That is what polygonize does, and it is why this is a few lines rather than a
 * geometry library of our own.
 *
 * **The result is a starting point, never the final answer.** Real limits run
 * along things no dataset contains — a canal, the edge of a park, an invisible
 * line two teams agreed on years ago. So this hands back an ordinary polygon
 * that goes straight into the normal editor with every vertex draggable, and it
 * returns null rather than inventing a shape when the lines do not close.
 */

import booleanPointInPolygon from '@turf/boolean-point-in-polygon'
import polygonize from '@turf/polygonize'

/** GeoJSON types that carry line work; anything else a Dataset holds is not one. */
const LINES = ['LineString', 'MultiLineString']

/**
 * The smallest enclosed face the seed falls in, or null if there is none.
 *
 * @param {Array} lines geometries of the picked Boundary features
 * @param {Array} seed [lon, lat] the person clicked inside the Sector
 * @return {?object} a GeoJSON Polygon, or null
 */
export function faceAround(lines, seed) {
	const segments = lines.flatMap(stretchesOf)
	if (segments.length === 0) {
		return null
	}

	let faces
	try {
		faces = polygonize({ type: 'FeatureCollection', features: segments })
	} catch {
		// polygonize throws on input it cannot make sense of rather than
		// returning nothing. From here that is the same answer: these lines do
		// not enclose anything, which is a thing the person is told and draws
		// around by hand.
		return null
	}

	// Smallest first, so a Sector inside a larger ring of picked streets is the
	// Sector rather than everything around it. Two streets crossing a third make
	// faces at several scales and the tightest one containing the click is the
	// only one the person can have meant.
	const containing = faces.features
		.filter((face) => booleanPointInPolygon(seed, face))
		.sort((one, other) => spanOf(one) - spanOf(other))

	return containing.length === 0 ? null : containing[0].geometry
}

/**
 * One picked record as the separate stretches it holds.
 *
 * A MultiLineString is one Boundary feature covering several stretches of the
 * same street; polygonize wants each as its own LineString. Duplicates are left
 * in on purpose — a corner picked twice is two identical segments, and
 * polygonize already treats an edge as an edge however many times it is given.
 */
function stretchesOf(geometry) {
	if (!LINES.includes(geometry?.type)) {
		return []
	}

	const parts = geometry.type === 'LineString'
		? [geometry.coordinates]
		: geometry.coordinates

	return parts
		// Two points is the least that can be an edge. A one-point "line" comes
		// out of a bad import and would take polygonize down with it.
		.filter((coordinates) => Array.isArray(coordinates) && coordinates.length >= 2)
		.map((coordinates) => ({
			type: 'Feature',
			properties: {},
			geometry: { type: 'LineString', coordinates },
		}))
}

/**
 * How big a face is, for choosing between nested ones.
 *
 * The shoelace area in degrees, unconverted: this only ever compares two faces
 * of the same map against each other, so the units cancel and turning it into
 * square metres would be arithmetic nobody reads. Absolute, because the sign of
 * the shoelace formula reports winding order rather than size.
 */
function spanOf(face) {
	const ring = face.geometry.coordinates[0] ?? []
	let twiceArea = 0

	for (let index = 0; index < ring.length - 1; index++) {
		const [x1, y1] = ring[index]
		const [x2, y2] = ring[index + 1]
		twiceArea += x1 * y2 - x2 * y1
	}

	return Math.abs(twiceArea) / 2
}
