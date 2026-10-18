import { describe, expect, it } from 'vitest'
import { viewTargetOf } from '../../src/assets.js'
import { boundsOfGeometry } from '../../src/taxonomy.js'

/**
 * Where the map goes when someone picks a record. A record carries GeoJSON
 * `geometry` ([lon, lat], longitude first) and never flat `lat`/`lon`; a record
 * without coordinates has `geometry: null` and is valid.
 */
describe('viewTargetOf', () => {
	it('pans to a Place, latitude and longitude the right way round', () => {
		expect(viewTargetOf({ type: 'Point', coordinates: [-70.65, -33.44] }))
			.toEqual({ kind: 'point', lat: -33.44, lon: -70.65 })
	})

	it('treats 0 as a coordinate and not as missing', () => {
		expect(viewTargetOf({ type: 'Point', coordinates: [0, 0] }))
			.toEqual({ kind: 'point', lat: 0, lon: 0 })
	})

	it('fits a Polygon to its edges', () => {
		const ring = [[-70.66, -33.45], [-70.64, -33.45], [-70.64, -33.43], [-70.66, -33.43], [-70.66, -33.45]]
		expect(viewTargetOf({ type: 'Polygon', coordinates: [ring] }))
			.toEqual({ kind: 'bounds', bounds: [[-33.45, -70.66], [-33.43, -70.64]] })
	})

	it('fits a MultiPolygon across all its parts', () => {
		const part = (west, south) => [[[west, south], [west + 0.01, south], [west + 0.01, south + 0.01], [west, south]]]
		const { bounds } = viewTargetOf({ type: 'MultiPolygon', coordinates: [part(-70.7, -33.5), part(-70.6, -33.4)] })
		expect(bounds[0][0]).toBeCloseTo(-33.5)
		expect(bounds[0][1]).toBeCloseTo(-70.7)
		expect(bounds[1][0]).toBeCloseTo(-33.39)
		expect(bounds[1][1]).toBeCloseTo(-70.59)
	})

	it('answers null when there is nowhere to go', () => {
		expect(viewTargetOf(null)).toBeNull()
		expect(viewTargetOf(undefined)).toBeNull()
		expect(viewTargetOf({ type: 'Point', coordinates: null })).toBeNull()
		expect(viewTargetOf({ type: 'GeometryCollection', geometries: [] })).toBeNull()
	})
})

describe('boundsOfGeometry', () => {
	it('measures a Point as a zero-size box', () => {
		expect(boundsOfGeometry({ type: 'Point', coordinates: [-70.65, -33.44] }))
			.toEqual([[-33.44, -70.65], [-33.44, -70.65]])
	})

	it('is null for no geometry', () => {
		expect(boundsOfGeometry(null)).toBeNull()
	})
})
