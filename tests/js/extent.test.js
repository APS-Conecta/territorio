import { describe, expect, it } from 'vitest'
import { CHILE_BOUNDS, initialView } from '../../src/extent.js'

/**
 * Where the map opens: the Comuna extent, then the Unidad Vecinal bounds, then
 * continental Chile. Every number below is deliberately different from its
 * neighbours (and |south| != |west|) so a south/west or north/east swap changes
 * the answer instead of hiding in a symmetric box.
 */
const EXTENT = [[-33.61, -70.74], [-33.37, -70.43]]
const UNITS = [[-33.55, -70.66], [-33.45, -70.52]]

describe('initialView precedence', () => {
	it('opens on the Comuna extent when there is one', () => {
		expect(initialView({ extent: EXTENT, unitBounds: null }))
			.toEqual({ bounds: EXTENT, source: 'extent' })
	})

	it('prefers the Comuna extent over the Unidad Vecinal bounds', () => {
		expect(initialView({ extent: EXTENT, unitBounds: UNITS }))
			.toEqual({ bounds: EXTENT, source: 'extent' })
	})

	it('falls back to the Unidad Vecinal bounds when there is no extent', () => {
		expect(initialView({ extent: null, unitBounds: UNITS }))
			.toEqual({ bounds: UNITS, source: 'units' })
	})

	it('falls back to Chile when it has neither', () => {
		expect(initialView({ extent: null, unitBounds: null }))
			.toEqual({ bounds: CHILE_BOUNDS, source: 'chile' })
	})

	it('hands back the very values it was given, not a reshuffle of them', () => {
		const { bounds } = initialView({ extent: EXTENT, unitBounds: UNITS })

		expect(bounds[0][0]).toBe(-33.61) // south
		expect(bounds[0][1]).toBe(-70.74) // west
		expect(bounds[1][0]).toBe(-33.37) // north
		expect(bounds[1][1]).toBe(-70.43) // east
	})
})

describe('initialView skips what it cannot trust', () => {
	const INVALID = {
		'NaN': [[NaN, -70.74], [-33.37, -70.43]],
		'Infinity': [[-33.61, -70.74], [Infinity, -70.43]],
		'swapped south and north': [[-33.37, -70.74], [-33.61, -70.43]],
		'swapped west and east': [[-33.61, -70.43], [-33.37, -70.74]],
		'zero-size (south equals north)': [[-33.5, -70.74], [-33.5, -70.43]],
		'zero-size (west equals east)': [[-33.61, -70.5], [-33.37, -70.5]],
		'numbers as strings': [['-33.61', '-70.74'], ['-33.37', '-70.43']],
		'a flat list': [-33.61, -70.74, -33.37, -70.43],
		'one corner only': [[-33.61, -70.74]],
		'a corner with one number': [[-33.61], [-33.37, -70.43]],
		'an object': { south: -33.61, west: -70.74, north: -33.37, east: -70.43 },
		'undefined': undefined,
	}

	it.each(Object.entries(INVALID))('an invalid extent (%s) falls through to the units', (_, extent) => {
		expect(initialView({ extent, unitBounds: UNITS }))
			.toEqual({ bounds: UNITS, source: 'units' })
	})

	it.each(Object.entries(INVALID))('an invalid extent (%s) with no units falls through to Chile', (_, extent) => {
		expect(initialView({ extent, unitBounds: null }))
			.toEqual({ bounds: CHILE_BOUNDS, source: 'chile' })
	})

	it.each(Object.entries(INVALID))('invalid unit bounds (%s) fall through to Chile', (_, unitBounds) => {
		expect(initialView({ extent: null, unitBounds }))
			.toEqual({ bounds: CHILE_BOUNDS, source: 'chile' })
	})

	it('keeps a valid extent when the unit bounds are the invalid ones', () => {
		expect(initialView({ extent: EXTENT, unitBounds: [[NaN, 0], [1, 1]] }))
			.toEqual({ bounds: EXTENT, source: 'extent' })
	})

	it('treats an empty argument object as having neither source', () => {
		expect(initialView({})).toEqual({ bounds: CHILE_BOUNDS, source: 'chile' })
	})
})

describe('CHILE_BOUNDS', () => {
	it('is a usable box: the module accepts it by its own validity rule', () => {
		expect(initialView({ extent: CHILE_BOUNDS, unitBounds: null }).source).toBe('extent')
	})

	it('is frozen, corners included, so a caller cannot change the shared fallback', () => {
		expect(Object.isFrozen(CHILE_BOUNDS)).toBe(true)
		expect(Object.isFrozen(CHILE_BOUNDS[0])).toBe(true)
		expect(Object.isFrozen(CHILE_BOUNDS[1])).toBe(true)
		expect(() => { CHILE_BOUNDS[0][0] = 0 }).toThrow(TypeError)
	})
})
