import { describe, expect, it } from 'vitest'
import { faceAround } from '../../src/sectors.js'

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
