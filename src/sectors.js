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

import { booleanPointInPolygon } from '@turf/boolean-point-in-polygon'
import { polygonize } from '@turf/polygonize'
import { safeError } from './api.js'

/** GeoJSON types that carry line work; anything else a Dataset holds is not one. */
const LINES = ['LineString', 'MultiLineString']

/**
 * Whether the picked lines can be polygonized at all: every one of them has its
 * shape held on the draft, and together they hold some line work.
 *
 * A separate question from whether generating succeeds, and asked first, because
 * the two failures need different words. `draftFrom()` rebuilds `picked` from the
 * stored Limit — that is what #15 put the names there for — but it has no shapes
 * to go with them, so a reopened Sector reached `faceAround` with an empty list,
 * got null back, and was told its streets do not close. About streets that do
 * (issue #49).
 *
 * Every, not some. A subset of the picked lines still closes — into a LARGER face
 * than the one meant, with nothing on screen to say so, which is the silent
 * failure `keepPickedGeometry` was written against. That is also what makes it
 * safe to rehydrate the cache a few lines at a time as the map loads them: a
 * half-filled cache reads as not ready, never as a smaller Sector.
 *
 * A held shape must be a GeoJSON type in `LINES`, or a Point. A Point or a line of
 * one repeated position adds no edge, so `faceAround` gives the same face with or
 * without it, and readiness agrees with it. A Polygon has edges that `stretchesOf`
 * drops, so it still blocks: ignoring it could give a larger face. The pick as a
 * whole needs at least one stretch.
 */
export function canPolygonize(picked, pickedGeometry) {
	const held = picked.map((id) => pickedGeometry?.[id])

	return held.every((geometry) => LINES.includes(geometry?.type) || geometry?.type === 'Point')
		&& held.some((geometry) => stretchesOf(geometry).length > 0)
}

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
	} catch (exception) {
		// NOT the "these lines do not close" path — that one comes back as an
		// empty collection and is handled below. Reaching here means polygonize
		// refused input this built, which is a bug in this file rather than
		// anything the person did. Said out loud, then treated as no face: the
		// editor still works and hand-drawing is still there (ADR-0007).
		safeError('could not polygonize the picked lines', exception)

		return null
	}

	// Smallest first, so a Sector inside a larger ring of picked streets is the
	// Sector rather than everything around it. Two streets crossing a third make
	// faces at several scales and the tightest one containing the click is the
	// only one the person can have meant.
	const containing = faces.features
		.filter((face) => booleanPointInPolygon(seed, face))
		.sort((one, other) => areaOf(one) - areaOf(other))

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
		// Two DISTINCT points is the least that can be an edge. Counting positions
		// was not enough: a KML <LineString> with a repeated coordinate imports as
		// [[x,y],[x,y]], which has two of them and no length. polygonize refused
		// the whole collection over it — "Each LinearRing of a Polygon must have 4
		// or more Positions" — and the editor reported that as "las calles elegidas
		// no cierran alrededor de ese punto", which was untrue. Picking by name
		// picks every fragment of a street, so one bad row poisoned the whole pick.
		.filter((coordinates) => Array.isArray(coordinates)
			&& coordinates.length >= 2
			&& coordinates.some(([lon, lat]) => lon !== coordinates[0][0] || lat !== coordinates[0][1]))
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
function areaOf(face) {
	const ring = face.geometry.coordinates[0] ?? []
	let twiceArea = 0

	for (let index = 0; index < ring.length - 1; index++) {
		const [x1, y1] = ring[index]
		const [x2, y2] = ring[index + 1]
		twiceArea += x1 * y2 - x2 * y1
	}

	return Math.abs(twiceArea) / 2
}
