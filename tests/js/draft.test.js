import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { blankDraft, draftFrom, payload } from '../../src/draft.js'
import { sectorSubcategoryId } from '../../src/taxonomy.js'

// fileURLToPath, not .pathname: the repo lives under a directory with a space
// in its name, and .pathname hands back the %20 still escaped. (pins.test.js)
const ROOT = fileURLToPath(new URL('../../', import.meta.url))

/**
 * The mapping's quality rules, read out of the PHP enum they must agree with
 * — the twins doctrine: a renamed case over there breaks this before the first
 * record saves a wrong value.
 */
function phpCases() {
	const php = readFileSync(`${ROOT}lib/Domain/LocationQuality.php`, 'utf8')
	const cases = [...php.matchAll(/case (\w+) = '([^']+)'/g)].map(([, name, value]) => ({ name, value }))
	expect(cases.length, 'LocationQuality cases could not be read').toBeGreaterThan(0)

	return cases
}

/**
 * A taxonomy whose one Category is the divisions, with the Sector and Unidad
 * Vecinal subcategories under it — the minimum `isSectorSubcategory` needs.
 */
const DIVISIONS = [{
	id: 1,
	slug: 'division_territorial',
	label: 'División territorial',
	subcategories: [
		{ id: 10, slug: 'sector', label: 'Sector' },
		{ id: 11, slug: 'unidad_vecinal', label: 'Unidad Vecinal' },
	],
}]

/** A subcategory id nothing files under, for the not-a-Sector cases. */
const PLACE = 99

describe('blankDraft', () => {
	it('starts a Place at Exact and with no shape', () => {
		const draft = blankDraft()
		expect(draft.locationQuality).toBe('exacta')
		expect(draft.geometry).toBeNull()
		expect(draft.id).toBeNull()
	})
})

describe('draftFrom', () => {
	it('maps every PHP quality except Absent onto the picker\'s two', () => {
		for (const { name, value } of phpCases()) {
			const feature = { subcategoryId: PLACE, locationQuality: value }
			const draft = draftFrom(feature, [])

			if (name === 'Absent') {
				// `sin_coordenada` is derived and never offered; the draft shows
				// Exacta so a first coordinate posts a quality the server accepts.
				expect(draft.locationQuality).toBe('exacta')
			} else {
				expect(draft.locationQuality).toBe(value)
			}
		}
	})

	it('gives a Sector draft its picker fields from the stored Limit', () => {
		expect(sectorSubcategoryId(DIVISIONS)).toBe(10)

		const stored = {
			subcategoryId: 10,
			limit: [
				{ featureId: 5, name: 'Vicuña Mackenna' },
				{ name: 'el canal' },
			],
		}
		const draft = draftFrom(stored, DIVISIONS)

		// `picked` is derived from the Limit (#15), geometries arrive as the map
		// brings each line into view (#49), and `limit` is the record itself.
		expect(draft.picked).toEqual([5])
		expect(draft.pickedGeometry).toEqual({})
		expect(draft.limit).toEqual(stored.limit)
	})

	it('gives a Place draft none of the picker fields', () => {
		const draft = draftFrom({ subcategoryId: PLACE, limit: [{ name: 'x' }] }, DIVISIONS)

		expect(draft.picked).toBeUndefined()
		expect(draft.pickedGeometry).toBeUndefined()
		expect(draft.limit).toBeUndefined()
	})
})

describe('payload', () => {
	it('sends the limit only while the record is a Sector', () => {
		const draft = { ...blankDraft(), subcategoryId: 10, ...{ limit: [{ name: 'el canal' }] } }

		// The reclassify case payload()'s own comment names: a Sector
		// re-classified as a colegio must not post a Limit onto it.
		expect(payload(draft, DIVISIONS).limit).toEqual([{ name: 'el canal' }])
		expect(payload({ ...draft, subcategoryId: PLACE }, DIVISIONS).limit).toBeUndefined()
	})

	it('builds a Point from the comma-decimal fields, GeoJSON-ordered', () => {
		expect(payload({ ...blankDraft(), lat: '-33,5187', lon: '-70.5553' }, []).geometry)
			.toEqual({ type: 'Point', coordinates: [-70.5553, -33.5187] })
	})

	it('lets a drawn shape win over the coordinate fields', () => {
		const drawn = { type: 'Polygon', coordinates: [[[0, 0], [1, 0], [1, 1], [0, 0]]] }
		const draft = { ...blankDraft(), geometry: drawn, lat: '-33', lon: '-70' }

		expect(payload(draft, []).geometry).toBe(drawn)
	})
})
