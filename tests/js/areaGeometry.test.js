import { describe, expect, it } from 'vitest'
import { intersectsArea } from '../../src/areaGeometry.js'

const polygon = (ring, holes = []) => ({ type: 'Polygon', coordinates: [ring, ...holes] })
const square = polygon([[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]])
const point = (x, y) => ({ type: 'Point', coordinates: [x, y] })

describe('matching registered assets to geographic areas', () => {
	it('includes a point in every overlapping area and includes the boundary', () => {
		const second = polygon([[2, 2], [6, 2], [6, 6], [2, 6], [2, 2]])
		expect([square, second].filter(area => intersectsArea(point(3, 3), area))).toHaveLength(2)
		expect(intersectsArea(point(0, 2), square)).toBe(true)
		expect(intersectsArea(point(5, 5), square)).toBe(false)
	})

	it('does not invent a location for an asset without geometry', () => {
		expect(intersectsArea(null, square)).toBe(false)
		expect(intersectsArea(point(1, 1), null)).toBe(false)
	})

	it('includes intersecting zones even when neither polygon has a vertex inside the other', () => {
		const horizontal = polygon([[-2, 1], [6, 1], [6, 2], [-2, 2], [-2, 1]])
		const vertical = polygon([[1, -2], [2, -2], [2, 6], [1, 6], [1, -2]])
		expect(intersectsArea(horizontal, vertical)).toBe(true)
	})

	it('includes both contained and containing zones, but not zones inside a hole', () => {
		const inner = polygon([[1, 1], [2, 1], [2, 2], [1, 2], [1, 1]])
		const hole = [[0.5, 0.5], [3.5, 0.5], [3.5, 3.5], [0.5, 3.5], [0.5, 0.5]]
		expect(intersectsArea(inner, square)).toBe(true)
		expect(intersectsArea(square, inner)).toBe(true)
		expect(intersectsArea(inner, polygon(square.coordinates[0], [hole]))).toBe(false)
		expect(intersectsArea(point(1, 1), polygon(square.coordinates[0], [hole]))).toBe(false)
	})

	it('matches any part of a multipolygon and rejects disjoint polygons', () => {
		const far = polygon([[10, 10], [12, 10], [12, 12], [10, 12], [10, 10]])
		const multi = { type: 'MultiPolygon', coordinates: [square.coordinates, far.coordinates] }
		expect(intersectsArea(point(11, 11), multi)).toBe(true)
		expect(intersectsArea(far, square)).toBe(false)
	})
})
