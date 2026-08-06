import { describe, expect, it } from 'vitest'
import {
	categoryState,
	countsFor,
	emptyFilter,
	filterFeatures,
	isFiltered,
	isSubcategoryVisible,
	listFor,
	toggleCategory,
	toggleSubcategory,
} from '../../src/filters.js'

/**
 * The Capas panel is the Category tree itself — there is no Layer object
 * (CONTEXT.md). These tests pin the two rules that make it feel right:
 * unchecking a Category takes its whole branch with it, and unchecking one
 * Subcategory leaves its siblings alone.
 *
 * The filter is stored as the set of HIDDEN subcategories, never the visible
 * ones. That is what makes a Subcategory added to the taxonomy appear on its own
 * with no code change — the criterion this slice is really about.
 */
const TAXONOMY = [
	{
		id: 1,
		slug: 'salud',
		label: 'Salud',
		color: '#dc2626',
		subcategories: [
			{ id: 11, slug: 'cesfam', label: 'CESFAM' },
			{ id: 12, slug: 'farmacia', label: 'Farmacia' },
		],
	},
	{
		id: 2,
		slug: 'educacion',
		label: 'Educación',
		color: '#2563eb',
		subcategories: [
			{ id: 21, slug: 'colegio', label: 'Colegio' },
		],
	},
]

const feature = (id, subcategoryId, name = `f${id}`) => ({ id, subcategoryId, name })

const FEATURES = [
	feature(1, 11, 'CESFAM Uno'),
	feature(2, 12, 'Farmacia Uno'),
	feature(3, 12, 'Farmacia Dos'),
	feature(4, 21, 'Colegio Uno'),
]

describe('the default filter', () => {
	it('shows everything', () => {
		const filter = emptyFilter()

		expect(filterFeatures(FEATURES, filter)).toHaveLength(4)
		expect(isSubcategoryVisible(filter, 11)).toBe(true)
	})

	/**
	 * The reason the filter stores hidden ids rather than visible ones: a
	 * Subcategory nobody has heard of yet must show up by itself, because adding
	 * one is a data change and must not need a code change.
	 */
	it('shows a subcategory it has never heard of', () => {
		const filter = toggleSubcategory(emptyFilter(), 11)

		expect(isSubcategoryVisible(filter, 999)).toBe(true)
	})
})

describe('toggling a subcategory', () => {
	it('hides only that subcategory', () => {
		const filter = toggleSubcategory(emptyFilter(), 12)

		expect(filterFeatures(FEATURES, filter).map((f) => f.id)).toEqual([1, 4])
		expect(isSubcategoryVisible(filter, 11)).toBe(true)
	})

	it('is its own undo', () => {
		const once = toggleSubcategory(emptyFilter(), 12)
		const twice = toggleSubcategory(once, 12)

		expect(filterFeatures(FEATURES, twice)).toHaveLength(4)
	})

	it('does not mutate the filter it was given', () => {
		const before = emptyFilter()
		toggleSubcategory(before, 12)

		expect(isSubcategoryVisible(before, 12)).toBe(true)
	})
})

describe('toggling a category', () => {
	it('takes its whole branch with it', () => {
		const filter = toggleCategory(emptyFilter(), TAXONOMY[0])

		expect(filterFeatures(FEATURES, filter).map((f) => f.id)).toEqual([4])
	})

	it('brings the whole branch back', () => {
		const hidden = toggleCategory(emptyFilter(), TAXONOMY[0])
		const shown = toggleCategory(hidden, TAXONOMY[0])

		expect(filterFeatures(FEATURES, shown)).toHaveLength(4)
	})

	/**
	 * Half-hidden is the interesting case. Clicking the parent should be a way to
	 * get the whole branch back, not a way to lose the half that was still there.
	 */
	it('shows everything when only part of the branch was hidden', () => {
		const partial = toggleSubcategory(emptyFilter(), 11)
		const filter = toggleCategory(partial, TAXONOMY[0])

		expect(isSubcategoryVisible(filter, 11)).toBe(true)
		expect(isSubcategoryVisible(filter, 12)).toBe(true)
	})

	it('leaves other categories alone', () => {
		const filter = toggleCategory(emptyFilter(), TAXONOMY[0])

		expect(isSubcategoryVisible(filter, 21)).toBe(true)
	})
})

describe('the state a category checkbox shows', () => {
	it('is all when nothing in it is hidden', () => {
		expect(categoryState(emptyFilter(), TAXONOMY[0])).toBe('all')
	})

	it('is none when the whole branch is hidden', () => {
		expect(categoryState(toggleCategory(emptyFilter(), TAXONOMY[0]), TAXONOMY[0])).toBe('none')
	})

	it('is some when part of the branch is hidden', () => {
		expect(categoryState(toggleSubcategory(emptyFilter(), 11), TAXONOMY[0])).toBe('some')
	})

	/**
	 * A Category with nothing under it is hiding nothing, so its box is checked.
	 * Reporting 'none' would draw an unchecked box next to a Category that hides
	 * nothing — and an empty Category arrives exactly through the "add a row,
	 * change no code" path this panel exists to support.
	 */
	it('is all for a category with no subcategories at all', () => {
		expect(categoryState(emptyFilter(), { id: 9, subcategories: [] })).toBe('all')
	})
})

describe('counts for Análisis', () => {
	it('groups by category and subcategory', () => {
		const counts = countsFor(FEATURES, TAXONOMY)

		expect(counts.total).toBe(4)
		expect(counts.categories[0]).toMatchObject({ label: 'Salud', total: 3, color: '#dc2626' })
		expect(counts.categories[0].subcategories).toEqual([
			{ id: 11, label: 'CESFAM', total: 1 },
			{ id: 12, label: 'Farmacia', total: 2 },
		])
	})

	/**
	 * Counts are computed over whatever set they are handed, so the panel, the
	 * list and the map cannot disagree about how many there are — there is one
	 * filtered set, not three filters.
	 */
	it('counts only what it is given', () => {
		const filter = toggleSubcategory(emptyFilter(), 12)
		const counts = countsFor(filterFeatures(FEATURES, filter), TAXONOMY)

		expect(counts.total).toBe(2)
		expect(counts.categories[0].total).toBe(1)
	})

	it('keeps a category with nothing in it, so the legend stays stable', () => {
		const counts = countsFor([feature(9, 11)], TAXONOMY)

		expect(counts.categories.map((c) => c.label)).toEqual(['Salud', 'Educación'])
		expect(counts.categories[1].total).toBe(0)
	})

	/**
	 * A Feature pointing at a Subcategory the taxonomy no longer has must not
	 * vanish from the totals — that would make the count quietly disagree with
	 * the list, which is the exact failure this slice exists to prevent.
	 */
	it('still counts a feature whose subcategory is unknown', () => {
		const counts = countsFor([...FEATURES, feature(5, 404)], TAXONOMY)

		expect(counts.total).toBe(5)
		expect(counts.unclassified).toBe(1)
	})
})

describe('what the Datos list shows', () => {
	const retired = [feature(7, 21, 'Colegio retirado')]

	it('filters the live list by the Capas filter', () => {
		const list = listFor({
			features: FEATURES, retiredFeatures: retired, showRetired: false,
			filter: toggleSubcategory(emptyFilter(), 12),
		})

		expect(list.map((f) => f.id)).toEqual([1, 4])
	})

	/**
	 * The regression this exists to prevent: the retired list is the recovery
	 * screen (ADR-0005). Filtering it by Capas means hiding Educación and then
	 * retiring a colegio tells the user "No hay registros retirados" — the record
	 * they are looking for appears destroyed.
	 */
	it('never filters the retired list, so a retired record is always findable', () => {
		const list = listFor({
			features: FEATURES, retiredFeatures: retired, showRetired: true,
			filter: toggleSubcategory(emptyFilter(), 21),
		})

		expect(list.map((f) => f.name)).toEqual(['Colegio retirado'])
	})
})

describe('isFiltered', () => {
	it('is false until something is hidden', () => {
		expect(isFiltered(emptyFilter())).toBe(false)
		expect(isFiltered(toggleSubcategory(emptyFilter(), 11))).toBe(true)
	})

	it('is false again once everything is shown', () => {
		const filter = toggleSubcategory(toggleSubcategory(emptyFilter(), 11), 11)

		expect(isFiltered(filter)).toBe(false)
	})
})
