import { describe, expect, it } from 'vitest'
import {
	categoryState,
	countsFor,
	emptyFilter,
	featureById,
	filterFeatures,
	isFiltered,
	isSubcategoryVisible,
	listFor,
	matches,
	toggleCategory,
	toggleSubcategory,
} from '../../src/filters.js'
import { indexSubcategories } from '../../src/taxonomy.js'

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

const SUBCATEGORIES = indexSubcategories(TAXONOMY)

const FEATURES = [
	feature(1, 11, 'CESFAM Uno'),
	feature(2, 12, 'Farmacia Uno'),
	feature(3, 12, 'Farmacia Dos'),
	feature(4, 21, 'Colegio Uno'),
]

describe('searching', () => {
	/**
	 * The criterion this whole slice is named for. Nobody types "Peñaflor" with
	 * the tilde when they are looking for something in a hurry, and a search
	 * that only matches the accented form is a search that quietly finds nothing.
	 */
	it('ignores accents and case', () => {
		const penaflor = feature(9, 11, 'Escuela Peñaflor de Prueba')

		expect(matches(penaflor, 'penaflor', SUBCATEGORIES)).toBe(true)
		expect(matches(penaflor, 'PEÑAFLOR', SUBCATEGORIES)).toBe(true)
		expect(matches(penaflor, 'liceo', SUBCATEGORIES)).toBe(false)
	})

	/** What a person actually reaches for: where it is, who to ask, what it is. */
	it('looks at the address, the contact person and the classification', () => {
		const farmacia = {
			...feature(9, 12, 'Sin nombre útil'),
			address: 'Avenida Vicuña Mackenna 1234',
			contactPerson: 'Alejandra Pérez',
		}

		expect(matches(farmacia, 'mackenna', SUBCATEGORIES)).toBe(true)
		expect(matches(farmacia, 'perez', SUBCATEGORIES)).toBe(true)
		// The index holds "Salud · Farmacia", so one comparison covers both levels.
		expect(matches(farmacia, 'farmacia', SUBCATEGORIES)).toBe(true)
		expect(matches(farmacia, 'salud', SUBCATEGORIES)).toBe(true)
	})

	/** A record missing every optional field must not throw, it must just not match. */
	it('survives a record with nothing but a name', () => {
		expect(matches(feature(9, 11, 'Solo nombre'), 'nada', SUBCATEGORIES)).toBe(false)
	})

	/**
	 * A Feature whose Subcategory the taxonomy no longer has is still searchable
	 * by everything else — the same reasoning as countsFor keeping it in the
	 * totals rather than dropping it.
	 */
	it('still searches a record whose subcategory is unknown', () => {
		expect(matches(feature(9, 404, 'Huérfano'), 'huerfano', SUBCATEGORIES)).toBe(true)
	})

	it('matches everything when the box is empty or only spaces', () => {
		const anything = feature(9, 11, 'Cualquiera')

		expect(matches(anything, '', SUBCATEGORIES)).toBe(true)
		expect(matches(anything, '   ', SUBCATEGORIES)).toBe(true)
	})
})

describe('search and the Capas toggles together', () => {
	/**
	 * The criterion the issue is really about: search is a predicate on the one
	 * filtered set, not a second mechanism beside it. Built as its own path, the
	 * list and the map end up disagreeing about what is visible.
	 */
	it('compose — neither clears the other', () => {
		// Colegio hidden by Capas leaves 1, 2, 3; "uno" then takes 3 as well. Both
		// narrowings have to be visible in the answer or the test proves nothing.
		const filter = { ...toggleSubcategory(emptyFilter(), 21), query: 'uno' }

		expect(filterFeatures(FEATURES, filter, SUBCATEGORIES).map((f) => f.id)).toEqual([1, 2])
	})

	/**
	 * The other order, which is the one a person actually does: type, then reach
	 * for a checkbox. The togglers returned a brand-new `{ hidden }` and dropped
	 * the query on the floor, so the search silently vanished — and the test above
	 * could never catch it, because building the filter toggle-first is the one
	 * arrangement where nothing is lost.
	 */
	it('survives a toggle made after typing', () => {
		const searched = { ...emptyFilter(), query: 'uno' }

		const afterSubcategory = toggleSubcategory(searched, 21)
		expect(afterSubcategory.query).toBe('uno')
		expect(filterFeatures(FEATURES, afterSubcategory, SUBCATEGORIES).map((f) => f.id))
			.toEqual([1, 2])

		const afterCategory = toggleCategory(searched, TAXONOMY[0])
		expect(afterCategory.query).toBe('uno')
		expect(filterFeatures(FEATURES, afterCategory, SUBCATEGORIES).map((f) => f.id))
			.toEqual([4])
	})

	it('clearing the box leaves the Capas selection standing', () => {
		const searched = { ...toggleSubcategory(emptyFilter(), 12), query: 'CESFAM' }
		expect(filterFeatures(FEATURES, searched, SUBCATEGORIES).map((f) => f.id)).toEqual([1])

		// Only the query goes; Farmacia is still hidden, so 2 and 3 stay away.
		const cleared = { ...searched, query: '' }
		expect(filterFeatures(FEATURES, cleared, SUBCATEGORIES).map((f) => f.id)).toEqual([1, 4])
	})

	/**
	 * Records with no coordinate are Datos-only and must stay searchable — the
	 * catastro this app inherits holds organisations whose address never resolved.
	 */
	it('finds a record that has no coordinate', () => {
		const sinCoordenada = { ...feature(9, 11, 'Junta sin coordenada'), geometry: null }

		expect(filterFeatures([sinCoordenada], { ...emptyFilter(), query: 'junta' }, SUBCATEGORIES))
			.toHaveLength(1)
	})

	it('starts empty, so a fresh View shows everything', () => {
		expect(emptyFilter().query).toBe('')
		expect(filterFeatures(FEATURES, emptyFilter(), SUBCATEGORIES)).toHaveLength(4)
	})

	/** "Ver todo" and the Análisis notice both key off this. */
	it('a query counts as filtering, so the way back stays offered', () => {
		expect(isFiltered({ ...emptyFilter(), query: 'uno' })).toBe(true)
		expect(isFiltered(emptyFilter())).toBe(false)
	})

	/**
	 * The retired list is the recovery screen and Capas deliberately does not
	 * touch it (ADR-0005) — but a search is not a background filter, it is someone
	 * saying what they are looking for. Refusing to narrow the list they are
	 * staring at would be the app ignoring an explicit instruction.
	 */
	it('narrows the retired list too, which the Capas toggles do not', () => {
		const retired = [feature(7, 21, 'Colegio retirado'), feature(8, 11, 'CESFAM retirado')]

		const list = listFor({
			features: FEATURES,
			retiredFeatures: retired,
			showRetired: true,
			// Colegio hidden, which must NOT apply here — only the query does.
			filter: { ...toggleSubcategory(emptyFilter(), 21), query: 'colegio' },
			subcategories: SUBCATEGORIES,
		})

		expect(list.map((f) => f.id)).toEqual([7])
	})
})

describe('the default filter', () => {
	it('shows everything', () => {
		const filter = emptyFilter()

		expect(filterFeatures(FEATURES, filter, SUBCATEGORIES)).toHaveLength(4)
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

		expect(filterFeatures(FEATURES, filter, SUBCATEGORIES).map((f) => f.id)).toEqual([1, 4])
		expect(isSubcategoryVisible(filter, 11)).toBe(true)
	})

	it('is its own undo', () => {
		const once = toggleSubcategory(emptyFilter(), 12)
		const twice = toggleSubcategory(once, 12)

		expect(filterFeatures(FEATURES, twice, SUBCATEGORIES)).toHaveLength(4)
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

		expect(filterFeatures(FEATURES, filter, SUBCATEGORIES).map((f) => f.id)).toEqual([4])
	})

	it('brings the whole branch back', () => {
		const hidden = toggleCategory(emptyFilter(), TAXONOMY[0])
		const shown = toggleCategory(hidden, TAXONOMY[0])

		expect(filterFeatures(FEATURES, shown, SUBCATEGORIES)).toHaveLength(4)
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
		const counts = countsFor(filterFeatures(FEATURES, filter, SUBCATEGORIES), TAXONOMY)

		expect(counts.total).toBe(2)
		expect(counts.categories[0].total).toBe(1)
	})

	/**
	 * The slug, not just the label: the chart colours a division with the
	 * neutral rather than its inert `#334155` (#29), and slug is the stable
	 * identifier — a clinic may rename a label and ids differ per install.
	 */
	it('reports each Category by slug, so the chart can tell a division apart', () => {
		expect(countsFor(FEATURES, TAXONOMY).categories.map((category) => category.slug))
			.toEqual(TAXONOMY.map((category) => category.slug))
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
			subcategories: SUBCATEGORIES,
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
			subcategories: SUBCATEGORIES,
		})

		expect(list.map((f) => f.name)).toEqual(['Colegio retirado'])
	})
})

/**
 * Selecting is resolving an id against the data, and the sets on screen are not
 * one list (issue #67). The map draws the live Registry whatever the retired
 * toggle says; the Datos list draws `listFor()`, which with the toggle on holds
 * only retired records. Resolving a map click against the list's answer found
 * nothing, so the pin highlighted and the sidebar never opened.
 */
describe('featureById', () => {
	const retired = [feature(7, 21, 'Colegio retirado')]

	it('finds a live record while the retired list is the one on show', () => {
		expect(featureById(FEATURES, retired, 1)?.id).toBe(1)
	})

	it('finds a retired record', () => {
		expect(featureById(FEATURES, retired, 7)?.name).toBe('Colegio retirado')
	})

	/**
	 * The pairing itself, which is the bug: `listFor()` is asked what the list
	 * shows and answers with the retired records alone, while the map is still
	 * drawing the live ones. Resolving against its answer is what killed the click.
	 */
	it('finds what listFor deliberately leaves out', () => {
		const shown = listFor({
			features: FEATURES, retiredFeatures: retired, showRetired: true,
			filter: emptyFilter(), subcategories: SUBCATEGORIES,
		})

		expect(shown.map((f) => f.id)).not.toContain(1)
		expect(featureById(FEATURES, retired, 1)?.id).toBe(1)
	})

	it('is undefined for an id neither list holds', () => {
		expect(featureById(FEATURES, retired, 999)).toBeUndefined()
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
