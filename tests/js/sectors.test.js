import { describe, expect, it } from 'vitest'
import { canPolygonize, faceAround } from '../../src/sectors.js'

/**
 * Turning picked lines into a Sector polygon (issue #14).
 *
 * The criterion this pins is the one the ticket says disposes of three problems
 * at once: "segment ordering, ring closure and duplicate names require no user
 * intervention". A comuna's streets arrive as dozens of unordered fragments,
 * picked by name so the same stretch comes twice, and none of that should reach
 * the person drawing a Sector.
 *
 * What it deliberately does NOT pin is whether the polygon is any GOOD. That
 * needs somebody looking at a real comuna, which is why #14 is marked
 * ready-for-human and why ADR-0007 keeps hand-drawing available throughout.
 */

/** A unit square as four separate segments, the way a cadastre holds a block. */
const SQUARE = [
	{ type: 'LineString', coordinates: [[0, 0], [4, 0]] },
	{ type: 'LineString', coordinates: [[4, 0], [4, 4]] },
	{ type: 'LineString', coordinates: [[4, 4], [0, 4]] },
	{ type: 'LineString', coordinates: [[0, 4], [0, 0]] },
]

/**
 * The distinct corners of the returned ring, sorted.
 *
 * Distinct and sorted because neither is part of the answer: a ring repeats its
 * first point to close, and which corner it starts at is polygonize's business.
 * Comparing those would be a test of the library's traversal order rather than
 * of the shape, and it would fail on an upgrade that changed nothing.
 */
const cornersOf = (polygon) => [...new Set(polygon.coordinates[0]
	.map(([x, y]) => `${Math.round(x)},${Math.round(y)}`))].sort()

describe('faceAround', () => {
	it('ignores a line whose two points are the same place', () => {
		// A KML <LineString> with a repeated coordinate imports as this. It has two
		// positions, so the old length check let it through, and polygonize then
		// refused the whole collection — "Each LinearRing of a Polygon must have 4
		// or more Positions". The editor caught that and told the person "las
		// calles elegidas no cierran alrededor de ese punto", which was untrue:
		// the streets closed perfectly. And because picking by name picks every
		// fragment of a street, one bad row poisoned the whole pick.
		const degenerate = { type: 'LineString', coordinates: [[9, 9], [9, 9]] }
		const polygon = faceAround([...SQUARE, degenerate], [2, 2])

		expect(polygon).not.toBeNull()
		expect(cornersOf(polygon)).toEqual(['0,0', '0,4', '4,0', '4,4'])
	})

	it('closes a ring from segments that arrive in no particular order', () => {
		const shuffled = [SQUARE[2], SQUARE[0], SQUARE[3], SQUARE[1]]
		const polygon = faceAround(shuffled, [2, 2])

		expect(polygon.type).toBe('Polygon')
		// The four corners, whichever segment happened to come first.
		expect(cornersOf(polygon)).toEqual(['0,0', '0,4', '4,0', '4,4'])
	})

	it('gives the same answer however the segments are ordered', () => {
		const one = faceAround(SQUARE, [2, 2])
		const other = faceAround([...SQUARE].reverse(), [2, 2])

		expect(cornersOf(one)).toEqual(cornersOf(other))
	})

	/**
	 * Picking by name picks every fragment of that name, so a corner shared by
	 * two streets arrives twice. It must not become two faces or none.
	 */
	it('ignores a line that was picked twice', () => {
		const polygon = faceAround([...SQUARE, SQUARE[1], SQUARE[3]], [2, 2])

		expect(polygon.type).toBe('Polygon')
		expect(cornersOf(polygon)).toEqual(['0,0', '0,4', '4,0', '4,4'])
	})

	/** A MultiLineString is one picked record holding several stretches. */
	it('takes a MultiLineString apart into its stretches', () => {
		const polygon = faceAround([
			{ type: 'MultiLineString', coordinates: [SQUARE[0].coordinates, SQUARE[1].coordinates] },
			SQUARE[2],
			SQUARE[3],
		], [2, 2])

		expect(polygon.type).toBe('Polygon')
	})

	/**
	 * The seed is what decides WHICH face, and it is the only thing that can:
	 * four streets crossing make several enclosed blocks and only the person
	 * knows which one is the Sector.
	 */
	it('keeps the face the seed point falls in, not another one', () => {
		const twoBlocks = [
			...SQUARE,
			// A second block east of the first, sharing the x=4 edge.
			{ type: 'LineString', coordinates: [[4, 0], [8, 0]] },
			{ type: 'LineString', coordinates: [[8, 0], [8, 4]] },
			{ type: 'LineString', coordinates: [[8, 4], [4, 4]] },
		]

		expect(cornersOf(faceAround(twoBlocks, [2, 2]))).toEqual(['0,0', '0,4', '4,0', '4,4'])
		expect(cornersOf(faceAround(twoBlocks, [6, 2]))).toEqual(['4,0', '4,4', '8,0', '8,4'])
	})

	/** Nested faces: the tightest one the seed is in, not the outer boundary. */
	it('prefers the smallest face containing the seed', () => {
		const nested = [
			...SQUARE,
			{ type: 'LineString', coordinates: [[1, 1], [3, 1]] },
			{ type: 'LineString', coordinates: [[3, 1], [3, 3]] },
			{ type: 'LineString', coordinates: [[3, 3], [1, 3]] },
			{ type: 'LineString', coordinates: [[1, 3], [1, 1]] },
		]

		expect(cornersOf(faceAround(nested, [2, 2]))).toEqual(['1,1', '1,3', '3,1', '3,3'])
	})

	/**
	 * The criterion that matters most when it goes wrong: "when no closed face
	 * contains the seed point, the user is told and hand-drawing still works".
	 * Null is how this says so — it never invents a shape.
	 */
	it('returns null when the lines do not close around the seed', () => {
		const open = SQUARE.slice(0, 3)

		expect(faceAround(open, [2, 2])).toBeNull()
	})

	it('returns null when the seed is outside every face', () => {
		expect(faceAround(SQUARE, [9, 9])).toBeNull()
	})

	it('returns null when nothing has been picked', () => {
		expect(faceAround([], [2, 2])).toBeNull()
	})

	/**
	 * The same shape where this app actually runs.
	 *
	 * Every case above is integers between 0 and 8, which is where a geometry
	 * library is least likely to be wrong. La Florida is around -33.5, -70.6:
	 * six decimal places, both negative, and the numbers that matter differing in
	 * the fourth. Node deduplication and the winding of a ring behave differently
	 * there, and it is the only place the answer counts.
	 */
	it('works at La Florida coordinates, not only at the origin', () => {
		const block = [
			{ type: 'LineString', coordinates: [[-70.5900, -33.5300], [-70.5850, -33.5300]] },
			{ type: 'LineString', coordinates: [[-70.5850, -33.5300], [-70.5850, -33.5250]] },
			{ type: 'LineString', coordinates: [[-70.5850, -33.5250], [-70.5900, -33.5250]] },
			{ type: 'LineString', coordinates: [[-70.5900, -33.5250], [-70.5900, -33.5300]] },
		]

		const polygon = faceAround(block, [-70.5875, -33.5275])

		expect(polygon).not.toBeNull()
		expect([...new Set(polygon.coordinates[0].map(([x, y]) => `${x.toFixed(4)},${y.toFixed(4)}`))].sort())
			.toEqual([
				'-70.5850,-33.5250', '-70.5850,-33.5300',
				'-70.5900,-33.5250', '-70.5900,-33.5300',
			])

		// And a click outside it is still outside it at this scale — 200 m away,
		// which a tolerance that made sense at the origin would swallow.
		expect(faceAround(block, [-70.5820, -33.5275])).toBeNull()
	})

	/**
	 * A Boundary Dataset can hold a Point — a KML import puts one there — and it
	 * is not a line. Skipped rather than crashing the editor.
	 */
	it('ignores geometries that are not lines', () => {
		const polygon = faceAround([
			...SQUARE,
			{ type: 'Point', coordinates: [2, 2] },
			null,
		], [2, 2])

		expect(polygon.type).toBe('Polygon')
	})
})

/**
 * Whether generating is possible at all, which is a different question from
 * whether it succeeds (issue #49).
 *
 * `draftFrom()` rebuilds `picked` from the stored Limit — that is the point of
 * #15, the Limit is the record of which streets were chosen — but it has no
 * shapes to go with them, and `save()` used to drop the ones it did have. Asked
 * to generate anyway, `faceAround` got an empty list, returned null, and the app
 * said the streets do not close. About streets that do.
 */
describe('whether the picked lines can be polygonized at all', () => {
	const shapes = { 7: SQUARE[0], 8: SQUARE[1] }
	const block = { 1: SQUARE[0], 2: SQUARE[1], 3: SQUARE[2], 4: SQUARE[3] }

	it('can, when every picked line has its shape', () => {
		expect(canPolygonize([7, 8], shapes)).toBe(true)
	})

	/**
	 * Picking a name picks every row of it, and a Boundary Dataset can hold a
	 * Point or a LineString of one repeated position under a street's name — how
	 * a KML import lands. Such a fragment adds no edge, so `faceAround` gives the
	 * same face with or without it, and readiness agrees.
	 */
	it('can, when a picked fragment adds no edge', () => {
		const fragment = { type: 'LineString', coordinates: [[2, 0], [2, 0]] }
		expect(canPolygonize([1, 2, 3, 4, 5], { ...block, 5: fragment })).toBe(true)
		expect(faceAround([...SQUARE, fragment], [2, 2])).toEqual(faceAround(SQUARE, [2, 2]))

		const point = { type: 'Point', coordinates: [2, 0] }
		expect(canPolygonize([1, 2, 3, 4, 5], { ...block, 5: point })).toBe(true)
		expect(faceAround([...SQUARE, point], [2, 2])).toEqual(faceAround(SQUARE, [2, 2]))
	})

	it('cannot, when nothing has been picked', () => {
		expect(canPolygonize([], shapes)).toBe(false)
	})

	/**
	 * A reopened Sector: `picked` comes from the Limit, `pickedGeometry` is empty.
	 * This is the whole of #49.
	 */
	it('cannot, from a Limit reopened with no shapes behind it', () => {
		expect(canPolygonize([7, 8], {})).toBe(false)
		expect(canPolygonize([7, 8], undefined)).toBe(false)
	})

	/**
	 * And not on a PARTIAL set, which is the reason this is not simply "some".
	 * A subset of the picked lines still closes — into a larger face than the one
	 * meant, with nothing on screen to say so. Silent and wrong beats loud and
	 * wrong every time, so this refuses instead.
	 */
	it('cannot, when only some of the picked lines have a shape', () => {
		expect(canPolygonize([7, 8, 9], shapes)).toBe(false)
		expect(canPolygonize([1, 2, 3, 4, 5], block)).toBe(false)
	})

	/**
	 * A Boundary row may carry a null geometry, and `keepPickedGeometry` stores
	 * what it finds. A cache of nulls passed a check for `undefined`, so this read
	 * as ready and then produced no face at all — the very message the guard
	 * exists to stop, reached by another road.
	 */
	it('cannot, when a picked line has no shape of its own', () => {
		expect(canPolygonize([7], { 7: null })).toBe(false)
		expect(canPolygonize([7, 8], { ...shapes, 8: null })).toBe(false)
		expect(canPolygonize([1, 2, 3, 4, 5], { ...block, 5: null })).toBe(false)
	})

	/**
	 * Held is not enough on its own. A Polygon's edges are dropped by
	 * `stretchesOf`, so ignoring it could give a larger face than the one meant;
	 * a shape with no type is not something this can read at all.
	 */
	it('cannot, when a picked shape has edges it cannot use', () => {
		expect(canPolygonize([1, 2, 3, 4, 5], {
			...block,
			5: { type: 'Polygon', coordinates: [[[0, 0], [1, 0], [1, 1], [0, 0]]] },
		})).toBe(false)
		expect(canPolygonize([1, 2, 3, 4, 5], { ...block, 5: { coordinates: [[0, 0], [1, 1]] } })).toBe(false)
	})

	/**
	 * A pick made only of edgeless or unrecognised rows holds no line work, so
	 * there is nothing to polygonize.
	 */
	it('cannot, when no picked line is line work it can use', () => {
		expect(canPolygonize([7], { 7: { type: 'Point', coordinates: [2, 2] } })).toBe(false)
		expect(canPolygonize([7], { 7: { coordinates: [[0, 0], [1, 1]] } })).toBe(false)
		expect(canPolygonize([7], {
			7: { type: 'LineString', coordinates: [[2, 2], [2, 2]] },
		})).toBe(false)
		expect(canPolygonize([5, 6], {
			5: { type: 'LineString', coordinates: [[2, 2], [2, 2]] },
			6: { type: 'Point', coordinates: [2, 2] },
		})).toBe(false)
	})
})
