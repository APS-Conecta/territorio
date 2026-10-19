import { describe, expect, it } from 'vitest'
import {
	categoryColour,
	colourOf,
	defaultSubcategoryId,
	divisionsOf,
	boundsOf,
	boundsOfRecords,
	DEFAULT_SECTOR_COLOUR,
	indexSubcategories,
	isDivision,
	isSectorSubcategory,
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
		// A seeded Category nobody has re-pointed: the mark comes from the
		// slug-keyed map in glyphs.js, not from the row (#46).
		glyph: null,
		subcategories: [{ id: 11, slug: 'cesfam', label: 'CESFAM' }],
	},
	{
		id: 2,
		slug: 'division_territorial',
		label: 'División territorial',
		color: '#334155',
		subcategories: [
			{ id: 21, slug: 'sector', label: 'Sector' },
			{ id: 22, slug: 'unidad_vecinal', label: 'Unidad Vecinal' },
		],
	},
	{
		id: 3,
		slug: 'sin_clasificar',
		label: 'Sin clasificar',
		color: '#6b7280',
		subcategories: [{ id: 31, slug: 'otro', label: 'Otro' }],
	},
	// A Category the clinic added, with the mark somebody picked for it (#46).
	// Last, so the positional reads above it keep meaning what they say.
	{
		id: 4,
		slug: 'comite_de_vivienda',
		label: 'Comité de vivienda',
		color: '#c27b72',
		glyph: 'town',
		subcategories: [{ id: 41, slug: 'otro', label: 'Otro' }],
	},
]

describe('territorial divisions', () => {
	it('are kept out of the Category tree', () => {
		expect(treeOf(TAXONOMY).map((category) => category.slug))
			.toEqual(['salud', 'sin_clasificar', 'comite_de_vivienda'])
	})

	/**
	 * Without a colour. The division Category's own `#334155` is inert (#29) —
	 * nothing draws it — and carrying it here was a copy waiting to be painted.
	 */
	it('are offered as toggles of their own', () => {
		expect(divisionsOf(TAXONOMY)).toEqual([
			{ id: 21, slug: 'sector', label: 'Sector' },
			{ id: 22, slug: 'unidad_vecinal', label: 'Unidad Vecinal' },
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

	/**
	 * «A Sector, and not merely any division» is asked in two places — the
	 * colour input (#24) and the Limit picker — and used to be written out in
	 * both. One fact, one owner, and the rule is under test here rather than
	 * in whichever component happened to be opened.
	 */
	it('tell a Sector from any other division', () => {
		expect(isSectorSubcategory(TAXONOMY, 21)).toBe(true)
		expect(isSectorSubcategory(TAXONOMY, 22)).toBe(false)
	})

	/**
	 * A record filed under no Subcategory at all — a brand-new draft — must not
	 * come back true from an install where the seed left no Sector either, which
	 * is what a bare `=== sectorSubcategoryId(...)` did: null === null.
	 */
	it('call a record with no Subcategory a Sector nowhere', () => {
		expect(isSectorSubcategory(TAXONOMY, null)).toBe(false)
		expect(isSectorSubcategory([TAXONOMY[0]], null)).toBe(false)
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

	/**
	 * Issue #29: the seeded `#334155` measures 1.73:1 against the dark theme,
	 * and ADR-0013 measures the neutral band as having no room for a fourth
	 * grey — so the division stops being a coloured mark instead of being given
	 * a better colour. It stays in the seed and reaches no surface.
	 */
	it('never hands back the division Category colour', () => {
		expect(colourOf({ subcategoryId: 21, colour: null }, subcategories))
			.toBe(DEFAULT_SECTOR_COLOUR)
		expect(colourOf({ subcategoryId: 22, colour: null }, subcategories))
			.toBe(DEFAULT_SECTOR_COLOUR)
	})

	/** A Sector a clinic did tint keeps its tint — #24 destroys nothing. */
	it('still honours a division\'s own colour where there is one', () => {
		expect(colourOf({ subcategoryId: 21, colour: '#16a34a' }, subcategories)).toBe('#16a34a')
		expect(colourOf({ subcategoryId: 22, colour: '#16a34a' }, subcategories)).toBe('#16a34a')
	})
})

describe('what colour stands for a whole Category', () => {
	it('is the Category\'s own', () => {
		expect(categoryColour(TAXONOMY[0])).toBe('#dc2626')
	})

	/** The Análisis chart is the last surface #334155 could have reached. */
	it('is the neutral for a territorial division, never its inert seed colour', () => {
		expect(categoryColour(TAXONOMY[1])).toBe(DEFAULT_SECTOR_COLOUR)
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

describe('the extent of the records themselves', () => {
	const record = (id, type, coordinates) => ({ id, subcategoryId: 11, geometry: { type, coordinates } })

	it('is null when there are no records, and when none has a geometry', () => {
		expect(boundsOfRecords([])).toBe(null)
		expect(boundsOfRecords([
			{ id: 1, subcategoryId: 11, geometry: null },
			{ id: 2, subcategoryId: 11 },
			{ id: 3, subcategoryId: 11, geometry: { type: 'Point', coordinates: null } },
		])).toBe(null)
	})

	/** Asymmetric numbers, so a lat/lon swap changes the answer. */
	it('is stretched by points, lines and polygons alike, whatever their Subcategory', () => {
		const features = [
			record(1, 'Point', [-70.50, -33.40]),
			record(2, 'LineString', [[-70.70, -33.60], [-70.65, -33.58]]),
			{ id: 3, subcategoryId: 21, geometry: { type: 'Polygon', coordinates: [[[-70.55, -33.45], [-70.45, -33.45], [-70.45, -33.30], [-70.55, -33.45]]] } },
		]

		expect(boundsOfRecords(features)).toEqual([[-33.60, -70.70], [-33.30, -70.45]])
	})

	it('skips a record without a geometry but still measures the others', () => {
		const features = [
			{ id: 1, subcategoryId: 11, geometry: null },
			record(2, 'Point', [-70.50, -33.40]),
			record(3, 'Point', [-70.20, -33.10]),
		]

		expect(boundsOfRecords(features)).toEqual([[-33.40, -70.50], [-33.10, -70.20]])
	})

	/**
	 * A lone Point is a zero-size box. It is returned as it is; initialView
	 * refuses a box with no area and falls through, rather than fitting a
	 * point at the highest zoom.
	 */
	it('is a zero-size box for a single Point', () => {
		expect(boundsOfRecords([record(1, 'Point', [-70.50, -33.40])]))
			.toEqual([[-33.40, -70.50], [-33.40, -70.50]])
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

/**
 * Issue #46: the mark a clinic chose has to travel with the colour.
 *
 * The map, the Datos list and the Capas legend all draw a record's pictogram
 * from this one index — the map and the list have nothing but a Subcategory id
 * to go on — so a stored choice that is not carried here reaches no surface at
 * all, however correctly it was written.
 */
describe('the pictogram a Category carries', () => {
	const subcategories = indexSubcategories(TAXONOMY)

	it('travels beside the colour, for the Category the record is filed under', () => {
		expect(subcategories.get(41).categoryGlyph).toBe('town')
	})

	it('is null for a Category nobody has chosen for, which is every seeded one', () => {
		expect(subcategories.get(11).categoryGlyph).toBe(null)
	})

	/**
	 * A tree served before this column existed — a browser holding initial state
	 * from the page it loaded before the upgrade — has no `glyph` key at all.
	 * `undefined` would reach `glyphFor()` as a chosen mark rather than as none.
	 */
	it('is null and not undefined when the served tree predates the column', () => {
		const old = [{ id: 9, slug: 'salud', label: 'Salud', color: '#dc2626',
			subcategories: [{ id: 91, slug: 'otro', label: 'Otro' }] }]

		expect(indexSubcategories(old).get(91).categoryGlyph).toBe(null)
	})
})
