import { describe, expect, it } from 'vitest'
import {
	colourOf,
	defaultSubcategoryId,
	divisionsOf,
	boundsOf,
	indexSubcategories,
	isDivision,
	sectorSubcategoryId,
	treeOf,
	UNKNOWN_COLOUR,
} from '../../src/taxonomy.js'

/**
 * CONTEXT.md: the panel a person toggles is the Category tree itself, PLUS one
 * toggle per territorial division. Sectores are Features like any other and so
 * must carry a Subcategory — but they are not one branch of the tree among
 * others, and these tests are what keeps them out of it.
 */
const TAXONOMY = [
	{
		id: 1,
		slug: 'salud',
		label: 'Salud',
		color: '#dc2626',
		subcategories: [{ id: 11, slug: 'cesfam', label: 'CESFAM' }],
	},
	{
		id: 2,
		slug: 'division_territorial',
		label: 'División territorial',
		color: '#334155',
		subcategories: [{ id: 21, slug: 'sector', label: 'Sector' }],
	},
	{
		id: 3,
		slug: 'sin_clasificar',
		label: 'Sin clasificar',
		color: '#6b7280',
		subcategories: [{ id: 31, slug: 'otro', label: 'Otro' }],
	},
]

describe('territorial divisions', () => {
	it('are kept out of the Category tree', () => {
		expect(treeOf(TAXONOMY).map((category) => category.slug))
			.toEqual(['salud', 'sin_clasificar'])
	})

	it('are offered as toggles of their own', () => {
		expect(divisionsOf(TAXONOMY)).toEqual([
			{ id: 21, slug: 'sector', label: 'Sector', colour: '#334155' },
		])
	})

	it('recognise the division Category by slug', () => {
		expect(isDivision(TAXONOMY[1])).toBe(true)
		expect(isDivision(TAXONOMY[0])).toBe(false)
	})

	it('name the Subcategory a new Sector is filed under', () => {
		expect(sectorSubcategoryId(TAXONOMY)).toBe(21)
	})

	/**
	 * An install whose seed has not run yet would otherwise file a Sector under
	 * whatever happened to be first — a colegio, in label order.
	 */
	it('report no Sector Subcategory rather than guessing one', () => {
		expect(sectorSubcategoryId([TAXONOMY[0]])).toBe(null)
	})

	/** The tree is still the whole taxonomy on an install that has no divisions. */
	it('leave the tree alone when there are none', () => {
		expect(treeOf([TAXONOMY[0]])).toEqual([TAXONOMY[0]])
		expect(divisionsOf([TAXONOMY[0]])).toEqual([])
	})
})

describe('what colour a record is drawn in', () => {
	const subcategories = indexSubcategories(TAXONOMY)

	it('is the record\'s own when it has one — that is how sectores differ', () => {
		expect(colourOf({ subcategoryId: 21, colour: '#16a34a' }, subcategories)).toBe('#16a34a')
	})

	it('falls back to the Category colour, so nothing has to be coloured by hand', () => {
		expect(colourOf({ subcategoryId: 11, colour: null }, subcategories)).toBe('#dc2626')
	})

	it('falls back again when the Subcategory is one the taxonomy no longer has', () => {
		expect(colourOf({ subcategoryId: 999, colour: null }, subcategories)).toBe(UNKNOWN_COLOUR)
	})
})

describe('the extent of a division', () => {
	const subcategories = indexSubcategories(TAXONOMY)
	const uv = (id, coordinates) => ({
		id, subcategoryId: 21, geometry: { type: 'Polygon', coordinates },
	})

	/** Opening on the comuna the install serves, measured from its own boundaries. */
	it('spans every ring of every matching Feature', () => {
		const features = [
			uv(1, [[[-70.60, -33.53], [-70.58, -33.53], [-70.58, -33.51], [-70.60, -33.53]]]),
			uv(2, [[[-70.62, -33.55], [-70.59, -33.55], [-70.59, -33.52], [-70.62, -33.55]]]),
		]

		expect(boundsOf(features, subcategories, 'sector'))
			.toEqual([[-33.55, -70.62], [-33.51, -70.58]])
	})

	it('ignores Features of any other Subcategory', () => {
		const features = [
			uv(1, [[[-70.60, -33.53], [-70.58, -33.53], [-70.58, -33.51], [-70.60, -33.53]]]),
			{ id: 2, subcategoryId: 11, geometry: { type: 'Point', coordinates: [-71.9, -30.1] } },
		]

		expect(boundsOf(features, subcategories, 'sector'))
			.toEqual([[-33.53, -70.60], [-33.51, -70.58]])
	})

	/** Nothing to measure is null, so the caller can keep its own opening view. */
	it('is null when nothing matches, and when a match has no geometry', () => {
		expect(boundsOf([], subcategories, 'sector')).toBe(null)
		expect(boundsOf([{ id: 1, subcategoryId: 21, geometry: null }], subcategories, 'sector'))
			.toBe(null)
	})
})

describe('the default Subcategory', () => {
	/**
	 * Categories arrive ordered by label, so "División territorial" now sits
	 * ahead of "Sin clasificar" — a new record must still start unclassified and
	 * never as a Sector.
	 */
	it('is still Sin clasificar now that a division Category exists', () => {
		expect(defaultSubcategoryId(TAXONOMY)).toBe(31)
	})
})
